#!/usr/bin/env bash
# Streamed from the trusted runner. Read only deployment metadata, never Laravel.
set -euo pipefail
stable="$HOME/phr-laravel"
control="$HOME/.deployments/phr-laravel"
[[ "$(readlink -f "$HOME")" == "$HOME" ]] || exit 1
for directory in "$HOME/.deployments" "$control"; do
    if [[ -e "$directory" || -L "$directory" ]]; then
        [[ -d "$directory" && ! -L "$directory" && "$(readlink -f "$directory")" == "$directory" ]] || exit 1
    fi
done
if [[ ! -e "$stable" && ! -L "$stable" ]]; then
    [[ ! -e "$control/deploy.lock" && ! -L "$control/deploy.lock" ]] || exit 1
    printf '{"state":"absent","commit":"","release":""}\n'
    exit 0
fi
[[ -d "$stable" && ! -L "$stable" && "$(readlink -f "$stable")" == "$stable" && -f "$stable/artisan" \
    && -f "$stable/.deploy-release" && ! -L "$stable/.deploy-release" \
    && "$(wc -c < "$stable/.deploy-release")" -le 4096 ]] || exit 1
release=$(sed -n 's/^release=//p' "$stable/.deploy-release")
commit=$(sed -n 's/^commit=//p' "$stable/.deploy-release")
[[ "$release" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ && "$commit" =~ ^[a-f0-9]{40}$ ]] || exit 1
printf '{"state":"selected","commit":"%s","release":"%s"}\n' "$commit" "$release"
