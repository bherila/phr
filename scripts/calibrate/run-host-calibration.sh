#!/usr/bin/env bash
# Runner orchestration. All captured SSH text is private until finite validation.
set -euo pipefail
[[ ${CANDIDATE:-} =~ ^[a-f0-9]{40}$ && -n ${RUNNER_TEMP:-} ]] || exit 2
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
mkdir -p build/ehi-host
ssh_options=(-o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=10 -o ServerAliveCountMax=2)
ssh_bin=${EHI_HOST_SSH_BIN:-ssh}
rsync_bin=${EHI_HOST_RSYNC_BIN:-rsync}
php_binary=/opt/cpanel/ea-php85/root/usr/bin/php
capture_stage() {
    local stage=$1 script=$2 status=0
    shift 2
    # Bound stdout and suppress all unvalidated transport errors/banners.
    (ulimit -f 4; timeout 30s "$ssh_bin" "${ssh_options[@]}" phr-calibration \
        "bash -s -- $(printf '%q ' "$@")" < "$script") \
        > "$RUNNER_TEMP/ehi-host-stage-raw" 2> "$RUNNER_TEMP/ehi-host-stage-error" || status=$?
    python3 "$script_dir/validate-host-stage.py" "$RUNNER_TEMP/ehi-host-stage-raw" "$stage" "$status" \
        > "$RUNNER_TEMP/ehi-host-stage-proof" || {
        tee -a build/ehi-host/host-stages.jsonl < "$RUNNER_TEMP/ehi-host-stage-proof"
        return 1
    }
    tee -a build/ehi-host/host-stages.jsonl < "$RUNNER_TEMP/ehi-host-stage-proof"
}
capture_stage prerequisites "$script_dir/inspect-host-prerequisites.sh" "$php_binary"
# Persist ownership before the first write. Root/token are never printed or uploaded.
python3 - "$RUNNER_TEMP/ehi-host-owned-root" <<'PY'
import os
from pathlib import Path
import secrets
import sys
path = Path(sys.argv[1])
with path.open("x") as state:
    os.chmod(path, 0o600)
    state.write("/tmp/phr-calibration." + secrets.token_hex(5) + "\n" + secrets.token_hex(32) + "\n")
PY
mapfile -t ownership < "$RUNNER_TEMP/ehi-host-owned-root"
fixture_root=${ownership[0]}
nonce=${ownership[1]}
capture_stage root_creation "$script_dir/create-host-root.sh" "$fixture_root" "$nonce"
payload="$RUNNER_TEMP/ehi-host-payload"
digest=$(sha256sum "$payload/tree-manifest.json" | cut -d' ' -f1)
verifier_digest=$(sha256sum "$script_dir/verify-host-tree.php" | cut -d' ' -f1)
transfer_started=$(date +%s)
if ! timeout 120s "$rsync_bin" -rz --perms --no-links --no-specials --no-devices \
    --chmod=Du=rwx,Dgo=,Fu=rw,Fgo= --protect-args \
    -e 'ssh -o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=10 -o ServerAliveCountMax=2' \
    "$payload/" "phr-calibration:$fixture_root/" \
    > "$RUNNER_TEMP/ehi-host-transfer-output" 2> "$RUNNER_TEMP/ehi-host-transfer-error"; then
    echo '{"stage":"transfer","status":"refused","code":"transport_failed"}' | tee -a build/ehi-host/host-stages.jsonl
    exit 1
fi
printf '{"stage":"transfer","status":"ready","code":"ready","seconds":%s}\n' "$(( $(date +%s) - transfer_started ))" | tee -a build/ehi-host/host-stages.jsonl
status=0
(ulimit -f 384; timeout --kill-after=15s 1050s "$ssh_bin" "${ssh_options[@]}" phr-calibration \
    "timeout --kill-after=10s 1000s bash $(printf '%q' "$fixture_root/ehi-host-remote.sh") $(printf '%q ' "$fixture_root" "$digest" "$CANDIDATE" "$php_binary" "$nonce" "$verifier_digest")") \
    > "$RUNNER_TEMP/ehi-host-runtime-raw" 2> "$RUNNER_TEMP/ehi-host-runtime-error" || status=$?
python3 "$script_dir/validate-host-runtime.py" "$RUNNER_TEMP/ehi-host-runtime-raw" "$CANDIDATE" "$status" \
    build/ehi-host/host-results.jsonl "$GITHUB_STEP_SUMMARY" "$payload/tree-manifest.json" | tee -a build/ehi-host/host-stages.jsonl
