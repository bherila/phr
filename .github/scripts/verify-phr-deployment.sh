#!/usr/bin/env bash

set -euo pipefail

for name in DEPLOY_SSH_TARGET DEPLOY_PHP_BINARY DEPLOY_DIR DEPLOY_CANDIDATE_DIR \
    DEPLOY_SITE_URL DEPLOY_RELEASE_ID DEPLOY_SOURCE_COMMIT DEPLOY_LIVE_RELEASE \
    DEPLOY_LIVE_COMMIT DEPLOY_LIVE_STATE
do
    if [[ -z "${!name:-}" ]]; then
        echo "Required deployment verification value is missing: ${name}" >&2
        exit 2
    fi
done

if [[ ! "$DEPLOY_SSH_TARGET" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*(@[A-Za-z0-9][A-Za-z0-9.-]*)?$ ]]; then
    echo 'DEPLOY_SSH_TARGET is unsafe.' >&2
    exit 2
fi
case "$DEPLOY_DIR:$DEPLOY_RELEASE_ID" in *[!A-Za-z0-9._:-]*) echo 'Deployment directory or release id is unsafe.' >&2; exit 2 ;; esac
case "$DEPLOY_CANDIDATE_DIR" in
    "$DEPLOY_DIR") ;;
    *) echo 'Stable-directory live verification requires the candidate at the stable path.' >&2; exit 2 ;;
esac
case "$DEPLOY_PHP_BINARY" in /*) ;; *) echo 'DEPLOY_PHP_BINARY must be absolute.' >&2; exit 2 ;; esac
case "$DEPLOY_SITE_URL" in https://*) ;; *) echo 'DEPLOY_SITE_URL must use HTTPS.' >&2; exit 2 ;; esac

if [[ "$DEPLOY_LIVE_RELEASE" != "$DEPLOY_RELEASE_ID" \
    || "$DEPLOY_LIVE_COMMIT" != "$DEPLOY_SOURCE_COMMIT" \
    || "$DEPLOY_LIVE_STATE" != serving ]]; then
    echo 'The shared action did not report the exact serving candidate.' >&2
    exit 1
fi

readonly curl_bin="${PHR_VERIFY_CURL_BIN:-curl}"
readonly ssh_bin="${PHR_VERIFY_SSH_BIN:-ssh}"
readonly php_runner="${PHR_VERIFY_PHP_BIN:-php}"
readonly memory_limit='1G'
readonly site_url="${DEPLOY_SITE_URL%/}"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly script_dir
repo_root="$(cd "$script_dir/../.." && pwd)"
readonly repo_root
# The deploy job downloads the `frontend-build` artifact to public/build/ before
# running this verifier (see .github/workflows/ci.yml), so the manifest on disk here
# is exactly the build being deployed. Tests override this to a fixture manifest.
readonly manifest_path="${PHR_VERIFY_MANIFEST:-$repo_root/public/build/manifest.json}"
verify_root="$(mktemp -d)"
readonly verify_root
trap 'rm -rf "$verify_root"' EXIT

request() {
    local name="$1" url="$2"
    shift 2
    RESPONSE_HEADERS="$verify_root/$name.headers"
    RESPONSE_BODY="$verify_root/$name.body"
    RESPONSE_STATUS="$("$curl_bin" --silent --show-error --retry 3 --retry-all-errors \
        --max-time 20 --dump-header "$RESPONSE_HEADERS" --output "$RESPONSE_BODY" \
        --write-out '%{http_code}' "$@" "$url")"
}

header_value() {
    local name="${1,,}"
    awk -v wanted="$name" '
        BEGIN { FS = ":" }
        {
            key = tolower($1)
            if (key == wanted) {
                sub(/^[^:]*:[[:space:]]*/, "")
                sub(/\r$/, "")
                value = $0
            }
        }
        END { print value }
    ' "$RESPONSE_HEADERS"
}

assert_asset_content_type() {
    local label="$1" kind="$2" content_type
    content_type="$(header_value content-type)"
    content_type="${content_type%%;*}"
    content_type="${content_type,,}"
    case "$kind" in
        js)
            [[ "$content_type" == 'text/javascript' || "$content_type" == 'application/javascript' ]] && return 0
            ;;
        css)
            [[ "$content_type" == 'text/css' ]] && return 0
            ;;
    esac
    echo "Deployed asset ${label} did not report the expected content type." >&2
    exit 1
}

assert_private_challenge() {
    local label="$1" cache_control challenge content_options
    if [[ "$RESPONSE_STATUS" != 401 ]]; then
        echo "Unauthenticated ${label} verification returned HTTP ${RESPONSE_STATUS}, expected 401." >&2
        exit 1
    fi
    cache_control="$(header_value cache-control)"
    challenge="$(header_value www-authenticate)"
    content_options="$(header_value x-content-type-options)"
    if [[ ",${cache_control// /}," != *,no-store,* \
        || "$challenge" != "Bearer resource_metadata=\"$site_url/.well-known/oauth-protected-resource/api/v1\"" \
        || "$content_options" != nosniff ]]; then
        echo "Unauthenticated ${label} response did not preserve the canonical private OAuth challenge." >&2
        exit 1
    fi
}

request up "$site_url/up"
[[ "$RESPONSE_STATUS" == 200 ]] || { echo "Production verification failed for /up with HTTP ${RESPONSE_STATUS}." >&2; exit 1; }

request login "$site_url/login"
[[ "$RESPONSE_STATUS" == 200 ]] || { echo "Login page returned HTTP ${RESPONSE_STATUS}, expected 200." >&2; exit 1; }
login_content_type="$(header_value content-type)"
login_content_type="${login_content_type%%;*}"
if [[ "${login_content_type,,}" != text/html ]]; then
    echo 'Login page did not report an HTML content type.' >&2
    exit 1
fi

[[ -f "$manifest_path" ]] || { echo "Frontend build manifest not found at ${manifest_path}." >&2; exit 1; }
manifest_assets="$verify_root/manifest-assets.tsv"
# shellcheck disable=SC2016 # The PHP program is intentionally a literal string.
PHR_VERIFY_MANIFEST="$manifest_path" PHR_VERIFY_OUT="$manifest_assets" "$php_runner" -r '
    $data = json_decode((string) file_get_contents(getenv("PHR_VERIFY_MANIFEST")), true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        fwrite(STDERR, "Frontend build manifest is not a JSON object.\n");
        exit(1);
    }
    $files = [];
    foreach ($data as $entry) {
        if (!is_array($entry) || ($entry["isEntry"] ?? false) !== true) {
            continue;
        }
        $file = $entry["file"] ?? null;
        if (!is_string($file) || $file === "") {
            fwrite(STDERR, "Frontend build manifest entry is missing its file.\n");
            exit(1);
        }
        $files[$file] = true;
        foreach ((array) ($entry["css"] ?? []) as $css) {
            if (!is_string($css) || $css === "") {
                fwrite(STDERR, "Frontend build manifest entry has an invalid CSS reference.\n");
                exit(1);
            }
            $files[$css] = true;
        }
    }
    if ($files === []) {
        fwrite(STDERR, "Frontend build manifest has no entry points to verify.\n");
        exit(1);
    }
    $out = fopen(getenv("PHR_VERIFY_OUT"), "w");
    foreach (array_keys($files) as $file) {
        if (str_ends_with($file, ".js")) {
            $kind = "js";
        } elseif (str_ends_with($file, ".css")) {
            $kind = "css";
        } else {
            fwrite(STDERR, "Frontend build manifest entry has an unexpected asset type.\n");
            exit(1);
        }
        fwrite($out, $file."\t".$kind."\n");
    }
' || exit 1

# Bounded on purpose: this only checks the manifest's entry points (isEntry: true) and
# the CSS each entry pulls in, never the full chunk graph (vendor/ui-core/imaging
# splits, etc.). That is a handful of hashed files, not the dozens a full-site crawl
# would touch, and it runs against production during a deploy. The cap below is a
# sanity backstop against that set growing unboundedly if the Vite config changes.
readonly max_manifest_assets=12
manifest_asset_count="$(wc -l <"$manifest_assets" | tr -d ' ')"
if (( manifest_asset_count > max_manifest_assets )); then
    echo "Frontend build manifest has ${manifest_asset_count} entry-point assets, exceeding the bounded check limit of ${max_manifest_assets}." >&2
    exit 1
fi

while IFS=$'\t' read -r asset_file asset_kind; do
    [[ -n "$asset_file" ]] || continue
    asset_label="build/${asset_file}"
    request "asset-${asset_file//\//_}" "$site_url/build/$asset_file"
    [[ "$RESPONSE_STATUS" == 200 ]] || { echo "Deployed asset ${asset_label} returned HTTP ${RESPONSE_STATUS}, expected 200." >&2; exit 1; }
    assert_asset_content_type "$asset_label" "$asset_kind"
done <"$manifest_assets"

request ohif "$site_url/ohif/viewer/dicomjson" --proto '=https' --max-redirs 0
[[ "$RESPONSE_STATUS" == 302 ]] || { echo 'OHIF viewer did not preserve its authentication boundary.' >&2; exit 1; }
viewer_location=$(header_value location)
[[ "$viewer_location" == /login || "$viewer_location" == "$site_url/login" ]] || {
    echo 'OHIF viewer did not preserve its exact login redirect.' >&2; exit 1;
}

request metadata "$site_url/.well-known/oauth-protected-resource/api/v1"
[[ "$RESPONSE_STATUS" == 200 ]] || { echo "Protected-resource metadata returned HTTP ${RESPONSE_STATUS}." >&2; exit 1; }
# shellcheck disable=SC2016 # The PHP program is intentionally a literal string.
PHR_VERIFY_JSON="$RESPONSE_BODY" PHR_VERIFY_SITE="$site_url" "$php_runner" -r '
    $data = json_decode((string) file_get_contents(getenv("PHR_VERIFY_JSON")), true, 16, JSON_THROW_ON_ERROR);
    $site = rtrim((string) getenv("PHR_VERIFY_SITE"), "/");
    $scopes = $data["scopes_supported"] ?? [];
    if (($data["resource"] ?? null) !== $site."/api/v1"
        || ($data["authorization_servers"][0] ?? null) !== $site
        || ! is_array($scopes)
        || array_diff(["mcp:use", "genai:read", "genai:work"], $scopes) !== []) {
        fwrite(STDERR, "Protected-resource metadata does not match the deployed Agent API.\n");
        exit(1);
    }
' || exit 1

request capabilities "$site_url/api/v1/capabilities"
[[ "$RESPONSE_STATUS" == 200 ]] || { echo "Agent capabilities returned HTTP ${RESPONSE_STATUS}." >&2; exit 1; }
# shellcheck disable=SC2016 # The PHP program is intentionally a literal string.
PHR_VERIFY_JSON="$RESPONSE_BODY" PHR_VERIFY_SITE="$site_url" "$php_runner" -r '
    $data = json_decode((string) file_get_contents(getenv("PHR_VERIFY_JSON")), true, 32, JSON_THROW_ON_ERROR);
    $site = rtrim((string) getenv("PHR_VERIFY_SITE"), "/");
    $required = ["mcp.exchange", "genai.queue_status", "genai.requests.claim", "genai.requests.complete", "genai.requests.fail"];
    if (($data["api_version"] ?? null) !== "v1"
        || ($data["limits"]["maximum_page_size"] ?? null) !== 100
        || ($data["oauth"]["authorization_code_pkce"] ?? null) !== true
        || ($data["oauth"]["protected_resource_metadata"] ?? null) !== $site."/.well-known/oauth-protected-resource/api/v1") {
        fwrite(STDERR, "Agent capabilities have an unexpected core shape.\n");
        exit(1);
    }
    foreach ($required as $operation) {
        if (($data["operations"][$operation]["available"] ?? null) !== true) {
            fwrite(STDERR, "Agent capabilities omit a required MCP/GenAI operation.\n");
            exit(1);
        }
    }
' || exit 1

request queue "$site_url/api/v1/genai/queue/status"
assert_private_challenge 'GenAI queue'

readonly initialize_payload='{"jsonrpc":"2.0","id":"deploy-check","method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"deploy-check","version":"1"}}}'
request mcp "$site_url/api/v1/mcp" \
    --request POST --header 'Content-Type: application/json' \
    --header 'Mcp-Protocol-Version: 2025-06-18' --data "$initialize_payload"
assert_private_challenge 'MCP'

request hostile-origin "$site_url/api/v1/mcp" \
    --request OPTIONS --header 'Origin: https://hostile.invalid' \
    --header 'Access-Control-Request-Method: POST' \
    --header 'Access-Control-Request-Headers: authorization, content-type, mcp-protocol-version'
if [[ "$RESPONSE_STATUS" != 204 \
    || -n "$(header_value access-control-allow-origin)" \
    || ",$(header_value cache-control | tr -d ' ')," != *,no-store,* \
    || "$(header_value x-content-type-options)" != nosniff ]]; then
    echo 'Hostile-origin MCP preflight did not fail closed without CORS permission.' >&2
    exit 1
fi

"$ssh_bin" "$DEPLOY_SSH_TARGET" \
    "bash -s -- $(printf '%q ' "$DEPLOY_CANDIDATE_DIR" "$DEPLOY_PHP_BINARY" "$DEPLOY_DIR")" \
    <"$script_dir/verify-phr-candidate.sh"

timeout 60 "$ssh_bin" "$DEPLOY_SSH_TARGET" \
    "bash -s -- $(printf '%q ' "$DEPLOY_DIR" "$DEPLOY_PHP_BINARY" "$DEPLOY_RELEASE_ID" "$DEPLOY_SOURCE_COMMIT" "$DEPLOY_RELEASE_ID")" \
    <"$script_dir/verify-phr-ohif-artifact.sh"

crontab_file="$verify_root/crontab"
"$ssh_bin" "$DEPLOY_SSH_TARGET" 'crontab -l' >"$crontab_file"

readonly scheduler_line="*/5 * * * * cd \"\$HOME/$DEPLOY_DIR\" && PHR_CRON_MEMORY_LIMIT=1G $DEPLOY_PHP_BINARY -d memory_limit=$memory_limit artisan phr:uptime:run-scheduler >> /dev/null 2>&1 # JOB:phr-laravel-scheduler"
readonly worker_line="*/5 * * * * cd \"\$HOME/$DEPLOY_DIR\" && PHR_CRON_MEMORY_LIMIT=1G /usr/bin/flock -n \"\$HOME/$DEPLOY_DIR/storage/framework/phr-queue-worker.lock\" $DEPLOY_PHP_BINARY -d memory_limit=$memory_limit artisan phr:uptime:run-worker >> /dev/null 2>&1 # JOB:phr-laravel-queue-worker"

for spec in "phr-laravel-scheduler|$scheduler_line" "phr-laravel-queue-worker|$worker_line"; do
    IFS='|' read -r job_name expected_line <<<"$spec"
    if [[ "$(grep -Ec "# JOB:${job_name}[[:space:]]*$" "$crontab_file")" != 1 \
        || "$(grep -Fxc "$expected_line" "$crontab_file" || true)" != 1 ]]; then
        echo "Production cron is missing the one canonical ${job_name} entry." >&2
        exit 1
    fi
done

echo 'Production PHR HTTP, login, assets, OAuth, MCP, OHIF, queue, key, memory, and cron checks passed.'
