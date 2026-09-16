#!/usr/bin/env bash
set -euo pipefail
readonly script_dir="$(cd "$(dirname "$0")" && pwd)"
readonly fixture_root="$(mktemp -d)"
trap 'rm -rf "$fixture_root"' EXIT
export HOME="$fixture_root/home"
export PATH="$fixture_root/bin:$PATH"
export PHR_ORPHAN_PROC_ROOT="$fixture_root/proc"
export FIXTURE_CRON="$fixture_root/cron"
readonly app=phr-laravel
readonly release=legacy-20260916T131957Z-c765e4086fa2-35100840905-1
readonly commit=153ddfdcb6e8180bf1d1291aa74202e13a92681e
readonly owner=orphan-c765e408-12345-1
readonly php_bin="$fixture_root/bin/php"
mkdir -p "$fixture_root/bin" "$fixture_root/proc"
printf '%s\n' '#!/bin/bash' 'if [[ "$*" == "-l" ]]; then cat "$FIXTURE_CRON"; else [[ "${FAIL_CRON_WRITE:-false}" != true ]] || exit 77; cp "$1" "$FIXTURE_CRON"; fi' > "$fixture_root/bin/crontab"
chmod 700 "$fixture_root/bin/crontab"
printf '%s\n' '#!/bin/bash' 'set -euo pipefail' \
    'if [[ "$*" == *"SELECT 1"* ]]; then echo environment=production; exit 0; fi' \
    'if [[ "$*" == *FileBasedMaintenanceMode* && "${HANG_MAINTENANCE:-false}" == true ]]; then sleep 10; fi' \
    'if [[ "$*" == *"artisan up"* ]]; then if [[ "${DELAY_UP:-false}" == true ]]; then touch "$DELAY_UP_STARTED"; sleep 2; fi; rm -f storage/framework/down; [[ "${FAIL_UP:-false}" != true ]] || exit 72; fi' \
    'exit 0' > "$php_bin"
chmod 700 "$php_bin"
readonly workflow="$script_dir/../workflows/ci.yml"
grep -Fq 'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1' "$workflow"
grep -Fq "github.ref == 'refs/heads/main' && vars.ATOMIC_DEPLOY_ENABLED == 'false'" "$workflow"
[[ $(grep -Fc "inputs.operation != 'recover-phr-orphan'" "$workflow") == 8 ]]
! grep -Eq 'config:|migrate|putenv|key:generate' "$script_dir/recover-phr-orphan.sh"

setup_fixture() {
    rm -rf "$HOME"
    mkdir -p "$HOME/$app/public" "$HOME/.deployments/$app/releases" "$HOME/.deployments/$app/state" \
        "$HOME/.deployments/$app/recovery" "$HOME/.deployments/$app/shared/storage/framework" \
        "$HOME/.deployments/$app/shared/public/ohif"
    printf 'release=%s\ncommit=%s\n' "$release" "$commit" > "$HOME/$app/.deploy-release"
    printf '%s\n' '{"status":503,"secret":"NEVER_EMIT_MARKER_CANARY"}' > "$HOME/.deployments/$app/shared/storage/framework/down"
    printf '%s\n' 'UNCHANGED_ENV_CANARY' > "$HOME/$app/.env"
    : > "$HOME/$app/artisan"
    ln -s "$HOME/.deployments/$app/shared/storage" "$HOME/$app/storage"
    ln -s "$HOME/.deployments/$app/shared/public/ohif" "$HOME/$app/public/ohif"
    printf '%s\n' "*/5 * * * * cd $HOME/$app && PHR_CRON_MEMORY_LIMIT=1G $php_bin -d memory_limit=1G artisan phr:uptime:run-scheduler >> /dev/null 2>&1 # JOB:phr-laravel-scheduler" \
        "*/5 * * * * cd $HOME/$app && PHR_CRON_MEMORY_LIMIT=1G /usr/bin/flock -n $HOME/$app/storage/framework/phr-queue-worker.lock $php_bin -d memory_limit=1G artisan phr:uptime:run-worker >> /dev/null 2>&1 # JOB:phr-laravel-queue-worker" \
        > "$HOME/.deployments/$app/recovery/c765e4086fa2-35100840905-1.cron"
    printf '%s\n\n' '* * * * * UNRELATED_CRON_CANARY' > "$FIXTURE_CRON"
}
run_recovery() { bash "$script_dir/recover-phr-orphan.sh" "$1" "$app" "$release" "$commit" "$php_bin" "$owner"; }
expect_failure() { if "$@" >"$fixture_root/failure.log" 2>&1; then echo 'Expected failure was accepted.' >&2; exit 1; fi; }
wait_phase() {
    local expected=$1 deadline=$((SECONDS + 12))
    while [[ ! -f "$HOME/.deployments/$app/deploy.lock/phase" ]] || [[ "$(cat "$HOME/.deployments/$app/deploy.lock/phase")" != "$expected" ]]; do
        (( SECONDS < deadline )) || { cat "$HOME/.deployments/$app/deploy.lock/supervisor.log" >&2; return 1; }
        sleep 1
    done
}

setup_fixture
before_env=$(sha256sum "$HOME/$app/.env")
run_recovery prepare
run_recovery start-supervisor
run_recovery wait-serving
[[ ! -e "$HOME/$app/storage/framework/down" ]]
run_recovery mark-verified
run_recovery wait-supervisor
run_recovery release-success
[[ ! -e "$HOME/.deployments/$app/deploy.lock" ]]
[[ "$before_env" == "$(sha256sum "$HOME/$app/.env")" ]]
printf '%s\n\n' '* * * * * UNRELATED_CRON_CANARY' > "$fixture_root/expected-cron"
cat "$HOME/.deployments/$app/recovery/c765e4086fa2-35100840905-1.cron" >> "$fixture_root/expected-cron"
cmp -s "$FIXTURE_CRON" "$fixture_root/expected-cron"
[[ -z "$(find "$HOME/.deployments/$app/state" -mindepth 1 -print -quit)" ]]

setup_fixture
run_recovery prepare
run_recovery start-supervisor
run_recovery wait-serving
run_recovery cleanup
[[ -f "$HOME/$app/storage/framework/down" && ! -e "$HOME/.deployments/$app/deploy.lock" ]]
[[ $(wc -l < "$FIXTURE_CRON") == 2 ]]

setup_fixture
run_recovery prepare
PHR_ORPHAN_VERIFY_LEASE_SECONDS=2 run_recovery start-supervisor
wait_phase maintenance-restored
run_recovery cleanup
[[ -f "$HOME/$app/storage/framework/down" && ! -e "$HOME/.deployments/$app/deploy.lock" ]]

setup_fixture
run_recovery prepare
FAIL_UP=true run_recovery start-supervisor || true
wait_phase maintenance-restored
run_recovery cleanup
[[ -f "$HOME/$app/storage/framework/down" ]]

setup_fixture
run_recovery prepare
PHR_ORPHAN_PAUSE_BEFORE_ADOPTION_SECONDS=2 run_recovery start-supervisor &
starter=$!
while [[ ! -d "$HOME/.deployments/$app/deploy.lock/operation" ]]; do sleep 0.1; done
run_recovery cleanup
wait "$starter" || true
[[ -f "$HOME/$app/storage/framework/down" && ! -e "$HOME/.deployments/$app/deploy.lock" ]]

setup_fixture
run_recovery prepare
run_recovery start-supervisor
run_recovery wait-serving
run_recovery mark-verified
run_recovery wait-supervisor
printf '%s\n' ENV_DRIFT_AFTER_VERIFICATION >> "$HOME/$app/.env"
expect_failure run_recovery release-success
run_recovery cleanup
[[ -f "$HOME/$app/storage/framework/down" && ! -e "$HOME/.deployments/$app/deploy.lock" ]]
[[ $(wc -l < "$FIXTURE_CRON") == 2 ]]

setup_fixture
HANG_MAINTENANCE=true PHR_ORPHAN_MAINTENANCE_TIMEOUT_SECONDS=1 expect_failure run_recovery prepare
run_recovery cleanup
[[ -f "$HOME/$app/storage/framework/down" && ! -e "$HOME/.deployments/$app/deploy.lock" ]]

# The fixture owns/reaps the real setsid child, making SIGKILL and descendant
# liveness checks deterministic without relying on the container's PID 1.
launch_owned_supervisor() {
    local operation="$HOME/.deployments/$app/deploy.lock/operation"
    mkdir "$operation"
    printf '%s\n' "$owner" > "$operation/owner"
    printf '%s\n' supervisor > "$operation/role"
    printf '%s\n' startup > "$operation/state"
    /usr/bin/setsid bash "$HOME/.deployments/$app/deploy.lock/recover-phr-orphan.sh" \
        supervise "$app" "$release" "$commit" "$php_bin" "$owner" \
        > "$HOME/.deployments/$app/deploy.lock/supervisor.log" 2>&1 &
    fixture_supervisor=$!
}
setup_fixture
run_recovery prepare
launch_owned_supervisor
wait_phase serving
kill -KILL "$fixture_supervisor"
wait "$fixture_supervisor" 2>/dev/null || true
run_recovery cleanup
[[ -f "$HOME/$app/storage/framework/down" && ! -e "$HOME/.deployments/$app/deploy.lock" ]]

setup_fixture
run_recovery prepare
export DELAY_UP=true DELAY_UP_STARTED="$fixture_root/delayed-up-started"
launch_owned_supervisor
unset DELAY_UP
while [[ ! -f "$DELAY_UP_STARTED" ]]; do sleep 0.1; done
kill -KILL "$fixture_supervisor"
wait "$fixture_supervisor" 2>/dev/null || true
PHR_ORPHAN_CLEANUP_WAIT_SECONDS=1 expect_failure run_recovery cleanup
[[ -d "$HOME/.deployments/$app/deploy.lock/operation" ]]
sleep 3
run_recovery cleanup
unset DELAY_UP_STARTED
[[ -f "$HOME/$app/storage/framework/down" && ! -e "$HOME/.deployments/$app/deploy.lock" ]]

setup_fixture
mkdir "$HOME/.deployments/$app/state/foreign"
expect_failure run_recovery prepare
[[ ! -e "$HOME/.deployments/$app/deploy.lock" ]]
setup_fixture
run_recovery prepare
printf '%s\n' 'ENV_DRIFT_CANARY' >> "$HOME/$app/.env"
run_recovery start-supervisor || true
wait_phase maintenance-restored
run_recovery cleanup
[[ -f "$HOME/$app/storage/framework/down" ]]
setup_fixture
mkdir -p "$PHR_ORPHAN_PROC_ROOT/4242"
printf 'Uid:\t%s\t%s\t%s\t%s\n' "$(id -u)" "$(id -u)" "$(id -u)" "$(id -u)" > "$PHR_ORPHAN_PROC_ROOT/4242/status"
printf '%s\0%s\0' "$php_bin" artisan > "$PHR_ORPHAN_PROC_ROOT/4242/cmdline"
ln -s "$php_bin" "$PHR_ORPHAN_PROC_ROOT/4242/exe"
ln -s "$HOME/$app" "$PHR_ORPHAN_PROC_ROOT/4242/cwd"
expect_failure run_recovery prepare
[[ ! -e "$HOME/.deployments/$app/deploy.lock" ]]
for executable in php8.5 php8.4 custom-interpreter; do
    cp "$php_bin" "$fixture_root/bin/$executable"
    rm -f "$PHR_ORPHAN_PROC_ROOT/4242/exe"
    ln -s "$fixture_root/bin/$executable" "$PHR_ORPHAN_PROC_ROOT/4242/exe"
    printf '%s\0%s\0' "$fixture_root/bin/$executable" artisan > "$PHR_ORPHAN_PROC_ROOT/4242/cmdline"
    expect_failure run_recovery prepare
    [[ ! -e "$HOME/.deployments/$app/deploy.lock" ]]
done
rm -f "$PHR_ORPHAN_PROC_ROOT/4242/cmdline"
expect_failure run_recovery prepare
[[ ! -e "$HOME/.deployments/$app/deploy.lock" ]]
rm -rf "$PHR_ORPHAN_PROC_ROOT/4242"
setup_fixture
printf '%s\n' 'MALICIOUS_CRON_CANARY' >> "$HOME/.deployments/$app/recovery/c765e4086fa2-35100840905-1.cron"
expect_failure run_recovery prepare
[[ ! -e "$HOME/.deployments/$app/deploy.lock" ]]
setup_fixture
mkdir "$HOME/.deployments/$app/deploy.lock"
printf '%s\n' 'foreign-owner' > "$HOME/.deployments/$app/deploy.lock/owner"
expect_failure run_recovery cleanup
[[ $(cat "$HOME/.deployments/$app/deploy.lock/owner") == foreign-owner ]]
echo 'PHR exact orphan recovery tests passed.'
