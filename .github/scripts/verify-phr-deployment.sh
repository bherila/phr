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
request ohif "$site_url/ohif/"
[[ "$RESPONSE_STATUS" == 200 ]] || { echo "Production verification failed for /ohif/ with HTTP ${RESPONSE_STATUS}." >&2; exit 1; }

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

echo 'Production PHR HTTP, OAuth, MCP, OHIF, queue, key, memory, and cron checks passed.'
