#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly script_dir
readonly verifier="$script_dir/verify-phr-activation.sh"
test_root="$(mktemp -d)"
readonly test_root
trap 'rm -rf "$test_root"' EXIT

export HOME="$test_root/home"
candidate_path='.deployments/phr-laravel/releases/candidate'
candidate_root="$HOME/$candidate_path"
shared_root="$HOME/.deployments/phr-laravel/shared"
mkdir -p "$candidate_root/public" "$shared_root/storage" "$shared_root/public/ohif"
touch "$candidate_root/artisan"
ln -s "$shared_root/storage" "$candidate_root/storage"
ln -s "$shared_root/public/ohif" "$candidate_root/public/ohif"
ln -s "$candidate_path" "$HOME/phr-laravel"

fake_php="$test_root/php"
printf '%s\n' '#!/usr/bin/env bash' 'printf "Current application environment: production\\n"' >"$fake_php"
chmod +x "$fake_php"

"$verifier" phr-laravel "$fake_php" "$candidate_path" >/dev/null

rm "$candidate_root/public/ohif"
mkdir "$candidate_root/public/ohif"
if "$verifier" phr-laravel "$fake_php" "$candidate_path" >/dev/null 2>&1; then
    echo 'Expected a release-local OHIF directory to fail activation verification.' >&2
    exit 1
fi
rm -rf "$candidate_root/public/ohif"
ln -s "$shared_root/public/ohif" "$candidate_root/public/ohif"

other_candidate='.deployments/phr-laravel/releases/other'
mkdir -p "$HOME/$other_candidate"
rm "$HOME/phr-laravel"
ln -s "$other_candidate" "$HOME/phr-laravel"
if "$verifier" phr-laravel "$fake_php" "$candidate_path" >/dev/null 2>&1; then
    echo 'Expected the wrong selected release to fail activation verification.' >&2
    exit 1
fi

echo 'verify PHR activation tests passed'
