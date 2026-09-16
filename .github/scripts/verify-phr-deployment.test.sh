#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly script_dir
readonly verifier="$script_dir/verify-phr-deployment.sh"
test_root="$(mktemp -d)"
readonly test_root
trap 'rm -rf "$test_root"' EXIT

fake_curl="$test_root/curl"
fake_ssh="$test_root/ssh"
fake_crontab="$test_root/crontab"
ssh_log="$test_root/ssh.log"

cat >"$fake_curl" <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
headers=/dev/null
body=/dev/null
method=GET
origin=''
url=''
while [[ $# -gt 0 ]]; do
    case "$1" in
        --dump-header) headers="$2"; shift 2 ;;
        --output) body="$2"; shift 2 ;;
        --request) method="$2"; shift 2 ;;
        --header)
            [[ "$2" == 'Origin: '* ]] && origin="${2#Origin: }"
            shift 2
            ;;
        --data) shift 2 ;;
        --retry|--max-time|--write-out) shift 2 ;;
        --silent|--show-error|--retry-all-errors) shift ;;
        http*) url="$1"; shift ;;
        *) shift ;;
    esac
done
status=200
cache='public, max-age=300'
challenge=''
allow_origin=''
payload='{}'
case "$url" in
    */.well-known/oauth-protected-resource/api/v1)
        payload='{"resource":"https://phr.example.test/api/v1","authorization_servers":["https://phr.example.test"],"scopes_supported":["mcp:use","genai:read","genai:work"]}'
        [[ -z "${PHR_TEST_METADATA:-}" ]] || payload="$PHR_TEST_METADATA"
        ;;
    */api/v1/capabilities)
        payload='{"api_version":"v1","limits":{"maximum_page_size":100},"oauth":{"authorization_code_pkce":true,"protected_resource_metadata":"https://phr.example.test/.well-known/oauth-protected-resource/api/v1"},"operations":{"mcp.exchange":{"available":true},"genai.queue_status":{"available":true},"genai.requests.claim":{"available":true},"genai.requests.complete":{"available":true},"genai.requests.fail":{"available":true}}}'
        [[ -z "${PHR_TEST_CAPABILITIES:-}" ]] || payload="$PHR_TEST_CAPABILITIES"
        ;;
    */api/v1/genai/queue/status)
        status="${PHR_TEST_QUEUE_STATUS:-401}"
        cache="${PHR_TEST_QUEUE_CACHE:-private, no-store, max-age=0}"
        challenge="${PHR_TEST_QUEUE_CHALLENGE:-Bearer resource_metadata=\"https://phr.example.test/.well-known/oauth-protected-resource/api/v1\"}"
        ;;
    */api/v1/mcp)
        if [[ "$method" == OPTIONS && -n "$origin" ]]; then
            status="${PHR_TEST_HOSTILE_STATUS:-204}"
            cache='private, no-store'
            allow_origin="${PHR_TEST_HOSTILE_ALLOW_ORIGIN:-}"
        else
            status="${PHR_TEST_MCP_STATUS:-401}"
            cache='private, no-store, max-age=0'
            challenge='Bearer resource_metadata="https://phr.example.test/.well-known/oauth-protected-resource/api/v1"'
        fi
        ;;
esac
{
    printf 'HTTP/1.1 %s Synthetic\r\n' "$status"
    printf 'Cache-Control: %s\r\n' "$cache"
    printf 'X-Content-Type-Options: nosniff\r\n'
    [[ -z "$challenge" ]] || printf 'WWW-Authenticate: %s\r\n' "$challenge"
    [[ -z "$allow_origin" ]] || printf 'Access-Control-Allow-Origin: %s\r\n' "$allow_origin"
    printf '\r\n'
} >"$headers"
printf '%s' "$payload" >"$body"
printf '%s' "$status"
SCRIPT
cat >"$fake_ssh" <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >>"$PHR_TEST_SSH_LOG"
if [[ "${2:-}" == 'crontab -l' ]]; then
    cat "$PHR_TEST_CRONTAB"
else
    cat >/dev/null
fi
SCRIPT
chmod +x "$fake_curl" "$fake_ssh"

export DEPLOY_SSH_TARGET=cpanel-deploy@host.example.test
export DEPLOY_PHP_BINARY=/opt/cpanel/ea-php85/root/usr/bin/php
export DEPLOY_DIR=phr-laravel
export DEPLOY_RELEASE_ID=abcdef123456-42-1
export DEPLOY_CANDIDATE_DIR=.deployments/phr-laravel/releases/abcdef123456-42-1
export DEPLOY_SITE_URL=https://phr.example.test
export DEPLOY_SOURCE_COMMIT=abcdef1234567890abcdef1234567890abcdef12
export DEPLOY_LIVE_RELEASE="$DEPLOY_RELEASE_ID"
export DEPLOY_LIVE_COMMIT="$DEPLOY_SOURCE_COMMIT"
export DEPLOY_LIVE_STATE=serving
export PHR_VERIFY_CURL_BIN="$fake_curl"
export PHR_VERIFY_SSH_BIN="$fake_ssh"
export PHR_TEST_CRONTAB="$fake_crontab"
export PHR_TEST_SSH_LOG="$ssh_log"

# shellcheck disable=SC2016 # $HOME must remain literal in the cron fixtures.
printf '%s\n' \
    'MAILTO=owner@example.test' \
    '*/5 * * * * cd "$HOME/phr-laravel" && PHR_CRON_MEMORY_LIMIT=1G /opt/cpanel/ea-php85/root/usr/bin/php -d memory_limit=1G artisan phr:uptime:run-scheduler >> /dev/null 2>&1 # JOB:phr-laravel-scheduler' \
    '*/5 * * * * cd "$HOME/phr-laravel" && PHR_CRON_MEMORY_LIMIT=1G /usr/bin/flock -n "$HOME/phr-laravel/storage/framework/phr-queue-worker.lock" /opt/cpanel/ea-php85/root/usr/bin/php -d memory_limit=1G artisan phr:uptime:run-worker >> /dev/null 2>&1 # JOB:phr-laravel-queue-worker' \
    >"$fake_crontab"

"$verifier" >/dev/null
grep -Fq "$DEPLOY_CANDIDATE_DIR" "$ssh_log"

export DEPLOY_SSH_TARGET=-V
if "$verifier" >/dev/null 2>&1; then
    echo 'Expected an option-like SSH target to fail verification.' >&2
    exit 1
fi
export DEPLOY_SSH_TARGET=cpanel-deploy@host.example.test

export PHR_TEST_METADATA='{"resource":"https://wrong.example/api/v1","authorization_servers":["https://phr.example.test"],"scopes_supported":["mcp:use","genai:read","genai:work"]}'
if "$verifier" >/dev/null 2>&1; then
    echo 'Expected mismatched protected-resource metadata to fail.' >&2
    exit 1
fi
unset PHR_TEST_METADATA

export PHR_TEST_CAPABILITIES='{"api_version":"v1","limits":{"maximum_page_size":100},"oauth":{"authorization_code_pkce":false}}'
if "$verifier" >/dev/null 2>&1; then
    echo 'Expected incomplete Agent API capabilities to fail.' >&2
    exit 1
fi
unset PHR_TEST_CAPABILITIES

export PHR_TEST_QUEUE_CACHE='private, max-age=0'
if "$verifier" >/dev/null 2>&1; then
    echo 'Expected a cacheable unauthenticated queue response to fail.' >&2
    exit 1
fi
unset PHR_TEST_QUEUE_CACHE

export PHR_TEST_MCP_STATUS=200
if "$verifier" >/dev/null 2>&1; then
    echo 'Expected an unauthenticated MCP endpoint that returned 200 to fail.' >&2
    exit 1
fi
unset PHR_TEST_MCP_STATUS

export PHR_TEST_HOSTILE_ALLOW_ORIGIN=https://hostile.invalid
if "$verifier" >/dev/null 2>&1; then
    echo 'Expected hostile-origin CORS permission to fail.' >&2
    exit 1
fi
unset PHR_TEST_HOSTILE_ALLOW_ORIGIN

sed -i '/phr-laravel-queue-worker/d' "$fake_crontab"
if "$verifier" >/dev/null 2>&1; then
    echo 'Expected a missing queue-worker cron to fail.' >&2
    exit 1
fi

echo 'verify PHR deployment tests passed'
