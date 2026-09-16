#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly script_dir
readonly hook="$script_dir/quiesce-phr-deployment.sh"
test_root="$(mktemp -d)"
readonly test_root
trap 'rm -rf "$test_root"' EXIT

export HOME="$test_root/home"
mkdir -p "$HOME/phr-laravel" "$HOME/.deployments/phr-laravel/releases/candidate"
touch "$HOME/phr-laravel/artisan" "$HOME/.deployments/phr-laravel/releases/candidate/artisan"

fake_php="$test_root/php"
fake_sleep="$test_root/sleep"
fake_pgrep="$test_root/pgrep"
counter="$test_root/counter"

printf '#!/usr/bin/env bash\nexit 0\n' >"$fake_php"
printf '#!/usr/bin/env bash\nexit 0\n' >"$fake_sleep"
cat >"$fake_pgrep" <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
count=0
[[ ! -f "$PHR_TEST_COUNTER" ]] || count="$(cat "$PHR_TEST_COUNTER")"
count=$((count + 1))
printf '%s\n' "$count" >"$PHR_TEST_COUNTER"
if (( count <= PHR_TEST_ACTIVE_POLLS )); then
    exit 0
fi
exit 1
SCRIPT
chmod +x "$fake_php" "$fake_sleep" "$fake_pgrep"

export PHR_QUIESCE_PGREP_BIN="$fake_pgrep"
export PHR_QUIESCE_SLEEP_BIN="$fake_sleep"
export PHR_QUIESCE_TIMEOUT_SECONDS=10
export PHR_QUIESCE_POLL_SECONDS=2
export PHR_TEST_COUNTER="$counter"
export PHR_TEST_ACTIVE_POLLS=2

"$hook" .deployments/phr-laravel/releases/candidate "$fake_php" phr-laravel >/dev/null
[[ "$(cat "$counter")" == 3 ]]

rm -f "$counter"
export PHR_TEST_ACTIVE_POLLS=99
if "$hook" .deployments/phr-laravel/releases/candidate "$fake_php" phr-laravel >/dev/null 2>&1; then
    echo 'Expected active PHR processes to fail closed at the timeout.' >&2
    exit 1
fi

rm -f "$counter"
if "$hook" ../unsafe "$fake_php" phr-laravel >/dev/null 2>&1; then
    echo 'Expected an unsafe candidate path to be refused.' >&2
    exit 1
fi

echo 'quiesce PHR deployment tests passed'
