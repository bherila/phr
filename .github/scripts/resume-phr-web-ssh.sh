#!/usr/bin/env bash
# On-host adapter for the reviewed web helper's three fixed SSH commands: write the probe, fetch
# it from the origin, and delete it. It never opens a network SSH connection or executes a
# supplied shell command; the fetch runs the bundled origin-fetch.sh, never the script on stdin.
set -euo pipefail
[[ $# == 2 && $1 == phr-local-probe ]] || exit 2
command_text=$2
fetch_pattern='^bash -s -- phr\.bherila\.net /(_deploy-php-check-[a-f0-9]{32}\.php) ([1-9]|1[0-9]|20) '
fetch_pattern+="'' php-runtime $"
if [[ $command_text =~ $fetch_pattern ]]; then
    name=${BASH_REMATCH[1]}
    fetch_limit=${BASH_REMATCH[2]}
else
    fetch_limit=''
    name=${command_text##*/}
    name=${name%\"}
fi
[[ $name =~ ^_deploy-php-check-[a-f0-9]{32}\.php$ ]] || exit 2
"$RESUME_PHP" -d memory_limit=1G -d display_errors=0 -d log_errors=0 \
    "$RESUME_SCRIPTS/resume-phr-state.php" "$RESUME_RELEASE" "$RESUME_COMMIT" "$RESUME_NONCE" \
    "$RESUME_INODE" owned either "$RESUME_CACHE_HASH" "$RESUME_RECOVERY_HASH" \
    "$RESUME_MARKER_HASH" "$RESUME_MARKER_MTIME" "$RESUME_INCIDENT_START" "$RESUME_INCIDENT_END" "$RESUME_MARKER_GUARD" \
    >"$RESUME_SCRATCH/probe-state" 2>"$RESUME_SCRATCH/probe-error"
[[ $(wc -c <"$RESUME_SCRATCH/probe-state") == 27 ]] \
    && printf 'phr-resume state=validated\n' | cmp -s -- "$RESUME_SCRATCH/probe-state" - || exit 1
path="$HOME/phr-laravel/public/$name"
if [[ -n $fetch_limit ]]; then
    [[ -f $path && ! -L $path ]] || exit 1
    exec bash "$RESUME_SCRIPTS/shared/origin-fetch.sh" phr.bherila.net "/$name" "$fetch_limit" '' php-runtime </dev/null
elif [[ $command_text == "umask 022 && cat > \"\$HOME/phr-laravel/public/$name\"" ]]; then
    [[ ! -e $path && ! -L $path ]] || exit 1
    (umask 022; set -o noclobber; cat >"$path")
elif [[ $command_text == "rm -f \"\$HOME/phr-laravel/public/$name\"" ]]; then
    [[ ! -L $path && ( ! -e $path || -f $path ) ]] || exit 1
    rm -f -- "$path"
    [[ ! -e $path && ! -L $path ]]
else
    exit 2
fi
