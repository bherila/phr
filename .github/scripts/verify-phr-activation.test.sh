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
stable_root="$HOME/phr-laravel"
shared_root="$HOME/.deployments/phr-laravel/shared"
mkdir -p "$stable_root/public" "$shared_root/storage" "$shared_root/public/ohif"
touch "$stable_root/artisan"
printf 'release=candidate\ncommit=abcdef1234567890abcdef1234567890abcdef12\ncreated_at=2026-09-16T00:00:00Z\n' >"$stable_root/.deploy-release"
ln -s "$shared_root/storage" "$stable_root/storage"
ln -s "$shared_root/public/ohif" "$stable_root/public/ohif"

fake_php="$test_root/php"
printf '%s\n' '#!/usr/bin/env bash' 'printf "Current application environment: production\\n"' >"$fake_php"
chmod +x "$fake_php"

mode() { stat -c %a "$1" 2>/dev/null || stat -f %Lp "$1"; }

# Start from the layout the deployment action creates: 0700 control directories
# and a world-readable storage tree that the web server never serves.
chmod 700 "$HOME/.deployments" "$HOME/.deployments/phr-laravel" "$shared_root" "$shared_root/public"
chmod 755 "$shared_root/storage" "$shared_root/public/ohif"

"$verifier" phr-laravel "$fake_php" phr-laravel >/dev/null

for directory in "$HOME/.deployments" "$HOME/.deployments/phr-laravel" "$shared_root" "$shared_root/public"; do
    [[ "$(mode "$directory")" == 711 ]] || {
        echo "Expected the web server to be able to traverse ${directory#"$HOME"/}." >&2; exit 1;
    }
done
[[ "$(mode "$shared_root/storage")" == 700 ]] || {
    echo 'Expected shared storage to be private once its parent is traversable.' >&2; exit 1;
}
[[ "$(mode "$shared_root/public/ohif")" == 755 ]] || {
    echo 'Expected the OHIF bundle directory mode to be left alone.' >&2; exit 1;
}

# A symlinked control directory would let chmod re-permission its target.
mv "$shared_root/public" "$test_root/public-elsewhere"
ln -s "$test_root/public-elsewhere" "$shared_root/public"
chmod 700 "$test_root/public-elsewhere"
if "$verifier" phr-laravel "$fake_php" phr-laravel >/dev/null 2>&1; then
    echo 'Expected a symlinked shared public directory to fail activation verification.' >&2
    exit 1
fi
[[ "$(mode "$test_root/public-elsewhere")" == 700 ]] || {
    echo 'A symlinked shared public directory was re-permissioned.' >&2; exit 1;
}
rm "$shared_root/public"
mv "$test_root/public-elsewhere" "$shared_root/public"

rm "$stable_root/public/ohif"
mkdir "$stable_root/public/ohif"
if "$verifier" phr-laravel "$fake_php" phr-laravel >/dev/null 2>&1; then
    echo 'Expected a release-local OHIF directory to fail activation verification.' >&2
    exit 1
fi
rm -rf "$stable_root/public/ohif"
ln -s "$shared_root/public/ohif" "$stable_root/public/ohif"

if "$verifier" phr-laravel "$fake_php" "$candidate_path" >/dev/null 2>&1; then
    echo 'Expected a release-tree active path to fail stable-directory activation verification.' >&2
    exit 1
fi

rm "$stable_root/.deploy-release"
if "$verifier" phr-laravel "$fake_php" phr-laravel >/dev/null 2>&1; then
    echo 'Expected missing selected release metadata to fail activation verification.' >&2
    exit 1
fi

echo 'verify PHR activation tests passed'
