#!/usr/bin/env bash

set -euo pipefail

readonly job_name='phr-laravel-scheduler'
readonly job_tag="# JOB:${job_name}"
readonly cron_schedule='*/5 * * * *'
readonly php_bin="${PHR_CRON_PHP_BIN:-/opt/cpanel/ea-php85/root/usr/bin/php}"
readonly app_dir="${PHR_CRON_APP_DIR:-${HOME}/phr-laravel}"
readonly crontab_bin="${PHR_CRONTAB_BIN:-crontab}"
readonly cron_line="${cron_schedule} cd ${app_dir} && ${php_bin} artisan schedule:run >> /dev/null 2>&1 ${job_tag}"

for value in "$php_bin" "$app_dir"; do
    if [[ ! "$value" =~ ^/[A-Za-z0-9._/-]+$ ]]; then
        echo "Refusing to install cron with an unsafe path: ${value}" >&2
        exit 1
    fi
done

if ! command -v "$crontab_bin" >/dev/null 2>&1; then
    echo "The cPanel account does not expose the crontab command." >&2
    exit 1
fi

existing_file="$(mktemp)"
read_error_file="$(mktemp)"
next_file="$(mktemp)"
installed_file="$(mktemp)"
untagged_before_file="$(mktemp)"
untagged_after_file="$(mktemp)"

cleanup() {
    rm -f \
        "$existing_file" \
        "$read_error_file" \
        "$next_file" \
        "$installed_file" \
        "$untagged_before_file" \
        "$untagged_after_file"
}
trap cleanup EXIT

had_crontab=true
if ! "$crontab_bin" -l >"$existing_file" 2>"$read_error_file"; then
    if grep -Fqi 'no crontab for' "$read_error_file"; then
        had_crontab=false
        : >"$existing_file"
    else
        echo "Unable to read the existing cPanel account crontab:" >&2
        sed -n '1,20p' "$read_error_file" >&2
        exit 1
    fi
fi

# The stable job tag is the only selector. Every other account cron line is
# copied through unchanged, including jobs owned by other applications.
awk -v tag="$job_tag" 'index($0, tag) == 0' "$existing_file" >"$next_file"
printf '%s\n' "$cron_line" >>"$next_file"

"$crontab_bin" "$next_file"

rollback() {
    echo "Cron verification failed; restoring the previous cPanel account crontab." >&2
    if "$had_crontab"; then
        "$crontab_bin" "$existing_file"
    else
        "$crontab_bin" -r
    fi
}

if ! "$crontab_bin" -l >"$installed_file" 2>"$read_error_file"; then
    rollback
    echo "Unable to read back the installed cPanel account crontab." >&2
    exit 1
fi

awk -v tag="$job_tag" 'index($0, tag) == 0' "$existing_file" >"$untagged_before_file"
awk -v tag="$job_tag" 'index($0, tag) == 0' "$installed_file" >"$untagged_after_file"

if ! cmp -s "$untagged_before_file" "$untagged_after_file"; then
    rollback
    echo "An unrelated cPanel cron entry changed during installation." >&2
    exit 1
fi

tagged_count="$(grep -Fc "$job_tag" "$installed_file" || true)"
exact_count="$(grep -Fxc "$cron_line" "$installed_file" || true)"
if [[ "$tagged_count" != '1' || "$exact_count" != '1' ]]; then
    rollback
    echo "Expected exactly one canonical ${job_name} cron entry." >&2
    exit 1
fi

cd "$app_dir"
schedule_output="$("$php_bin" artisan schedule:list --no-ansi)"

for expected_command in \
    'genai:requeue-stale' \
    'phr:dicom:gc' \
    'phr:exports:purge'
do
    if ! grep -Fq "$expected_command" <<<"$schedule_output"; then
        rollback
        echo "Laravel schedule is missing ${expected_command}." >&2
        exit 1
    fi
done

echo "Installed ${job_name} without changing unrelated cPanel cron entries."
printf '%s\n' "$schedule_output"

echo "Production queue driver:"
"$php_bin" artisan config:show queue.default --no-ansi

queue_cron_count="$(
    awk '/artisan[[:space:]]+queue:(work|listen)/ { count++ } END { print count + 0 }' "$installed_file"
)"
if command -v pgrep >/dev/null 2>&1; then
    queue_process_count="$(pgrep -u "$(id -u)" -fc 'artisan (queue:work|queue:listen)' || true)"
else
    queue_process_count='unknown'
fi

echo "Queue audit: worker_processes=${queue_process_count}; worker_cron_entries=${queue_cron_count}."
