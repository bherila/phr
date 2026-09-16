#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 3 ]]; then
    echo 'usage: verify-phr-activation.sh <stable-path> <php> <candidate-path>' >&2
    exit 2
fi

readonly stable_path="$1"
readonly php_bin="$2"
readonly active_candidate_path="$3"

case "$stable_path" in
    '' | .* | */* | *[!A-Za-z0-9._-]*)
        echo 'The activation verifier received an unsafe stable path.' >&2
        exit 2
        ;;
esac

if [[ "$active_candidate_path" != "$stable_path" ]]; then
    echo 'Stable-directory activation must expose the selected candidate at the stable path.' >&2
    exit 2
fi

case "$php_bin" in
    /*) ;;
    *)
        echo 'The activation verifier requires an absolute PHP path.' >&2
        exit 2
        ;;
esac

readonly stable_root="$HOME/$stable_path"
readonly shared_root="$HOME/.deployments/$stable_path/shared"

if [[ ! -x "$php_bin" || ! -d "$stable_root" || -L "$stable_root" || ! -f "$stable_root/artisan" \
    || ! -f "$stable_root/.deploy-release" ]]; then
    echo 'The selected PHR release or PHP binary is unavailable after activation.' >&2
    exit 1
fi

release_id="$(sed -n 's/^release=//p' "$stable_root/.deploy-release" | head -1)"
release_commit="$(sed -n 's/^commit=//p' "$stable_root/.deploy-release" | head -1)"
if [[ ! "$release_id" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ \
    || ! "$release_commit" =~ ^[0-9A-Fa-f]{40,64}$ ]]; then
    echo 'The stable PHR directory has invalid release metadata.' >&2
    exit 1
fi

for persistent_path in storage public/ohif; do
    selected_path="$stable_root/$persistent_path"
    shared_path="$shared_root/$persistent_path"
    selected_real="$(readlink -f "$selected_path" || true)"
    shared_real="$(readlink -f "$shared_path" || true)"
    if [[ ! -L "$selected_path" || -z "$selected_real" || "$selected_real" != "$shared_real" ]]; then
        echo "The selected release does not use managed persistent state for ${persistent_path}." >&2
        exit 1
    fi
done

cd "$stable_root"
app_environment="$("$php_bin" -d memory_limit=1G artisan env --no-ansi)"
if ! grep -Fq 'production' <<<"${app_environment,,}"; then
    echo 'The selected PHR release did not boot in the production environment.' >&2
    exit 1
fi

echo 'The real stable PHR directory contains the selected candidate and its managed persistent state.'
