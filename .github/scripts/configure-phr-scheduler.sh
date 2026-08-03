#!/usr/bin/env bash

set -euo pipefail

readonly scheduler_job_name='phr-laravel-scheduler'
readonly scheduler_job_tag="# JOB:${scheduler_job_name}"
readonly worker_job_name='phr-laravel-queue-worker'
readonly worker_job_tag="# JOB:${worker_job_name}"
readonly cron_schedule='*/5 * * * *'
readonly php_bin="${PHR_CRON_PHP_BIN:-/opt/cpanel/ea-php85/root/usr/bin/php}"
readonly app_dir="${PHR_CRON_APP_DIR:-${HOME}/phr-laravel}"
readonly crontab_bin="${PHR_CRONTAB_BIN:-crontab}"
readonly flock_bin="${PHR_FLOCK_BIN:-/usr/bin/flock}"
readonly worker_lock="${app_dir}/storage/framework/phr-queue-worker.lock"
readonly scheduler_cron_line="${cron_schedule} cd ${app_dir} && ${php_bin} artisan phr:uptime:run-scheduler >> /dev/null 2>&1 ${scheduler_job_tag}"
readonly worker_cron_line="${cron_schedule} cd ${app_dir} && ${flock_bin} -n ${worker_lock} ${php_bin} artisan phr:uptime:run-worker >> /dev/null 2>&1 ${worker_job_tag}"

for value in "$php_bin" "$app_dir" "$flock_bin"; do
    if [[ ! "$value" =~ ^/[A-Za-z0-9._/-]+$ ]]; then
        echo "Refusing to install cron with an unsafe path: ${value}" >&2
        exit 1
    fi
done

if [[ ! -x "$flock_bin" ]]; then
    echo "The cPanel account does not expose an executable flock binary at ${flock_bin}." >&2
    exit 1
fi

if ! command -v "$crontab_bin" >/dev/null 2>&1; then
    echo "The cPanel account does not expose the crontab command." >&2
    exit 1
fi

cd "$app_dir"
schedule_output="$("$php_bin" artisan schedule:list --no-ansi)"

for expected_command in \
    'genai:requeue-stale' \
    'phr:dicom:gc' \
    'phr:exports:purge' \
    'phr:native-backups:purge'
do
    if ! grep -Fq "$expected_command" <<<"$schedule_output"; then
        echo "Laravel schedule is missing ${expected_command}." >&2
        exit 1
    fi
done

queue_driver_output="$("$php_bin" artisan config:show queue.default --no-ansi)"
if ! grep -Eq 'queue\.default[[:space:].]+database[[:space:]]*$' <<<"$queue_driver_output"; then
    echo 'The managed queue worker requires queue.default=database.' >&2
    exit 1
fi

queue_audit_output="$("$php_bin" artisan phr:queue:audit --no-ansi)"
retry_after="$(sed -nE 's/^queue-audit .*retry_after=([0-9]+).*$/\1/p' <<<"$queue_audit_output")"
if [[ -z "$retry_after" || "$retry_after" -le 300 ]]; then
    echo 'The database queue retry_after must exceed the 300-second job timeout.' >&2
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

# The two stable job tags are the only selectors. Every other account cron
# line is copied through unchanged, including jobs owned by other applications.
awk -v scheduler_tag="$scheduler_job_tag" -v worker_tag="$worker_job_tag" \
    'function has_tag(line, tag, trimmed) {
        trimmed = line
        sub(/[[:space:]]+$/, "", trimmed)
        return substr(trimmed, length(trimmed) - length(tag) + 1) == tag
    }
    !has_tag($0, scheduler_tag) && !has_tag($0, worker_tag)' \
    "$existing_file" >"$next_file"
printf '%s\n' "$scheduler_cron_line" "$worker_cron_line" >>"$next_file"

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

awk -v scheduler_tag="$scheduler_job_tag" -v worker_tag="$worker_job_tag" \
    'function has_tag(line, tag, trimmed) {
        trimmed = line
        sub(/[[:space:]]+$/, "", trimmed)
        return substr(trimmed, length(trimmed) - length(tag) + 1) == tag
    }
    !has_tag($0, scheduler_tag) && !has_tag($0, worker_tag)' \
    "$existing_file" >"$untagged_before_file"
awk -v scheduler_tag="$scheduler_job_tag" -v worker_tag="$worker_job_tag" \
    'function has_tag(line, tag, trimmed) {
        trimmed = line
        sub(/[[:space:]]+$/, "", trimmed)
        return substr(trimmed, length(trimmed) - length(tag) + 1) == tag
    }
    !has_tag($0, scheduler_tag) && !has_tag($0, worker_tag)' \
    "$installed_file" >"$untagged_after_file"

if ! cmp -s "$untagged_before_file" "$untagged_after_file"; then
    rollback
    echo "An unrelated cPanel cron entry changed during installation." >&2
    exit 1
fi

for job_spec in \
    "${scheduler_job_name}|${scheduler_job_tag}|${scheduler_cron_line}" \
    "${worker_job_name}|${worker_job_tag}|${worker_cron_line}"
do
    IFS='|' read -r job_name job_tag cron_line <<<"$job_spec"
    tagged_count="$(awk -v tag="$job_tag" '
        function has_tag(line, expected, trimmed) {
            trimmed = line
            sub(/[[:space:]]+$/, "", trimmed)
            return substr(trimmed, length(trimmed) - length(expected) + 1) == expected
        }
        has_tag($0, tag) { count++ }
        END { print count + 0 }
    ' "$installed_file")"
    exact_count="$(grep -Fxc "$cron_line" "$installed_file" || true)"
    if [[ "$tagged_count" != '1' || "$exact_count" != '1' ]]; then
        rollback
        echo "Expected exactly one canonical ${job_name} cron entry." >&2
        exit 1
    fi
done

echo "Installed ${scheduler_job_name} and ${worker_job_name} without changing unrelated cPanel cron entries."
printf '%s\n' "$schedule_output"

echo "Production queue driver:"
printf '%s\n' "$queue_driver_output"

echo "Production queue backlog:"
printf '%s\n' "$queue_audit_output"

managed_worker_cron_count="$(awk -v tag="$worker_job_tag" '
    function has_tag(line, expected, trimmed) {
        trimmed = line
        sub(/[[:space:]]+$/, "", trimmed)
        return substr(trimmed, length(trimmed) - length(expected) + 1) == expected
    }
    has_tag($0, tag) { count++ }
    END { print count + 0 }
' "$installed_file")"
other_queue_cron_count="$(
    awk -v managed_tag="$worker_job_tag" \
        'function has_tag(line, tag, trimmed) {
            trimmed = line
            sub(/[[:space:]]+$/, "", trimmed)
            return substr(trimmed, length(trimmed) - length(tag) + 1) == tag
        }
        /artisan[[:space:]]+(queue:(work|listen)|phr:uptime:run-worker)/ && !has_tag($0, managed_tag) { count++ }
        END { print count + 0 }' \
        "$installed_file"
)"
if command -v pgrep >/dev/null 2>&1; then
    queue_process_count="$(pgrep -u "$(id -u)" -fc 'artisan (queue:work|queue:listen|phr:uptime:run-worker)' || true)"
else
    queue_process_count='unknown'
fi

echo "Queue audit: account_worker_processes=${queue_process_count}; managed_worker_cron_entries=${managed_worker_cron_count}; other_queue_cron_entries=${other_queue_cron_count}."
