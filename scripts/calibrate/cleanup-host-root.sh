#!/usr/bin/env bash
# Idempotent fallback for a failed transfer/SSH connection. Never follows links.
set -euo pipefail
[[ $# == 1 && $1 =~ ^/tmp/phr-calibration\.[A-Za-z0-9]{10}$ ]] || exit 2
root=$1
if [[ ! -e "$root" && ! -L "$root" ]]; then
    echo '{"cleanup":"confirmed","scope":"owned-synthetic-root"}'
    exit 0
fi
[[ -d "$root" && ! -L "$root" && $(realpath -- "$root") == "$root" \
    && $(stat -c %u -- "$root") == "$(id -u)" && $(stat -c %a -- "$root") == 700 \
    && -f "$root/.owned-synthetic-calibration" && ! -L "$root/.owned-synthetic-calibration" \
    && $(cat "$root/.owned-synthetic-calibration") == phr-host-calibration-v1 ]] || exit 2
find "$root" -xdev -depth -delete
echo '{"cleanup":"confirmed","scope":"owned-synthetic-root"}'
