#!/usr/bin/env bash
# One incident only: restore the exact selected release left by the failed probe.
set -euo pipefail
[[ $# == 4 ]] || exit 2
mode=$1 owner=$2 expected_marker=$3 php=$4
[[ "$mode" == inspect || "$mode" == up || "$mode" == down ]] || exit 2
[[ "$owner" =~ ^recovery-[1-9][0-9]*-[1-9][0-9]*$ && "$php" == /* && -x "$php" ]] || exit 2
stable="$HOME/phr-laravel"
control="$HOME/.deployments/phr-laravel"
fail() { echo 'Reviewed release recovery proof/operation failed; details redacted.' >&2; exit 1; }
for directory in "$HOME" "$HOME/.deployments" "$control" "$control/state" "$stable"; do
    [[ -d "$directory" && ! -L "$directory" && "$(readlink -f "$directory")" == "$directory" ]] || fail
done
[[ -f "$stable/.deploy-release" && ! -L "$stable/.deploy-release" && "$(wc -c < "$stable/.deploy-release")" -le 4096 \
    && "$(sed -n 's/^release=//p' "$stable/.deploy-release")" == 7b33fe7445aa-37239370303-1 \
    && "$(sed -n 's/^commit=//p' "$stable/.deploy-release")" == 7b33fe7445aa6d0a002f2e752497282b04c360a2 \
    && -f "$stable/artisan" && ! -L "$stable/artisan" ]] || fail
[[ -d "$control/deploy.lock" && ! -L "$control/deploy.lock" \
    && -f "$control/deploy.lock/owner" && ! -L "$control/deploy.lock/owner" \
    && "$(wc -c < "$control/deploy.lock/owner")" -le 128 \
    && "$(cat "$control/deploy.lock/owner")" == "$owner" \
    && -z "$(find "$control/state" -mindepth 1 -maxdepth 1 -print -quit)" ]] || fail
marker="$stable/storage/framework/down"
if [[ "$mode" != down ]]; then
    [[ -f "$marker" && ! -L "$marker" ]] || fail
    modified=$(stat -c %Y "$marker")
    # The first automated failed deployment occurred at 22:20 UTC. A later
    # operator maintenance marker is outside this explicitly reviewed window.
    [[ "$modified" =~ ^[0-9]+$ && "$modified" -ge 1791152340 && "$modified" -le 1791152520 ]] || fail
    marker_digest=$(sha256sum "$marker" | cut -d' ' -f1)
    if [[ "$mode" == inspect ]]; then printf '%s\n' "$marker_digest"; exit 0; fi
    [[ "$expected_marker" =~ ^[a-f0-9]{64}$ && "$marker_digest" == "$expected_marker" ]] || fail
fi
cd "$stable"
umask 077
scratch=$(mktemp -d)
trap 'rm -rf -- "$scratch"' EXIT
if ! (ulimit -f 2048; exec timeout --kill-after=2s 30s "$php" -d memory_limit=1G artisan "$mode") > "$scratch/output" 2> "$scratch/error"; then fail; fi
echo 'Exact selected release maintenance operation verified.'
