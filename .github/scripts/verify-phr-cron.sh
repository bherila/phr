#!/usr/bin/env bash
# Shared read-only cron assertion for live and finalized deployment diagnostics.
verify_phr_cron() {
    local crontab_file="$1" cron_memory_limit=1G spec job_name expected_line
    local scheduler_line="*/5 * * * * cd \"\$HOME/$DEPLOY_DIR\" && PHR_CRON_MEMORY_LIMIT=1G $DEPLOY_PHP_BINARY -d memory_limit=$cron_memory_limit artisan phr:uptime:run-scheduler >> /dev/null 2>&1 # JOB:phr-laravel-scheduler"
    local worker_line="*/5 * * * * cd \"\$HOME/$DEPLOY_DIR\" && PHR_CRON_MEMORY_LIMIT=1G /usr/bin/flock -n \"\$HOME/$DEPLOY_DIR/storage/framework/phr-queue-worker.lock\" $DEPLOY_PHP_BINARY -d memory_limit=$cron_memory_limit artisan phr:uptime:run-worker >> /dev/null 2>&1 # JOB:phr-laravel-queue-worker"
    
    for spec in "phr-laravel-scheduler|$scheduler_line" "phr-laravel-queue-worker|$worker_line"; do
        IFS='|' read -r job_name expected_line <<<"$spec"
        if [[ "$(grep -Ec "# JOB:${job_name}[[:space:]]*$" "$crontab_file")" != 1 \
            || "$(grep -Fxc "$expected_line" "$crontab_file" || true)" != 1 ]]; then
            echo "Production cron is missing the one canonical ${job_name} entry." >&2
            exit 1
        fi
    done
}
