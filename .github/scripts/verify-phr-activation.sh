#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 3 ]]; then
    echo 'usage: verify-phr-activation.sh <stable-path> <php> <candidate-path>' >&2
    exit 2
fi

readonly stable_path="$1"
readonly php_bin="$2"
readonly candidate_path="$3"

case "$stable_path" in
    '' | .* | */* | *[!A-Za-z0-9._-]*)
        echo 'The activation verifier received an unsafe stable path.' >&2
        exit 2
        ;;
esac

case "$candidate_path" in
    .deployments/"$stable_path"/releases/*)
        release_id="${candidate_path##*/}"
        if [[ -z "$release_id" || "$release_id" == .* || "$release_id" == *[!A-Za-z0-9._-]* ]]; then
            echo 'The activation verifier received an unsafe release id.' >&2
            exit 2
        fi
        ;;
    *)
        echo 'The activation verifier received an unrelated candidate path.' >&2
        exit 2
        ;;
esac

case "$php_bin" in
    /*) ;;
    *)
        echo 'The activation verifier requires an absolute PHP path.' >&2
        exit 2
        ;;
esac

readonly stable_root="$HOME/$stable_path"
readonly candidate_root="$HOME/$candidate_path"
readonly shared_root="$HOME/.deployments/$stable_path/shared"

if [[ ! -x "$php_bin" || ! -L "$stable_root" || ! -f "$candidate_root/artisan" ]]; then
    echo 'The selected PHR release or PHP binary is unavailable after activation.' >&2
    exit 1
fi

stable_real="$(readlink -f "$stable_root" || true)"
candidate_real="$(readlink -f "$candidate_root" || true)"
if [[ -z "$stable_real" || "$stable_real" != "$candidate_real" ]]; then
    echo 'The stable PHR path does not select the candidate release.' >&2
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

echo 'The stable PHR path selects the candidate and its managed persistent state.'
