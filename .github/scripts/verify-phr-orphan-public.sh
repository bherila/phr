#!/usr/bin/env bash
set -euo pipefail
readonly site=https://phr.bherila.net
readonly probe_root="$(mktemp -d)"
trap 'rm -rf "$probe_root"' EXIT
probe() {
    local endpoint=$1 expected=$2 actual
    shift 2
    actual=$(curl --silent --show-error --proto '=https' --max-redirs 0 --max-time 20 \
        --dump-header "$probe_root/headers" --output "$probe_root/body" --write-out '%{http_code}' \
        "$@" "$site$endpoint")
    [[ "$actual" == "$expected" ]] || { echo 'Fixed public recovery probe failed.' >&2; return 1; }
}
probe /up 200
# A client-routed viewer path must hit Laravel auth, not Apache's directory
# redirect or a static entrypoint. Never follow a redirect into a login-page 200.
probe /ohif/viewer/dicomjson 302
location=$(awk 'tolower($1) == "location:" {sub(/^[^:]*:[[:space:]]*/, ""); sub(/\r$/, ""); print}' "$probe_root/headers")
[[ "$location" == /login || "$location" == "$site/login" ]] || {
    echo 'OHIF viewer did not preserve its exact authentication redirect.' >&2
    exit 1
}
probe /.well-known/oauth-protected-resource/api/v1 200
php -d memory_limit=1G -r '
    $data = json_decode((string) file_get_contents($argv[1]), true);
    exit(is_array($data) && ($data["resource"] ?? null) === "https://phr.bherila.net/api/v1"
        && ($data["authorization_servers"][0] ?? null) === "https://phr.bherila.net" ? 0 : 1);
' "$probe_root/body"
probe /api/v1/genai/queue/status 401
grep -Eiq '^cache-control:.*no-store' "$probe_root/headers"
grep -Fiq 'Bearer resource_metadata="https://phr.bherila.net/.well-known/oauth-protected-resource/api/v1"' "$probe_root/headers"
probe /api/v1/mcp 401 --request POST --header 'Content-Type: application/json' \
    --header 'Mcp-Protocol-Version: 2025-06-18' \
    --data '{"jsonrpc":"2.0","id":"recovery-check","method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"recovery-check","version":"1"}}}'
grep -Eiq '^cache-control:.*no-store' "$probe_root/headers"
grep -Fiq 'Bearer resource_metadata="https://phr.bherila.net/.well-known/oauth-protected-resource/api/v1"' "$probe_root/headers"
echo 'Fixed public health and unauthenticated OAuth/MCP boundaries verified; no tokens or data writes.'
