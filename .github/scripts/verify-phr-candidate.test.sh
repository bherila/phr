#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly script_dir
readonly verifier="$script_dir/verify-phr-candidate.sh"
test_root="$(mktemp -d)"
readonly test_root
trap 'rm -rf "$test_root"' EXIT

export HOME="$test_root/home"
candidate_path='.deployments/phr-laravel/releases/candidate'
stable_path='phr-laravel'
candidate_root="$HOME/$candidate_path"
stable_root="$HOME/$stable_path"
mkdir -p "$candidate_root" "$stable_root"
touch "$candidate_root/artisan" "$stable_root/artisan"
readonly digest='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
printf 'APP_KEY=synthetic-app-key\nAGENT_API_MUTATION_DIGEST_KEY=%s\n' "$digest" >"$candidate_root/.env"
cp "$candidate_root/.env" "$stable_root/.env"

fake_php="$test_root/php"
cat >"$fake_php" <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
if [[ "${1:-}" == '-d' ]]; then
    shift 2
fi
if [[ "${1:-}" == '-r' ]]; then
    printf '1G'
    exit 0
fi
[[ "${1:-}" == artisan ]] || exit 2
case "${2:-}" in
    schedule:list)
        printf '%s\n' \
            'genai:requeue-stale' \
            'phr:dicom:gc' \
            'phr:exports:purge' \
            'phr:native-backups:purge'
        ;;
    config:show)
        printf 'queue.default ................................ database\n'
        ;;
    phr:queue:audit)
        printf 'queue-audit driver=database retry_after=%s pending_total=0 failed_total=0\n' "${PHR_TEST_RETRY_AFTER:-3660}"
        ;;
    phr:agent-api:verify-oauth-keys) ;;
    *) exit 2 ;;
esac
SCRIPT
chmod +x "$fake_php"

"$verifier" "$candidate_path" "$fake_php" "$stable_path" >/dev/null

printf 'APP_KEY=synthetic-app-key\nAGENT_API_MUTATION_DIGEST_KEY=base64:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=\n' >"$candidate_root/.env"
if "$verifier" "$candidate_path" "$fake_php" "$stable_path" >/dev/null 2>&1; then
    echo 'Expected a candidate digest-key mismatch to fail.' >&2
    exit 1
fi
cp "$stable_root/.env" "$candidate_root/.env"

printf 'APP_KEY=synthetic-app-key\n' >"$stable_root/.env"
"$verifier" "$candidate_path" "$fake_php" "$stable_path" >/dev/null
printf 'APP_KEY=synthetic-app-key\nAGENT_API_MUTATION_DIGEST_KEY=malformed\n' >"$stable_root/.env"
if "$verifier" "$candidate_path" "$fake_php" "$stable_path" >/dev/null 2>&1; then
    echo 'Expected a malformed selected digest key to fail.' >&2
    exit 1
fi
printf 'APP_KEY=synthetic-app-key\nAGENT_API_MUTATION_DIGEST_KEY=%s\n' "$digest" >"$stable_root/.env"

export PHR_TEST_RETRY_AFTER=3600
if "$verifier" "$candidate_path" "$fake_php" "$stable_path" >/dev/null 2>&1; then
    echo 'Expected an unsafe queue retry_after to fail.' >&2
    exit 1
fi

echo 'verify PHR candidate tests passed'
