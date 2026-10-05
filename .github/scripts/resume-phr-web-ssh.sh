#!/usr/bin/env bash
# On-host adapter for the reviewed web helper's two fixed SSH commands.
# It never opens a network SSH connection or executes a supplied shell command.
set -euo pipefail
[[ $# == 2 && $1 == phr-local-probe ]] || exit 2
command_text=$2
name=${command_text##*/}
name=${name%\"}
[[ $name =~ ^_deploy-php-check-[a-f0-9]{32}\.php$ ]] || exit 2
"$RESUME_PHP" -d memory_limit=1G -d display_errors=0 -d log_errors=0 \
    "$RESUME_SCRIPTS/resume-phr-state.php" "$RESUME_RELEASE" "$RESUME_COMMIT" "$RESUME_NONCE" \
    "$RESUME_INODE" owned either "$RESUME_CACHE_HASH" "$RESUME_RECOVERY_HASH" \
    "$RESUME_MARKER_HASH" "$RESUME_MARKER_MTIME" "$RESUME_INCIDENT_START" "$RESUME_INCIDENT_END" "$RESUME_MARKER_GUARD" \
    >"$RESUME_SCRATCH/probe-state" 2>"$RESUME_SCRATCH/probe-error"
[[ $(wc -c <"$RESUME_SCRATCH/probe-state") == 27 ]] \
    && cmp -s "$RESUME_SCRATCH/probe-state" <(printf 'phr-resume state=validated\n') || exit 1
path="$HOME/phr-laravel/public/$name"
if [[ $command_text == "umask 022 && cat > \"\$HOME/phr-laravel/public/$name\"" ]]; then
    [[ ! -e $path && ! -L $path ]] || exit 1
    (umask 022; set -o noclobber; cat >"$path")
elif [[ $command_text == "rm -f \"\$HOME/phr-laravel/public/$name\"" ]]; then
    [[ ! -L $path && ( ! -e $path || -f $path ) ]] || exit 1
    rm -f -- "$path"
    [[ ! -e $path && ! -L $path ]]
else
    exit 2
fi
