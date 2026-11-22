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
cat >"$fake_php" <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
if [[ "${1:-}" == '-d' ]]; then
    shift 2
fi
[[ "${1:-}" == artisan ]] || exit 2
case "${2:-}" in
    env) printf 'Current application environment: production\n' ;;
    bherila-auth:provider-identity-cutover)
        [[ "${3:-}" == check ]] || exit 2
        exit "${PHR_TEST_CUTOVER_CHECK_STATUS:-0}"
        ;;
    *) exit 2 ;;
esac
SCRIPT
chmod +x "$fake_php"

"$verifier" phr-laravel "$fake_php" phr-laravel >/dev/null

if PHR_TEST_CUTOVER_CHECK_STATUS=1 "$verifier" phr-laravel "$fake_php" phr-laravel >/dev/null 2>&1; then
    echo 'Expected a configuration that disagrees with the recorded provider identity cutover to fail activation verification.' >&2
    exit 1
fi

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
