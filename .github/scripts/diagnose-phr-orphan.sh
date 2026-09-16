#!/usr/bin/env bash

# Intentionally read-only. Do not bootstrap Laravel, inspect data, or print raw values.
set -euo pipefail
trap 'echo "PHR diagnostic failed; details REDACTED." >&2' ERR
readonly app=phr-laravel
readonly failed=c765e4086fa2-35100840905-1
readonly selected=legacy-20260916T131957Z-c765e4086fa2-35100840905-1
readonly commit=153ddfdcb6e8180bf1d1291aa74202e13a92681e
readonly stable="$HOME/$app"
readonly control="$HOME/.deployments/$app"
readonly snapshot="$control/recovery/$failed.cron"

report_path() {
    local label=$1 path=$2 kind=missing
    if [[ -L "$path" ]]; then kind=symlink
    elif [[ -d "$path" ]]; then kind=directory
    elif [[ -f "$path" ]]; then kind=file
    elif [[ -e "$path" ]]; then kind=other
    fi
    printf '%s=%s\n' "$label" "$kind"
}

for suffix in .deployments ".deployments/$app" ".deployments/$app/shared"; do
    [[ -d "$HOME/$suffix" && ! -L "$HOME/$suffix" ]]
done
[[ -d "$stable" && ! -L "$stable" ]]
[[ -f "$stable/.deploy-release" && ! -L "$stable/.deploy-release" ]]
mapfile -t releases < <(sed -n 's/^release=//p' "$stable/.deploy-release")
mapfile -t commits < <(sed -n 's/^commit=//p' "$stable/.deploy-release")
[[ ${#releases[@]} == 1 && ${releases[0]} == "$selected" ]]
[[ ${#commits[@]} == 1 && ${commits[0]} == "$commit" ]]
echo 'selected_identity=exact_expected'
for path in storage public/ohif; do
    expected="$control/shared/$path"
    [[ -d "$expected" && ! -L "$expected" ]]
    [[ -L "$stable/$path" && $(readlink -e "$stable/$path") == "$(readlink -e "$expected")" ]]
done
echo 'persistent_links=exact_shared'
report_path marker "$stable/storage/framework/down"
report_path lock "$control/deploy.lock"
report_path failed_transaction "$control/transactions/$failed"
report_path failed_candidate "$control/releases/$failed"
report_path cron_snapshot "$snapshot"
for directory in transactions releases recovery; do
    [[ -d "$control/$directory" && ! -L "$control/$directory" ]]
    entries=0
    while IFS= read -r -d '' ignored; do entries=$((entries + 1)); done < <(find "$control/$directory" -mindepth 1 -maxdepth 1 -print0)
    printf '%s_entries=%d\n' "$directory" "$entries"
done
if [[ -f "$snapshot" && ! -L "$snapshot" ]]; then
    printf 'snapshot_scheduler_markers=%s\n' "$(grep -Fc '# JOB:phr-laravel-scheduler' "$snapshot" || true)"
    printf 'snapshot_worker_markers=%s\n' "$(grep -Fc '# JOB:phr-laravel-queue-worker' "$snapshot" || true)"
    printf 'snapshot_lines=%s\n' "$(awk 'END {print NR}' "$snapshot")"
fi
cron_status=0
cron=$(crontab -l 2>/dev/null) || cron_status=$?
printf 'crontab_read_status=%d\n' "$cron_status"
printf 'current_scheduler_markers=%s\n' "$(grep -Fc '# JOB:phr-laravel-scheduler' <<<"$cron" || true)"
printf 'current_worker_markers=%s\n' "$(grep -Fc '# JOB:phr-laravel-queue-worker' <<<"$cron" || true)"
process_status=0
processes=$(/usr/bin/pgrep -c -u "$(id -u)" -f '[p]hr:uptime:run-(scheduler|worker)' 2>/dev/null) || process_status=$?
[[ "$process_status" == 0 || "$process_status" == 1 ]]
printf 'phr_wrapper_processes=%s\n' "$processes"
echo 'PHR read-only diagnostic completed; no raw contents emitted.'
