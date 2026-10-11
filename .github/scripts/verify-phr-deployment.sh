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
cleanup_verification() {
    local result=$?
    trap - EXIT
    if [[ "$result" != 0 && -n "${RESPONSE_NAME:-}" ]]; then
        http_failure_diagnostics || true
    fi
    rm -rf "$verify_root"
    exit "$result"
}
trap cleanup_verification EXIT

# shellcheck source=./verify-frontend-manifest.sh
source "$script_dir/verify-frontend-manifest.sh"

# Manifest validation is local-only (no network, no SSH) and runs first, before
# this script makes any HTTP or SSH call. A broken build artifact is a
# verifier-input problem, not evidence that production is broken, and this
# ordering means the post-deploy verifier also fails closed with zero remote
# calls when it is -- it is not relying solely on the separate CI preflight
# step (.github/scripts/verify-frontend-manifest.sh run standalone right
# after actions/download-artifact, before any remote mutation) to catch it.
readonly max_manifest_assets=12
manifest_assets="$verify_root/manifest-assets.tsv"
readonly manifest_assets
verify_frontend_manifest "$manifest_path" "$(dirname "$manifest_path")" "$manifest_assets" "$max_manifest_assets"

# Repair static OHIF and prove its identity while this application transaction
# owns the mutex, before probing the public entrypoint and committing healthy.
if [[ -n "${OHIF_RUN_ID:-}" ]]; then
    OHIF_LOCK_OWNER="$DEPLOY_RELEASE_ID" bash "$script_dir/publish-ohif-dist.sh"
fi

request() {
    local name="$1" url="$2"
    shift 2
    RESPONSE_NAME="$name"
    RESPONSE_HEADERS="$verify_root/$name.headers"
    RESPONSE_BODY="$verify_root/$name.body"
    RESPONSE_CURL_EXIT=0
    # Curl diagnostics can contain URLs. Keep them private; report only its exit
    # code and the allowlisted response metadata on a failed verification.
    if RESPONSE_STATUS="$("$curl_bin" --silent --show-error --retry 3 --retry-all-errors \
        --max-time 20 --dump-header "$RESPONSE_HEADERS" --output "$RESPONSE_BODY" \
        --write-out '%{http_code}' "$@" "$url" 2>"$verify_root/$name.curl-error")"; then
        return 0
    else
        RESPONSE_CURL_EXIT=$?
        exit "$RESPONSE_CURL_EXIT"
    fi
}

# Select only the final response header block (curl may retry). Never print raw
# headers, bodies, Location, cookies, or authentication challenges. Even these
# allowlisted headers are mapped to fixed values before appearing in CI logs.
diagnostic_header_value() {
    [[ -f "$RESPONSE_HEADERS" ]] || return 0
    LC_ALL=C awk -v wanted="$1" '
        /^HTTP\/[0-9.]+[[:space:]]/ { value = "" }
        {
            colon = index($0, ":")
            if (colon && tolower(substr($0, 1, colon - 1)) == wanted) {
                value = substr($0, colon + 1)
                sub(/^[[:space:]]*/, "", value)
                sub(/\r$/, "", value)
            }
        }
        END { if (length(value) <= 128) print value }
    ' "$RESPONSE_HEADERS"
}

http_failure_diagnostics() {
    local check="$RESPONSE_NAME" status="${RESPONSE_STATUS:-000}"
    local mime mitigated cache
    [[ "$check" =~ ^[A-Za-z0-9._/-]{1,128}$ ]] || check=asset
    [[ "$status" =~ ^[0-9]{3}$ ]] || status=invalid
    mime="$(diagnostic_header_value content-type)"
    mime="${mime%%;*}"
    case "${mime,,}" in
        text/html|application/json|text/plain|text/css|text/javascript|application/javascript|application/octet-stream) mime="${mime,,}" ;;
        '') mime=unavailable ;;
        *) mime=other ;;
    esac
    mitigated="$(diagnostic_header_value cf-mitigated)"
    case "${mitigated,,}" in challenge) mitigated=challenge ;; '') mitigated=none ;; *) mitigated=other ;; esac
    cache="$(diagnostic_header_value cf-cache-status)"
    case "${cache^^}" in
        HIT|MISS|DYNAMIC|BYPASS|EXPIRED|STALE|UPDATING|REVALIDATED) cache="${cache^^}" ;;
        '') cache=unavailable ;;
        *) cache=other ;;
    esac
    printf 'PHR HTTP verification failed: check=%s status=%s curl_exit=%s content_type=%s cf_mitigated=%s cf_cache_status=%s\n' \
        "$check" "$status" "$RESPONSE_CURL_EXIT" "$mime" "$mitigated" "$cache" >&2
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
    local label="$1" metadata_path="$2" cache_control challenge content_options metadata=''
    if [[ "$RESPONSE_STATUS" != 401 ]]; then
        echo "Unauthenticated ${label} verification returned HTTP ${RESPONSE_STATUS}, expected 401." >&2
        exit 1
    fi
    cache_control="$(header_value cache-control)"
    challenge="$(header_value www-authenticate)"
    content_options="$(header_value x-content-type-options)"
    # RFC 9728 5.1: the challenge may carry RFC 6750 error parameters; what must
    # hold is that it is a Bearer challenge naming exactly one metadata document,
    # and that document is the one for the resource that was called.
    if [[ "$challenge" =~ ^Bearer[[:space:]] \
        && "$challenge" =~ (^Bearer[[:space:]]|,[[:space:]]*)resource_metadata=\"([^\"]*)\" ]]; then
        metadata="${BASH_REMATCH[2]}"
    fi
    if [[ ",${cache_control// /}," != *,no-store,* \
        || "$metadata" != "$site_url/.well-known/oauth-protected-resource${metadata_path}" \
        || "$(grep -o 'resource_metadata=' <<<"$challenge" | wc -l | tr -d ' ')" != 1 \
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
        || array_diff(["genai:read", "genai:work"], $scopes) !== []
        || in_array("mcp:use", $scopes, true)) {
        fwrite(STDERR, "Protected-resource metadata does not match the deployed Agent API.\n");
        exit(1);
    }
' || exit 1

# RFC 9728 3.3: the MCP endpoint is its own protected resource, so its metadata
# names exactly /api/v1/mcp and is the only document that offers the connection scope.
request mcp-metadata "$site_url/.well-known/oauth-protected-resource/api/v1/mcp"
[[ "$RESPONSE_STATUS" == 200 ]] || { echo "MCP protected-resource metadata returned HTTP ${RESPONSE_STATUS}." >&2; exit 1; }
# shellcheck disable=SC2016 # The PHP program is intentionally a literal string.
PHR_VERIFY_JSON="$RESPONSE_BODY" PHR_VERIFY_SITE="$site_url" "$php_runner" -r '
    $data = json_decode((string) file_get_contents(getenv("PHR_VERIFY_JSON")), true, 16, JSON_THROW_ON_ERROR);
    $site = rtrim((string) getenv("PHR_VERIFY_SITE"), "/");
    $scopes = $data["scopes_supported"] ?? [];
    if (($data["resource"] ?? null) !== $site."/api/v1/mcp"
        || ($data["authorization_servers"][0] ?? null) !== $site
        || ! is_array($scopes)
        || array_diff(["mcp:use", "genai:read", "genai:work"], $scopes) !== []) {
        fwrite(STDERR, "MCP protected-resource metadata does not match the deployed Agent API.\n");
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
assert_private_challenge 'GenAI queue' /api/v1

readonly initialize_payload='{"jsonrpc":"2.0","id":"deploy-check","method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"deploy-check","version":"1"}}}'
request mcp "$site_url/api/v1/mcp" \
    --request POST --header 'Content-Type: application/json' \
    --header 'Mcp-Protocol-Version: 2025-06-18' --data "$initialize_payload"
assert_private_challenge 'MCP' /api/v1/mcp

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

# Subsequent host verification failures must not be labelled as HTTP failures.
RESPONSE_NAME=''

"$ssh_bin" "$DEPLOY_SSH_TARGET" \
    "bash -s -- $(printf '%q ' "$DEPLOY_CANDIDATE_DIR" "$DEPLOY_PHP_BINARY" "$DEPLOY_DIR")" \
    <"$script_dir/verify-phr-candidate.sh"

timeout 60 "$ssh_bin" "$DEPLOY_SSH_TARGET" \
    "bash -s -- $(printf '%q ' "$DEPLOY_DIR" "$DEPLOY_PHP_BINARY" "$DEPLOY_RELEASE_ID" "$DEPLOY_SOURCE_COMMIT" "$DEPLOY_RELEASE_ID")" \
    <"$script_dir/verify-phr-ohif-artifact.sh"

crontab_file="$verify_root/crontab"
"$ssh_bin" "$DEPLOY_SSH_TARGET" 'crontab -l' >"$crontab_file"

# shellcheck source=./verify-phr-cron.sh
source "$script_dir/verify-phr-cron.sh"
verify_phr_cron "$crontab_file"

echo 'Production PHR HTTP, login, assets, OAuth, MCP, OHIF, queue, key, memory, and cron checks passed.'
