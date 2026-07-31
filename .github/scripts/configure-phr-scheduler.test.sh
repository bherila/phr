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
                    ;;
                config:show)
                    printf '%s\n' 'queue.default ..................................................... database'
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
    '*/10 * * * * old-phr-command # JOB:phr-laravel-scheduler' \
    '*/15 * * * * duplicate-old-phr-command # JOB:phr-laravel-scheduler' \
    >"$fake_crontab"
cp "$fake_crontab" "$original_crontab"

export FAKE_CRONTAB_FILE="$fake_crontab"
export HOME="$fake_home"
export PHR_CRON_APP_DIR="$fake_app"
export PHR_CRON_PHP_BIN="$helper"
export PHR_CRONTAB_BIN="$helper"

bash "$installer" >/dev/null
bash "$installer" >/dev/null

readonly expected_line="*/5 * * * * cd ${fake_app} && ${helper} artisan schedule:run >> /dev/null 2>&1 # JOB:phr-laravel-scheduler"

[[ "$(grep -Fc '# JOB:phr-laravel-scheduler' "$fake_crontab")" == '1' ]]
grep -Fqx "$expected_line" "$fake_crontab"
grep -Fqx 'MAILTO=account-owner@example.com' "$fake_crontab"
grep -Fqx '17 2 * * * /home/test-user/other-app/backup # JOB:other-app-backup' "$fake_crontab"

cp "$fake_crontab" "$original_crontab"
export FAKE_SCHEDULE_MISSING_EXPORT_PURGE=true
if bash "$installer" >/dev/null 2>&1; then
    echo 'Expected missing schedule verification to fail.' >&2
    exit 1
fi
cmp -s "$original_crontab" "$fake_crontab"

echo 'configure-phr-scheduler test passed'
