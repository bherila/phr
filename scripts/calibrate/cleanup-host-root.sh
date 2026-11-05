#!/usr/bin/env bash
# Idempotent fallback for a failed transfer/SSH connection. Never follows links.
set -euo pipefail
[[ $# == 2 && $1 =~ ^/tmp/phr-calibration\.[a-f0-9]{10}$ && $2 =~ ^[a-f0-9]{64}$ ]] || exit 2
root=$1
nonce=$2
if [[ ! -e "$root" && ! -L "$root" ]]; then
    echo '{"cleanup":"confirmed","scope":"owned-synthetic-root"}'
    exit 0
fi
[[ -d "$root" && ! -L "$root" && $(realpath -- "$root") == "$root" \
    && $(stat -c %u -- "$root") == "$(id -u)" && $(stat -c %a -- "$root") == 700 \
    && -f "$root/.owned-synthetic-calibration" && ! -L "$root/.owned-synthetic-calibration" \
    && $(cat "$root/.owned-synthetic-calibration") == "phr-host-calibration-v1:$nonce" ]] || exit 2
find "$root" -xdev -depth -delete
echo '{"cleanup":"confirmed","scope":"owned-synthetic-root"}'
