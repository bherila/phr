#!/usr/bin/env bash
# One host session owns the complete, bounded resume and rollback operation.
set -euo pipefail
[[ $# == 7 && $1 =~ ^/[A-Za-z0-9/._-]+$ && -x $1 \
    && $2 =~ ^[a-f0-9]{12}-[0-9]+-[0-9]+$ && $3 =~ ^[a-f0-9]{40}$ \
    && $4 =~ ^resume-[a-f0-9]{32}$ && $5 =~ ^[0-9]{1,12}$ && $6 =~ ^[0-9]{1,12}$ && -d $7 && ! -L $7 ]] || exit 2
export RESUME_PHP=$1 RESUME_RELEASE=$2 RESUME_COMMIT=$3 RESUME_NONCE=$4 RESUME_INCIDENT_START=$5 RESUME_INCIDENT_END=$6 RESUME_SCRATCH=$7
export PHR_CRON_MEMORY_LIMIT=1G
RESUME_SCRIPTS=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
export RESUME_SCRIPTS
shared="$RESUME_SCRIPTS/shared"
lock="$HOME/.deployments/phr-laravel/deploy.lock"
export RESUME_INODE=- RESUME_CACHE_HASH=- RESUME_RECOVERY_HASH=-
export RESUME_MARKER_HASH=- RESUME_MARKER_MTIME=- RESUME_MARKER_GUARD=true
export RESUME_OAUTH_PRIVATE_HASH=- RESUME_OAUTH_PUBLIC_HASH=-
acquired=false published=false may_up=false completed=false
umask 077
ulimit -f 1024
one_record() {
    local path=$1 expected=$2
    [[ $(wc -c <"$path") -le 512 ]] && cmp -s "$path" <(printf '%s\n' "$expected")
}
state() {
    local mode=$1 maintenance=$2
    timeout --kill-after=2s 30s "$RESUME_PHP" -d memory_limit=1G -d display_errors=0 -d log_errors=0 \
        "$RESUME_SCRIPTS/resume-phr-state.php" "$RESUME_RELEASE" "$RESUME_COMMIT" "$RESUME_NONCE" \
        "$RESUME_INODE" "$mode" "$maintenance" "$RESUME_CACHE_HASH" "$RESUME_RECOVERY_HASH" \
        "$RESUME_MARKER_HASH" "$RESUME_MARKER_MTIME" "$RESUME_INCIDENT_START" "$RESUME_INCIDENT_END" "$RESUME_MARKER_GUARD" \
        >"$RESUME_SCRATCH/state" 2>"$RESUME_SCRATCH/error" \
        && one_record "$RESUME_SCRATCH/state" 'phr-resume state=validated'
}
framework() {
    local mode=$1 expect=$2
    if [[ $mode == up ]]; then
        state owned present || return 1
        # Only our attempted up permits a fresh marker during failure rollback.
        may_up=true
        RESUME_MARKER_GUARD=false
    else state owned either || return 1; fi
    (cd "$HOME/phr-laravel"; timeout --kill-after=2s 45s "$RESUME_PHP" -d memory_limit=1G \
        -d display_errors=0 -d log_errors=0 "$RESUME_SCRIPTS/resume-phr-framework.php" \
        "$mode" "$RESUME_SCRATCH/framework") >"$RESUME_SCRATCH/framework-output" 2>"$RESUME_SCRATCH/error" \
        && one_record "$RESUME_SCRATCH/framework-output" "phr-resume framework=$expect"
}
audit() {
    state owned either || return 1
    timeout --kill-after=2s 60s bash "$shared/operational-audit.sh" phr-laravel "$RESUME_PHP" 1G \
        "$RESUME_RELEASE" "$RESUME_COMMIT" $'storage\npublic/ohif' selected \
        >"$RESUME_SCRATCH/audit" 2>"$RESUME_SCRATCH/error" || return 1
    [[ $(wc -c <"$RESUME_SCRATCH/audit") -le 512 ]] || return 1
    LC_ALL=C awk '
        NR == 1 { if ($0 != "runtime-audit identity=exact paths=canonical writable=yes database=persistent phase=selected") invalid=1; next }
        NR != 2 || $0 !~ /^operational-audit pending_migrations=0 queue_driver=(sync|null|database|redis|sqs|beanstalkd|deferred|background|failover) queue_applicability=(database|no-persistent-queue|external) pending_total=([0-9]+|not-counted) failed_applicability=(database|disabled|external) failed_total=([0-9]+|not-counted)$/ { invalid=1 }
        END { if (NR != 2 || invalid) exit 1 }
    ' "$RESUME_SCRATCH/audit"
}
web() {
    state owned either || return 1
    PATH="$RESUME_SCRATCH/bin:$PATH" timeout --kill-after=15s 100s bash "$shared/verify-web-php.sh" \
        phr-local-probe phr-laravel https://phr.bherila.net 8.5 1024M \
        >"$RESUME_SCRATCH/web" 2>&1 || return 1
    ! grep -Fq 'Could not delete' "$RESUME_SCRATCH/web"
}
read_cron() {
    if timeout --kill-after=2s 15s crontab -l >"$1" 2>"$RESUME_SCRATCH/error"; then return 0; fi
    # An absent account spool is an empty crontab, not an unknown failed read.
    # Validate the complete standard error record; never print the account name.
    if [[ ! -s $1 && $(wc -c <"$RESUME_SCRATCH/error") -le 256 ]] && LC_ALL=C awk '
        NR != 1 || tolower($0) !~ /^(crontab: )?no crontab for [a-z0-9_.-]+\$?$/ { invalid=1 }
        END { if (NR != 1 || invalid) exit 1 }
    ' "$RESUME_SCRATCH/error"; then : >"$1"; return 0; fi
    return 1
}
filter_foreign() {
    # Identical ownership spellings to the reviewed install-cron helper; no prefix ownership.
    # shellcheck disable=SC2016
    LC_ALL=C awk -v quoted_literal='cd "$HOME/phr-laravel" ' -v bare_literal='cd $HOME/phr-laravel ' \
        -v quoted_expanded="cd \"$HOME/phr-laravel\" " -v bare_expanded="cd $HOME/phr-laravel " '
        { owned=index($0,quoted_literal)||index($0,bare_literal)||index($0,quoted_expanded)||index($0,bare_expanded)
          if (!owned && match($0, /# JOB:[A-Za-z0-9._-]+$/)) { id=substr($0,RSTART); owned=(id=="# JOB:phr-laravel-scheduler" || id=="# JOB:phr-laravel-queue-worker") }
          if (!owned) print }
    ' "$1" >"$2"
}
pause_app_cron() (
    state owned either || exit 1
    exec 9>"$HOME/.crontab-deploy.lock"
    timeout --kill-after=2s 65s flock -w 60 9 || exit 1
    state owned either && read_cron "$RESUME_SCRATCH/pause-current" || exit 1
    filter_foreign "$RESUME_SCRATCH/pause-current" "$RESUME_SCRATCH/pause-next"
    if ! cmp -s "$RESUME_SCRATCH/pause-current" "$RESUME_SCRATCH/pause-next"; then
        state owned present || exit 1
        timeout --kill-after=2s 15s crontab "$RESUME_SCRATCH/pause-next" \
            >"$RESUME_SCRATCH/cron-output" 2>"$RESUME_SCRATCH/error" || exit 1
    fi
    read_cron "$RESUME_SCRATCH/pause-readback" && cmp -s "$RESUME_SCRATCH/pause-next" "$RESUME_SCRATCH/pause-readback"
)
release_lock() {
    state owned either || return 1
    # Never recursively remove a directory or another owner's file.
    rm -- "$lock/owner" || return 1
    if rmdir -- "$lock"; then published=false; acquired=false; return 0; fi
    # Retain our ownership marker if directory removal unexpectedly failed.
    if [[ -d $lock && ! -L $lock && $(stat -Lc '%d:%i' "$lock") == "$RESUME_INODE" \
        && ! -e $lock/owner && ! -L $lock/owner ]]; then
        (set -o noclobber; printf '%s\n' "$RESUME_NONCE" >"$lock/owner") || true
    fi
    return 1
}
finish() {
    local result=$?
    trap - EXIT HUP INT TERM
    if [[ $completed == true && $result == 0 ]]; then return; fi
    if [[ $published == true ]] && state owned either; then
        if [[ $may_up == true ]]; then
            if framework down maintenance && state owned present && pause_app_cron && state owned present; then
                if release_lock; then
                    echo 'phr-resume result=failed rollback=maintenance cron=paused lock=released'
                else
                    echo 'phr-resume result=failed rollback=maintenance cron=paused lock=retained'
                fi
            else
                echo 'phr-resume result=failed rollback=unconfirmed cron=unconfirmed lock=retained'
            fi
        elif release_lock; then
            echo 'phr-resume result=refused service_and_cron_mutations=none lock=released'
        else
            echo 'phr-resume result=refused service_and_cron_mutations=none lock=retained'
        fi
    elif [[ $acquired == true ]]; then
        echo 'phr-resume result=failed ownership=unconfirmed lock=retained'
    else
        echo 'phr-resume result=refused service_and_cron_mutations=none'
    fi
    exit 1
}
trap finish EXIT
trap 'exit 1' HUP INT TERM
state idle present || exit 1
RESUME_MARKER_HASH=$(sha256sum "$HOME/phr-laravel/storage/framework/down"); RESUME_MARKER_HASH=${RESUME_MARKER_HASH%% *}
RESUME_MARKER_MTIME=$(stat -c '%Y' "$HOME/phr-laravel/storage/framework/down")
RESUME_OAUTH_PRIVATE_HASH=$(sha256sum "$HOME/phr-laravel/storage/app/private/oauth/oauth-private.key"); RESUME_OAUTH_PRIVATE_HASH=${RESUME_OAUTH_PRIVATE_HASH%% *}
RESUME_OAUTH_PUBLIC_HASH=$(sha256sum "$HOME/phr-laravel/storage/app/private/oauth/oauth-public.key"); RESUME_OAUTH_PUBLIC_HASH=${RESUME_OAUTH_PUBLIC_HASH%% *}
state idle present || exit 1
mkdir -- "$lock" 2>"$RESUME_SCRATCH/error" || exit 1
acquired=true
RESUME_INODE=$(stat -Lc '%d:%i' "$lock")
[[ ! -L $lock && $(readlink -f "$lock") == "$lock" ]] || exit 1
(set -o noclobber; printf '%s\n' "$RESUME_NONCE" >"$lock/owner") || exit 1
published=true
state owned present || exit 1
RESUME_CACHE_HASH=$(sha256sum "$HOME/phr-laravel/bootstrap/cache/config.php"); RESUME_CACHE_HASH=${RESUME_CACHE_HASH%% *}
RESUME_RECOVERY_HASH=$(sha256sum "$HOME/.deployments/phr-laravel/recovery/$RESUME_RELEASE.cron"); RESUME_RECOVERY_HASH=${RESUME_RECOVERY_HASH%% *}
state owned present && audit && state owned present || exit 1
mkdir "$RESUME_SCRATCH/framework" "$RESUME_SCRATCH/bin"
cp -- "$HOME/phr-laravel/bootstrap/cache/config.php" "$RESUME_SCRATCH/framework/config.php"
[[ $(sha256sum "$RESUME_SCRATCH/framework/config.php") == "$RESUME_CACHE_HASH "* ]] || exit 1
ln -s "$RESUME_SCRIPTS/resume-phr-web-ssh.sh" "$RESUME_SCRATCH/bin/ssh"
framework prove-down maintenance && web && state owned present || exit 1
read_cron "$RESUME_SCRATCH/before-cron" || exit 1
filter_foreign "$RESUME_SCRATCH/before-cron" "$RESUME_SCRATCH/before-foreign"
cmp -s "$RESUME_SCRATCH/before-cron" "$RESUME_SCRATCH/before-foreign" || exit 1
echo 'phr-resume preflight=validated identity=exact runtime=valid pending_migrations=0 web=valid lock=owned'
framework up serving && state owned absent && audit && framework prove-up serving && web || exit 1
for endpoint in up login; do
    state owned absent || exit 1
    status=$(timeout --kill-after=2s 25s curl --silent --show-error --max-time 20 --output /dev/null \
        --write-out '%{http_code}' "https://phr.bherila.net/$endpoint" 2>"$RESUME_SCRATCH/error") || exit 1
    if [[ $endpoint == up ]]; then [[ $status == 200 ]] || exit 1
    else [[ $status == 200 || $status == 302 ]] || exit 1; fi
done
state owned absent || exit 1
scheduler="*/5 * * * * cd \"\$HOME/phr-laravel\" && PHR_CRON_MEMORY_LIMIT=1G $RESUME_PHP artisan phr:uptime:run-scheduler >> /dev/null 2>&1 # JOB:phr-laravel-scheduler"
worker="*/5 * * * * cd \"\$HOME/phr-laravel\" && PHR_CRON_MEMORY_LIMIT=1G /usr/bin/flock -n \"\$HOME/phr-laravel/storage/framework/phr-queue-worker.lock\" $RESUME_PHP artisan phr:uptime:run-worker >> /dev/null 2>&1 # JOB:phr-laravel-queue-worker"
bash "$shared/prepare-cron-lines.sh" phr-laravel "$RESUME_PHP" 1G /dev/null "$scheduler" "$worker" \
    >"$RESUME_SCRATCH/desired" 2>"$RESUME_SCRATCH/error"
mapfile -t desired <"$RESUME_SCRATCH/desired"
[[ ${#desired[@]} == 2 ]] || exit 1
timeout --kill-after=5s 145s bash "$shared/install-cron.sh" phr-laravel "${desired[@]}" \
    >"$RESUME_SCRATCH/cron-output" 2>"$RESUME_SCRATCH/error" || exit 1
state owned absent && read_cron "$RESUME_SCRATCH/after-cron" || exit 1
export DEPLOY_DIR=phr-laravel DEPLOY_PHP_BINARY=$RESUME_PHP
# shellcheck source=/dev/null
source "$RESUME_SCRIPTS/verify-phr-cron.sh"
(verify_phr_cron "$RESUME_SCRATCH/after-cron") >"$RESUME_SCRATCH/cron-proof" 2>"$RESUME_SCRATCH/error" || exit 1
# install-cron preserves foreign rows from its own fresh locked snapshot, not a stale global backup.
state owned absent && framework prove-up serving && release_lock || exit 1
completed=true
echo 'phr-resume result=serving identity=exact runtime=valid pending_migrations=0 web=valid http_up=200 http_login=healthy cron=canonical lock=released recovery_cron=preserved'
