#!/usr/bin/env bash

set -euo pipefail

# This file doubles as the fake crontab and PHP executable used by the test.
if [[ -n "${FAKE_CRONTAB_FILE:-}" && $# -gt 0 ]]; then
    case "$1" in
        -l)
            if [[ ! -f "$FAKE_CRONTAB_FILE" ]]; then
                echo 'no crontab for test-user' >&2
                exit 1
            fi
            exec sed -n '1,$p' "$FAKE_CRONTAB_FILE"
            ;;
        -r)
            rm -f "$FAKE_CRONTAB_FILE"
            exit 0
            ;;
        artisan)
            case "${2:-}" in
                schedule:list)
                    printf '%s\n' \
                        '*/5 * * * * php artisan genai:requeue-stale' \
                        '0 * * * * php artisan phr:dicom:gc'
                    if [[ "${FAKE_SCHEDULE_MISSING_EXPORT_PURGE:-false}" != 'true' ]]; then
                        printf '%s\n' '0 0 * * * php artisan phr:exports:purge'
                    fi
                    if [[ "${FAKE_SCHEDULE_MISSING_NATIVE_BACKUP_PURGE:-false}" != 'true' ]]; then
                        printf '%s\n' '0 0 * * * php artisan phr:native-backups:purge'
                    fi
                    ;;
                config:show)
                    printf '%s\n' "queue.default ..................................................... ${FAKE_QUEUE_DRIVER:-database}"
                    ;;
                phr:queue:audit)
                    printf '%s\n' "queue-audit driver=database retry_after=${FAKE_RETRY_AFTER:-3660} pending_total=0 failed_total=0"
                    ;;
                *)
                    echo "Unexpected fake Artisan command: $*" >&2
                    exit 1
                    ;;
            esac
            exit 0
            ;;
        *)
            exec cp "$1" "$FAKE_CRONTAB_FILE"
            ;;
    esac
fi

readonly script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly installer="${script_dir}/configure-phr-scheduler.sh"
readonly helper="${script_dir}/configure-phr-scheduler.test.sh"
readonly test_root="$(mktemp -d)"
readonly fake_home="${test_root}/home/test-user"
readonly fake_app="${fake_home}/phr-laravel"
readonly fake_crontab="${test_root}/crontab"
readonly original_crontab="${test_root}/original-crontab"

cleanup() {
    rm -rf "$test_root"
}
trap cleanup EXIT

mkdir -p "$fake_app"
printf '%s\n' \
    'MAILTO=account-owner@example.com' \
    '17 2 * * * /home/test-user/other-app/backup # JOB:other-app-backup' \
    '*/2 * * * * /home/test-user/other-app/artisan queue:work # JOB:other-app-worker' \
    '41 3 * * * /home/test-user/other-app/backup # JOB:phr-laravel-queue-worker-backup' \
    '*/10 * * * * old-phr-command # JOB:phr-laravel-scheduler' \
    '*/15 * * * * duplicate-old-phr-command # JOB:phr-laravel-scheduler' \
    '*/10 * * * * old-queue-command # JOB:phr-laravel-queue-worker' \
    '*/15 * * * * duplicate-old-queue-command # JOB:phr-laravel-queue-worker' \
    >"$fake_crontab"
cp "$fake_crontab" "$original_crontab"

export FAKE_CRONTAB_FILE="$fake_crontab"
export PHR_CRON_APP_DIR="$fake_app"
export PHR_CRON_PHP_BIN="$helper"
export PHR_CRONTAB_BIN="$helper"
export PHR_FLOCK_BIN="$helper"

bash "$installer" >/dev/null
bash "$installer" >/dev/null

readonly expected_scheduler_line="*/5 * * * * cd ${fake_app} && ${helper} artisan phr:uptime:run-scheduler >> /dev/null 2>&1 # JOB:phr-laravel-scheduler"
readonly expected_worker_line="*/5 * * * * cd ${fake_app} && ${helper} -n ${fake_app}/storage/framework/phr-queue-worker.lock ${helper} artisan phr:uptime:run-worker >> /dev/null 2>&1 # JOB:phr-laravel-queue-worker"

[[ "$(grep -Ec '# JOB:phr-laravel-scheduler[[:space:]]*$' "$fake_crontab")" == '1' ]]
[[ "$(grep -Ec '# JOB:phr-laravel-queue-worker[[:space:]]*$' "$fake_crontab")" == '1' ]]
grep -Fqx "$expected_scheduler_line" "$fake_crontab"
grep -Fqx "$expected_worker_line" "$fake_crontab"
grep -Fqx 'MAILTO=account-owner@example.com' "$fake_crontab"
grep -Fqx '17 2 * * * /home/test-user/other-app/backup # JOB:other-app-backup' "$fake_crontab"
grep -Fqx '*/2 * * * * /home/test-user/other-app/artisan queue:work # JOB:other-app-worker' "$fake_crontab"
grep -Fqx '41 3 * * * /home/test-user/other-app/backup # JOB:phr-laravel-queue-worker-backup' "$fake_crontab"

cp "$fake_crontab" "$original_crontab"
export FAKE_SCHEDULE_MISSING_EXPORT_PURGE=true
if bash "$installer" >/dev/null 2>&1; then
    echo 'Expected missing schedule verification to fail.' >&2
    exit 1
fi
cmp -s "$original_crontab" "$fake_crontab"

unset FAKE_SCHEDULE_MISSING_EXPORT_PURGE
export FAKE_SCHEDULE_MISSING_NATIVE_BACKUP_PURGE=true
if bash "$installer" >/dev/null 2>&1; then
    echo 'Expected missing native backup purge schedule verification to fail.' >&2
    exit 1
fi
cmp -s "$original_crontab" "$fake_crontab"

unset FAKE_SCHEDULE_MISSING_NATIVE_BACKUP_PURGE
export FAKE_QUEUE_DRIVER=redis
if bash "$installer" >/dev/null 2>&1; then
    echo 'Expected non-database queue verification to fail.' >&2
    exit 1
fi
cmp -s "$original_crontab" "$fake_crontab"

unset FAKE_QUEUE_DRIVER
export FAKE_RETRY_AFTER=3600
if bash "$installer" >/dev/null 2>&1; then
    echo 'Expected unsafe retry_after verification to fail.' >&2
    exit 1
fi
cmp -s "$original_crontab" "$fake_crontab"

echo 'configure-phr-scheduler test passed'
