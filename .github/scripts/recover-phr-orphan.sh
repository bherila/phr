#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 6 ]]; then
    echo 'usage: recover-phr-orphan.sh <command> <app> <release> <commit> <php> <owner>' >&2
    exit 2
fi

readonly command_name="$1"
readonly app="$2"
readonly expected_release="$3"
readonly expected_commit="$4"
readonly php_bin="$5"
readonly recovery_owner="$6"
readonly stable="$HOME/$app"
readonly control="$HOME/.deployments/$app"
readonly releases="$control/releases"
readonly state_root="$control/state"
readonly lock="$control/deploy.lock"
readonly metadata="$stable/.deploy-release"
readonly shared_storage="$control/shared/storage"
readonly maintenance_dir="$stable/storage/framework"
readonly maintenance_marker="$maintenance_dir/down"
readonly marker_snapshot="$lock/original-maintenance-marker"
readonly cron_original="$control/recovery/c765e4086fa2-35100840905-1.cron"
readonly cron_snapshot="$lock/original-owned-cron"
readonly environment_snapshot="$lock/original-environment-sha256"
readonly supervisor_script="$lock/recover-phr-orphan.sh"
readonly operation="$lock/operation"
readonly abort_request="$operation/abort-requested"
readonly proc_root="${PHR_ORPHAN_PROC_ROOT:-/proc}"
readonly expected_uid="${PHR_ORPHAN_UID:-$(id -u)}"
readonly timeout_bin="${PHR_ORPHAN_TIMEOUT_BIN:-/usr/bin/timeout}"
supervisor_verified=false

[[ "$command_name" =~ ^(inspect|prepare|start-supervisor|supervise|wait-serving|mark-verified|wait-supervisor|release-success|cleanup)$ ]] || exit 2
[[ "$app" == phr-laravel ]]
[[ "$expected_release" == legacy-20260916T131957Z-c765e4086fa2-35100840905-1 ]]
[[ "$expected_commit" == 153ddfdcb6e8180bf1d1291aa74202e13a92681e ]]
case "$php_bin" in /*) ;; *) exit 2 ;; esac
case "$timeout_bin" in /*) ;; *) exit 2 ;; esac
[[ "$recovery_owner" =~ ^orphan-c765e408-[0-9]+-[0-9]+$ ]]

path_kind() {
    if [[ -L "$1" ]]; then printf symlink
    elif [[ -d "$1" ]]; then printf directory
    elif [[ -f "$1" ]]; then printf file
    elif [[ -e "$1" ]]; then printf other
    else printf missing
    fi
}

state_is_empty() {
    local entry
    [[ -d "$state_root" && ! -L "$state_root" ]] || return 1
    if ! entry=$(find "$state_root" -mindepth 1 -maxdepth 1 -print -quit); then
        return 1
    fi
    [[ -z "$entry" ]]
}

exact_identity() {
    [[ -d "$stable" && ! -L "$stable" \
        && -f "$metadata" && ! -L "$metadata" \
        && "$(grep -c '^release=' "$metadata")" == 1 \
        && "$(grep -c '^commit=' "$metadata")" == 1 \
        && "$(sed -n 's/^release=//p' "$metadata")" == "$expected_release" \
        && "$(sed -n 's/^commit=//p' "$metadata")" == "$expected_commit" ]]
}

storage_is_managed() {
    local deployments_real control_real shared_real storage_real
    [[ -d "$HOME/.deployments" && ! -L "$HOME/.deployments" \
        && -d "$control" && ! -L "$control" \
        && -d "$control/shared" && ! -L "$control/shared" \
        && -L "$stable/storage" \
        && -d "$shared_storage" && ! -L "$shared_storage" \
        && -d "$maintenance_dir" && ! -L "$maintenance_dir" ]] || return 1
    deployments_real=$(readlink -f "$HOME/.deployments") || return 1
    control_real=$(readlink -f "$control") || return 1
    shared_real=$(readlink -f "$control/shared") || return 1
    storage_real=$(readlink -f "$shared_storage") || return 1
    [[ "$control_real" == "$deployments_real/$app" \
        && "$shared_real" == "$control_real/shared" \
        && "$storage_real" == "$shared_real/storage" \
        && "$(readlink -f "$stable/storage")" == "$storage_real" \
        && -L "$stable/public/ohif" && -d "$control/shared/public" && ! -L "$control/shared/public" \
        && -d "$control/shared/public/ohif" && ! -L "$control/shared/public/ohif" \
        && "$(readlink -f "$stable/public/ohif")" == "$shared_real/public/ohif" ]]
}

environment_is_unchanged() {
    local current
    owned_lock || return 1
    [[ -f "$stable/.env" && ! -L "$stable/.env" && -r "$stable/.env" \
        && -f "$environment_snapshot" && ! -L "$environment_snapshot" ]] || return 1
    current=$(sha256sum "$stable/.env" | awk '{print $1}') || return 1
    [[ "$current" == "$(cat "$environment_snapshot")" ]]
}

owned_lock() {
    [[ -d "$lock" && ! -L "$lock" \
        && -f "$lock/owner" && ! -L "$lock/owner" \
        && "$(cat "$lock/owner")" == "$recovery_owner" ]]
}

owned_operation() {
    [[ -d "$operation" && ! -L "$operation" \
        && -f "$operation/owner" && ! -L "$operation/owner" \
        && "$(cat "$operation/owner")" == "$recovery_owner" ]]
}

supervisor_operation() {
    owned_operation || return 1
    [[ -f "$operation/role" && ! -L "$operation/role" \
        && "$(cat "$operation/role")" == supervisor \
        && -f "$operation/state" && ! -L "$operation/state" ]] || return 1
    case "$(cat "$operation/state")" in startup | adopted) ;; *) return 1 ;; esac
}

valid_marker() {
    local marker="$1"
    [[ -f "$marker" && ! -L "$marker" ]] || return 1
    "$php_bin" -d memory_limit=1G -d display_errors=0 -d log_errors=0 -r '
        $data = json_decode((string) file_get_contents($argv[1]), true);
        exit(is_array($data) && ($data["status"] ?? null) === 503 ? 0 : 1);
    ' "$marker"
}

require_file_maintenance() {
    (cd "$stable" && "$php_bin" -d memory_limit=1G -d display_errors=0 -d log_errors=0 -r '
        try {
            require "vendor/autoload.php";
            $app = require "bootstrap/app.php";
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $maintenance = $app->maintenanceMode();
            exit($maintenance instanceof Illuminate\Foundation\FileBasedMaintenanceMode
                && $maintenance->active() ? 0 : 1);
        } catch (Throwable) { exit(1); }
    ')
}

no_phr_cron() {
    local contents
    contents=$("$timeout_bin" --kill-after=10s 30s crontab -l 2>/dev/null) || return 1
    ! grep -E "^[[:space:]]*[^#].*(phr-laravel|phr:uptime:run-|# JOB:phr-laravel)" <<<"$contents" >/dev/null
}

no_phr_processes() {
    local stable_real process process_uid process_cwd argument artisan_path artisan_real pgrep_status=0
    /usr/bin/pgrep -u "$(id -u)" -f '[p]hr:uptime:run-(scheduler|worker)' >/dev/null || pgrep_status=$?
    [[ "$pgrep_status" == 1 ]] || return 1
    local -a arguments
    stable_real=$(readlink -f "$stable") || return 1
    [[ -n "$stable_real" && -d "$proc_root" && "$expected_uid" =~ ^[0-9]+$ ]] || return 1
    for process in "$proc_root"/[0-9]*; do
        [[ -d "$process" ]] || continue
        if [[ ! -r "$process/status" ]] || ! process_uid=$(awk '/^Uid:/ { print $2; exit }' "$process/status" 2>/dev/null); then
            [[ ! -d "$process" ]] && continue
            return 1
        fi
        [[ "$process_uid" =~ ^[0-9]+$ ]] || return 1
        [[ "$process_uid" == "$expected_uid" ]] || continue
        if [[ ! -r "$process/cmdline" ]] || ! mapfile -d '' -t arguments <"$process/cmdline"; then
            [[ ! -d "$process" ]] && continue
            return 1
        fi
        if [[ ${#arguments[@]} == 0 ]]; then
            # Zombies cannot execute work; all other live empty argv is unknown.
            [[ "$(awk '/^State:/ {print $2; exit}' "$process/status")" == Z ]] && continue
            [[ ! -d "$process" ]] && continue
            return 1
        fi
        if ! process_cwd=$(readlink -e "$process/cwd" 2>/dev/null); then
            [[ ! -d "$process" ]] && continue
            return 1
        fi
        for argument in "${arguments[@]}"; do
            case "$argument" in
                artisan | */artisan)
                    if [[ "$argument" == /* ]]; then
                        artisan_path="$argument"
                    else
                        [[ -n "$process_cwd" ]] || return 1
                        artisan_path="$process_cwd/$argument"
                    fi
                    artisan_real=$(readlink -f -- "$artisan_path" 2>/dev/null || true)
                    [[ -n "$artisan_real" ]] || return 1
                    [[ "$artisan_real" != "$stable_real/artisan" ]] || return 1
                    ;;
            esac
        done
    done
}

write_lock_value() {
    local name="$1" value="$2" temporary
    owned_lock
    temporary=$(mktemp "$lock/.${name}.XXXXXX")
    printf '%s\n' "$value" >"$temporary"
    chmod 600 "$temporary"
    mv -f -- "$temporary" "$lock/$name"
}

write_operation_value() {
    local name="$1" value="$2" temporary
    owned_lock
    owned_operation
    temporary=$(mktemp "$operation/.${name}.XXXXXX")
    printf '%s\n' "$value" >"$temporary"
    chmod 600 "$temporary"
    mv -f -- "$temporary" "$operation/$name"
}

restore_marker() {
    local maintenance_real shared_real temporary
    owned_lock || return 1
    exact_identity || return 1
    state_is_empty || return 1
    storage_is_managed || return 1
    valid_marker "$marker_snapshot" || return 1
    maintenance_real=$(readlink -f "$maintenance_dir") || return 1
    shared_real=$(readlink -f "$shared_storage") || return 1
    case "$maintenance_real/" in "$shared_real"/*) ;; *) return 1 ;; esac
    temporary=$(mktemp "$maintenance_real/.down.orphan.XXXXXX") || return 1
    cp -- "$marker_snapshot" "$temporary" || { rm -f -- "$temporary"; return 1; }
    chmod 600 "$temporary" || { rm -f -- "$temporary"; return 1; }
    mv -f -- "$temporary" "$maintenance_marker" || { rm -f -- "$temporary"; return 1; }
    valid_marker "$maintenance_marker" && cmp -s "$maintenance_marker" "$marker_snapshot"
}

release_owned_lock() {
    local unlock="$control/.unlock-$recovery_owner"
    owned_lock || return 1
    [[ ! -e "$unlock" && ! -L "$unlock" ]] || return 1
    # Ownership and destination safety are fully proved before this boundary.
    # A successful same-filesystem rename releases the canonical lock; cleanup
    # of the uniquely named evidence directory is deliberately non-failing.
    mv -T "$lock" "$unlock"
    rm -rf -- "$unlock" || echo 'Released the canonical lock; retained unlock evidence could not be removed.' >&2
    return 0
}

inspect() {
    local release_match=no commit_match=no state_entries=unavailable inventory
    if [[ -f "$metadata" && ! -L "$metadata" ]]; then
        [[ "$(sed -n 's/^release=//p' "$metadata")" == "$expected_release" ]] && release_match=yes
        [[ "$(sed -n 's/^commit=//p' "$metadata")" == "$expected_commit" ]] && commit_match=yes
    fi
    if [[ -d "$state_root" && ! -L "$state_root" ]]; then
        if inventory=$(find "$state_root" -mindepth 1 -maxdepth 1 -printf .); then
            state_entries=${#inventory}
        else
            state_entries=error
        fi
    fi
    printf 'orphan_state stable=%s metadata=%s release_match=%s commit_match=%s state_root=%s state_entries=%s lock=%s\n' \
        "$(path_kind "$stable")" "$(path_kind "$metadata")" "$release_match" "$commit_match" \
        "$(path_kind "$state_root")" "$state_entries" "$(path_kind "$lock")"
}


# Accept only the exact two known legacy installer lines, never arbitrary cron.
valid_owned_cron() {
    local source=$1 scheduler worker line scheduler_count=0 worker_count=0
    [[ -f "$source" && ! -L "$source" && -r "$source" ]] || return 1
    scheduler="*/5 * * * * cd $HOME/$app && PHR_CRON_MEMORY_LIMIT=1G $php_bin -d memory_limit=1G artisan phr:uptime:run-scheduler >> /dev/null 2>&1 # JOB:phr-laravel-scheduler"
    worker="*/5 * * * * cd $HOME/$app && PHR_CRON_MEMORY_LIMIT=1G /usr/bin/flock -n $HOME/$app/storage/framework/phr-queue-worker.lock $php_bin -d memory_limit=1G artisan phr:uptime:run-worker >> /dev/null 2>&1 # JOB:phr-laravel-queue-worker"
    while IFS= read -r line || [[ -n "$line" ]]; do
        case "$line" in
            "$scheduler") scheduler_count=$((scheduler_count + 1)) ;;
            "$worker") worker_count=$((worker_count + 1)) ;;
            *) return 1 ;;
        esac
    done <"$source"
    [[ "$scheduler_count" == 1 && "$worker_count" == 1 ]]
}

with_cron_mutex() (
    local callback=$1
    [[ ! -L "$HOME/.crontab-deploy.lock" ]] || return 1
    [[ ! -e "$HOME/.crontab-deploy.lock" || -f "$HOME/.crontab-deploy.lock" ]] || return 1
    exec 9>>"$HOME/.crontab-deploy.lock"
    /usr/bin/flock -w 120 9 || return 1
    "$callback"
)

rewrite_owned_cron() {
    local mode=$1 current next
    owned_lock || return 1
    exact_identity || return 1
    storage_is_managed || return 1
    valid_owned_cron "$cron_snapshot" || return 1
    current=$(mktemp "$lock/.current-cron.XXXXXX") || return 1
    next=$(mktemp "$lock/.next-cron.XXXXXX") || return 1
    "$timeout_bin" --kill-after=10s 30s crontab -l >"$current" 2>/dev/null || return 1
    awk 'NR == FNR {owned[$0] = 1; next}
        $0 in owned {next}
        /phr-laravel|phr:uptime:run-/ {exit 1}
        {print}' "$cron_snapshot" "$current" >"$next" || return 1
    if [[ "$mode" == restore ]]; then
        cat "$cron_snapshot" >>"$next" || return 1
    fi
    "$timeout_bin" --kill-after=10s 30s crontab "$next" || return 1
    "$timeout_bin" --kill-after=10s 30s crontab -l 2>/dev/null | cmp -s - "$next" || return 1
    rm -f -- "$current" "$next"
}

restore_owned_cron_impl() { rewrite_owned_cron restore; }
pause_owned_cron_impl() { rewrite_owned_cron pause; }
restore_owned_cron() { with_cron_mutex restore_owned_cron_impl; }
pause_owned_cron() { with_cron_mutex pause_owned_cron_impl; }

require_restored_cron() {
    local current line count=0
    valid_owned_cron "$cron_snapshot" || return 1
    current=$("$timeout_bin" --kill-after=10s 30s crontab -l 2>/dev/null) || return 1
    while IFS= read -r line; do
        if grep -Fxq -- "$line" "$cron_snapshot"; then count=$((count + 1))
        elif [[ "$line" == *phr-laravel* || "$line" == *phr:uptime:run-* ]]; then return 1
        fi
    done <<<"$current"
    [[ "$count" == 2 ]]
}

read_only_boot_proof() {
    (cd "$stable" && "$timeout_bin" --signal=TERM --kill-after=10s 60s \
        "$php_bin" -d memory_limit=1G -d display_errors=0 -d log_errors=0 -r '
        try {
            require "vendor/autoload.php";
            $app = require "bootstrap/app.php";
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            if (!$app->environment("production") || config("queue.default") !== "database") { exit(1); }
            Illuminate\Support\Facades\DB::connection()->select("SELECT 1");
            echo "environment=production database_probe=ok";
        } catch (Throwable) { exit(1); }
    ')
}

prepare() {
    local database_status owner_tmp marker_tmp script_tmp
    [[ -x "$php_bin" && -x "$timeout_bin" \
        && -d "$control" && ! -L "$control" && -d "$releases" && ! -L "$releases" ]]
    exact_identity
    state_is_empty
    [[ ! -e "$lock" && ! -L "$lock" ]]
    storage_is_managed
    valid_marker "$maintenance_marker"
    valid_owned_cron "$cron_original"
    no_phr_cron
    no_phr_processes
    database_status=$(read_only_boot_proof)
    [[ "$database_status" == environment=production* ]]

    # mkdir is the ownership boundary. Death before owner publication leaves an
    # intentionally blocking ownerless lock; no later run may infer ownership.
    mkdir "$lock"
    chmod 700 "$lock"
    owner_tmp=$(mktemp "$lock/.owner.XXXXXX")
    printf '%s\n' "$recovery_owner" >"$owner_tmp"
    chmod 600 "$owner_tmp"
    mv -f -- "$owner_tmp" "$lock/owner"
    write_lock_value phase locked

    exact_identity
    state_is_empty
    owned_lock
    storage_is_managed
    [[ -f "$stable/.env" && ! -L "$stable/.env" && -r "$stable/.env" ]]
    write_lock_value original-environment-sha256 "$(sha256sum "$stable/.env" | awk '{print $1}')"
    valid_marker "$maintenance_marker"
    marker_tmp=$(mktemp "$lock/.maintenance-marker.XXXXXX")
    cp -- "$maintenance_marker" "$marker_tmp"
    chmod 600 "$marker_tmp"
    mv -f -- "$marker_tmp" "$marker_snapshot"
    valid_marker "$marker_snapshot"
    cmp -s "$maintenance_marker" "$marker_snapshot"
    valid_owned_cron "$cron_original"
    cp -- "$cron_original" "$cron_snapshot"
    chmod 600 "$cron_snapshot"
    valid_owned_cron "$cron_snapshot"
    cmp -s "$cron_original" "$cron_snapshot"
    [[ -f "${BASH_SOURCE[0]}" && ! -L "${BASH_SOURCE[0]}" ]]
    script_tmp=$(mktemp "$lock/.recovery-script.XXXXXX")
    cp -- "${BASH_SOURCE[0]}" "$script_tmp"
    chmod 600 "$script_tmp"
    mv -f -- "$script_tmp" "$supervisor_script"
    [[ -f "$supervisor_script" && ! -L "$supervisor_script" && -r "$supervisor_script" ]]

    # Inputs checked before mkdir are advisory until the canonical lock is
    # owned. Repeat all mutable quiescence and database proofs under that lock.
    no_phr_cron
    no_phr_processes
    database_status=$(read_only_boot_proof)
    [[ "$database_status" == environment=production* ]]

    require_file_maintenance

    exact_identity
    state_is_empty
    owned_lock
    storage_is_managed
    valid_marker "$maintenance_marker"
    cmp -s "$maintenance_marker" "$marker_snapshot"
    write_lock_value phase prepared
    echo 'Exact orphan release is prepared at its final path and remains in maintenance under the owned recovery lock.'
}

require_serving() {
    local timeout_seconds=${PHR_ORPHAN_POST_UP_TIMEOUT_SECONDS:-60}
    [[ "$timeout_seconds" =~ ^[1-9][0-9]*$ && -x "$timeout_bin" ]]
    (cd "$stable" && "$timeout_bin" --signal=TERM --kill-after=10s "${timeout_seconds}s" \
        "$php_bin" -d memory_limit=1G -d display_errors=0 -d log_errors=0 -r '
        try {
            require "vendor/autoload.php";
            $app = require "bootstrap/app.php";
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            exit($app->isDownForMaintenance() ? 1 : 0);
        } catch (Throwable) { exit(1); }
    ')
}

start_supervisor() {
    local supervisor_pid deadline
    owned_lock
    exact_identity
    state_is_empty
    [[ "$(cat "$lock/phase")" == prepared ]]
    [[ -f "$supervisor_script" && ! -L "$supervisor_script" && -r "$supervisor_script" ]]
    [[ ! -e "$operation" && ! -L "$operation" ]]
    mkdir "$operation"
    chmod 700 "$operation"
    printf '%s\n' "$recovery_owner" >"$operation/owner"
    chmod 600 "$operation/owner"
    write_operation_value role supervisor
    write_operation_value state startup
    owned_lock
    [[ "$(cat "$lock/phase")" == prepared ]]
    [[ ! -e "$abort_request" && ! -L "$abort_request" ]]
    nohup /bin/bash "$supervisor_script" supervise "$app" "$expected_release" "$expected_commit" "$php_bin" "$recovery_owner" \
        >"$lock/supervisor.log" 2>&1 </dev/null &
    supervisor_pid=$!
    write_lock_value supervisor-pid "$supervisor_pid"
    deadline=$((SECONDS + 10))
    while [[ ! -f "$operation/state" ]] || [[ "$(cat "$operation/state")" != adopted ]]; do
        kill -0 "$supervisor_pid" 2>/dev/null || return 1
        owned_operation
        (( SECONDS < deadline )) || return 1
        sleep 1
    done
    echo 'Durable orphan recovery supervisor started under the owned operation mutex.'
}

supervise() {
    local database_status deadline phase lease_seconds timeout_seconds
    supervisor_verified=false
    lease_seconds=${PHR_ORPHAN_VERIFY_LEASE_SECONDS:-900}
    timeout_seconds=${PHR_ORPHAN_POST_UP_TIMEOUT_SECONDS:-60}
    [[ "$lease_seconds" =~ ^[1-9][0-9]*$ \
        && "$timeout_seconds" =~ ^[1-9][0-9]*$ \
        && -x "$timeout_bin" ]]
    supervisor_exit() {
        local trap_status=$?
        trap - EXIT
        if [[ "$supervisor_verified" != true ]]; then
            if supervisor_operation && restore_marker && pause_owned_cron && no_phr_processes; then
                write_lock_value phase maintenance-restored
                rm -rf -- "$operation"
                echo 'Supervisor restored the exact maintenance marker after failure or verification timeout.' >&2
            else
                echo 'Supervisor could not prove maintenance restoration; operation mutex and recovery lock remain.' >&2
            fi
        fi
        exit "$trap_status"
    }
    trap supervisor_exit EXIT

    owned_lock
    supervisor_operation
    [[ -f "$operation/state" && ! -L "$operation/state" \
        && "$(cat "$operation/state")" == startup ]]
    if [[ "${PHR_ORPHAN_PAUSE_BEFORE_ADOPTION_SECONDS:-0}" =~ ^[0-9]+$ \
        && "${PHR_ORPHAN_PAUSE_BEFORE_ADOPTION_SECONDS:-0}" -gt 0 ]]; then
        sleep "${PHR_ORPHAN_PAUSE_BEFORE_ADOPTION_SECONDS}"
    fi
    [[ ! -e "$abort_request" && ! -L "$abort_request" ]]
    [[ "$(cat "$lock/phase")" == prepared ]]
    write_operation_value state adopted
    exact_identity
    state_is_empty
    storage_is_managed
    environment_is_unchanged
    [[ "$(cat "$lock/phase")" == prepared ]]
    valid_marker "$maintenance_marker"
    valid_marker "$marker_snapshot"
    cmp -s "$maintenance_marker" "$marker_snapshot"
    no_phr_cron
    no_phr_processes
    database_status=$(read_only_boot_proof)
    [[ "$database_status" == environment=production* ]]
    require_file_maintenance
    write_lock_value phase activating

    if [[ "${PHR_ORPHAN_PAUSE_BEFORE_UP_SECONDS:-0}" =~ ^[0-9]+$ \
        && "${PHR_ORPHAN_PAUSE_BEFORE_UP_SECONDS:-0}" -gt 0 ]]; then
        sleep "${PHR_ORPHAN_PAUSE_BEFORE_UP_SECONDS}"
    fi
    owned_lock
    [[ -d "$operation" && ! -L "$operation" ]]
    [[ ! -e "$abort_request" && ! -L "$abort_request" ]]
    [[ "$(cat "$lock/phase")" == activating ]]
    no_phr_cron
    no_phr_processes
    database_status=$(read_only_boot_proof)
    [[ "$database_status" == environment=production* ]]
    require_file_maintenance
    [[ ! -e "$abort_request" && ! -L "$abort_request" ]]
    [[ "$(cat "$lock/phase")" == activating ]]
    environment_is_unchanged
    (cd "$stable" && "$timeout_bin" --signal=TERM --kill-after=10s "${timeout_seconds}s" \
        "$php_bin" -d memory_limit=1G artisan up --no-ansi)
    [[ ! -e "$abort_request" && ! -L "$abort_request" ]]
    [[ "$(cat "$lock/phase")" == activating ]]
    require_serving
    write_lock_value phase serving

    deadline=$((SECONDS + lease_seconds))
    while true; do
        owned_lock
        [[ -d "$operation" && ! -L "$operation" ]]
        [[ ! -e "$abort_request" && ! -L "$abort_request" ]]
        phase=$(cat "$lock/phase")
        if [[ "$phase" == verified ]]; then
            supervisor_operation
            restore_owned_cron
            [[ ! -e "$abort_request" && ! -L "$abort_request" ]]
            [[ "$(cat "$lock/phase")" == verified ]]
            exact_identity
            environment_is_unchanged
            require_serving
            [[ ! -e "$abort_request" && ! -L "$abort_request" ]]
            [[ "$(cat "$lock/phase")" == verified ]]
            supervisor_verified=true
            rm -rf -- "$operation"
            trap - EXIT
            echo 'Supervisor observed verified production and released its operation mutex.'
            return 0
        fi
        [[ "$phase" == serving ]]
        (( SECONDS < deadline )) || return 1
        sleep 1
    done
}

wait_serving() {
    local deadline phase wait_seconds=${PHR_ORPHAN_SERVING_WAIT_SECONDS:-60}
    [[ "$wait_seconds" =~ ^[1-9][0-9]*$ ]]
    deadline=$((SECONDS + wait_seconds))
    while true; do
        owned_lock
        phase=$(cat "$lock/phase")
        [[ "$phase" != maintenance-restored ]] || return 1
        if [[ "$phase" == serving ]]; then
            require_serving
            return 0
        fi
        [[ -d "$operation" && ! -L "$operation" ]]
        (( SECONDS < deadline )) || return 1
        sleep 1
    done
}

mark_verified() {
    exact_identity
    state_is_empty
    owned_lock
    [[ -d "$operation" && ! -L "$operation" ]]
    [[ ! -e "$abort_request" && ! -L "$abort_request" ]]
    [[ "$(cat "$lock/phase")" == serving ]]
    [[ ! -e "$maintenance_marker" && ! -L "$maintenance_marker" ]]
    require_serving
    write_lock_value phase verified
    echo 'Full verification acknowledged under the active supervisor mutex.'
}

wait_supervisor() {
    local deadline wait_seconds=${PHR_ORPHAN_SUPERVISOR_WAIT_SECONDS:-360}
    [[ "$wait_seconds" =~ ^[1-9][0-9]*$ ]]
    deadline=$((SECONDS + wait_seconds))
    while [[ -e "$operation" || -L "$operation" ]]; do
        owned_lock
        (( SECONDS < deadline )) || return 1
        sleep 1
    done
    owned_lock
    [[ "$(cat "$lock/phase")" == verified ]]
}

release_success() {
    [[ -x "$php_bin" ]]
    exact_identity
    state_is_empty
    owned_lock
    storage_is_managed
    environment_is_unchanged
    [[ "$(cat "$lock/phase")" == verified ]]
    [[ ! -e "$operation" && ! -L "$operation" ]]
    [[ ! -e "$maintenance_marker" && ! -L "$maintenance_marker" ]]
    require_serving
    require_restored_cron
    write_lock_value phase verified
    release_owned_lock
    echo 'Verified exact orphan release is serving; its exact owned recovery lock was released.'
}

cleanup() {
    local deadline temporary wait_seconds=${PHR_ORPHAN_CLEANUP_WAIT_SECONDS:-360} phase
    if [[ ! -e "$lock" && ! -L "$lock" ]]; then
        echo 'No recovery lock was acquired; cleanup has no authorized mutation.'
        return 0
    fi
    owned_lock
    [[ "$wait_seconds" =~ ^[1-9][0-9]*$ ]]
    if [[ -d "$operation" && ! -L "$operation" ]]; then
        owned_operation
        phase=$(cat "$lock/phase")
        case "$phase" in
            prepared | activating | serving)
                temporary=$(mktemp "$operation/.abort-requested.XXXXXX")
                printf '%s\n' "$recovery_owner" >"$temporary"
                chmod 600 "$temporary"
                mv -f -- "$temporary" "$abort_request"
                write_lock_value phase aborting
                ;;
            aborting | maintenance-restored | verified) ;;
            *)
                echo "Recovery supervisor has an unexpected phase; retaining its operation mutex and canonical lock." >&2
                return 1
                ;;
        esac
    fi
    deadline=$((SECONDS + wait_seconds))
    while [[ -e "$operation" || -L "$operation" ]]; do
        owned_lock
        (( SECONDS < deadline )) || {
            echo 'Recovery supervisor is still active; retaining the operation mutex and canonical lock.' >&2
            return 1
        }
        sleep 1
    done
    phase=$(cat "$lock/phase")
    if [[ "$phase" == verified ]]; then
        release_success
        return
    fi
    # Win the same atomic mutex used by startup before restoration/unlock. A
    # starter that raced the preceding absence check blocks this mkdir; a
    # delayed starter cannot publish while this cleanup mutex remains inside
    # the canonical lock through the owner-checked rename.
    mkdir "$operation"
    chmod 700 "$operation"
    printf '%s\n' "$recovery_owner" >"$operation/owner"
    chmod 600 "$operation/owner"
    write_operation_value role cleanup
    write_operation_value state cleanup
    restore_marker
    pause_owned_cron
    no_phr_processes
    write_lock_value phase maintenance-restored
    release_owned_lock
    echo 'Exact original maintenance marker was restored without Laravel bootstrap; exact owned recovery lock was released.'
}

case "$command_name" in
    inspect) inspect ;;
    prepare) prepare ;;
    start-supervisor) start_supervisor ;;
    supervise) supervise ;;
    wait-serving) wait_serving ;;
    mark-verified) mark_verified ;;
    wait-supervisor) wait_supervisor ;;
    release-success) release_success ;;
    cleanup) cleanup ;;
esac
