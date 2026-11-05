#!/usr/bin/env bash
# The runner records this random path/token BEFORE this fresh, noclobber creation.
set -euo pipefail
[[ $# == 2 && $1 =~ ^/tmp/phr-calibration\.[a-f0-9]{10}$ && $2 =~ ^[a-f0-9]{64}$ ]] || exit 2
root=$1
nonce=$2
umask 077
export PATH=/usr/bin:/bin
if ! mkdir -m 700 -- "$root" 2>/dev/null; then
    echo '{"stage":"root_creation","status":"refused","code":"mkdir_failed"}'
    exit 1
fi
# If initialization fails before its nonce can be written, remove only this
# process's newly created empty directory. Never touch a preexisting directory.
initialized=false
cleanup_incomplete() {
    if [[ "$initialized" == false ]]; then
        if [[ -f "$root/.owned-synthetic-calibration" && ! -L "$root/.owned-synthetic-calibration" ]]; then
            unlink "$root/.owned-synthetic-calibration"
        fi
        rmdir -- "$root" 2>/dev/null || true
    fi
}
trap cleanup_incomplete EXIT
printf 'phr-host-calibration-v1:%s\n' "$nonce" > "$root/.owned-synthetic-calibration"
initialized=true
printf '{"stage":"root_creation","status":"ready","code":"ready"}\n'
