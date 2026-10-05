#!/usr/bin/env bash
# Runner-only preflight; refuse a mismatched or encrypted secret before SSH writes.
set -euo pipefail
[[ -n ${POLICY_PRIVATE_KEY:-} && -n ${PUBLIC_KEY:-} ]] || exit 2
umask 077
scratch=$(mktemp -d)
trap 'rm -rf -- "$scratch"' EXIT
printf '%s\n' "$POLICY_PRIVATE_KEY" > "$scratch/key"
expected=$(printf '%s' "$PUBLIC_KEY" | cut -d' ' -f1,2)
if ! actual=$(timeout --kill-after=2s 10s ssh-keygen -y -P '' -f "$scratch/key" </dev/null 2> "$scratch/error" | cut -d' ' -f1,2) \
    || [[ "$actual" != "$expected" ]]; then
    echo 'Dedicated PHR key pair validation failed; details redacted.' >&2
    exit 1
fi
echo 'Dedicated PHR key pair validated before SSH authorization.'
