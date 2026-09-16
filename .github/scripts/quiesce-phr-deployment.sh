#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 3 ]]; then
    echo 'usage: quiesce-phr-deployment.sh <candidate-path> <php> <stable-path>' >&2
    exit 2
fi

readonly candidate_path="$1"
readonly php_bin="$2"
readonly stable_path="$3"
readonly timeout_seconds="${PHR_QUIESCE_TIMEOUT_SECONDS:-3900}"
readonly poll_seconds="${PHR_QUIESCE_POLL_SECONDS:-5}"
readonly pgrep_bin="${PHR_QUIESCE_PGREP_BIN:-/usr/bin/pgrep}"
readonly sleep_bin="${PHR_QUIESCE_SLEEP_BIN:-/usr/bin/sleep}"

validate_app_path() {
    local path="$1" prefix app releases release extra
    case "$path" in
        .deployments/*/releases/*)
            IFS=/ read -r prefix app releases release extra <<<"$path"
            [[ "$prefix" == .deployments && "$releases" == releases && -z "$extra" ]] || return 1
            [[ "$app" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ \
                && "$release" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]]
            ;;
        '' | .* | */* | *[!A-Za-z0-9._-]*) return 1 ;;
    esac
}

for path in "$candidate_path" "$stable_path"; do
    if ! validate_app_path "$path"; then
        echo 'The deployment hook received an unsafe application path.' >&2
        exit 2
    fi
done

case "$php_bin" in
    /*) ;;
    *)
        echo 'The deployment hook requires an absolute PHP path.' >&2
        exit 2
        ;;
esac

if [[ ! "$timeout_seconds" =~ ^[1-9][0-9]*$ || ! "$poll_seconds" =~ ^[1-9][0-9]*$ ]]; then
    echo 'PHR quiescence timeout and poll interval must be positive seconds.' >&2
    exit 2
fi

for executable in "$php_bin" "$pgrep_bin" "$sleep_bin"; do
    if [[ ! -x "$executable" ]]; then
        echo "Required quiescence executable is unavailable: ${executable}" >&2
        exit 1
    fi
done

for app_path in "$candidate_path" "$stable_path"; do
    if [[ ! -f "$HOME/$app_path/artisan" ]]; then
        echo "Laravel application path is unavailable during quiescence: ${app_path}" >&2
        exit 1
    fi
done

remaining="$timeout_seconds"
while true; do
    process_status=0
    # The bracketed first character prevents pgrep from matching its own argv.
    "$pgrep_bin" -u "$(id -u)" -f '[p]hr:uptime:run-(scheduler|worker)' >/dev/null || process_status=$?
    case "$process_status" in
        1)
            echo 'PHR scheduler and queue worker processes are quiescent.'
            exit 0
            ;;
        0) ;;
        *)
            echo 'Could not inspect PHR scheduler and queue worker processes.' >&2
            exit 1
            ;;
    esac

    if (( remaining <= poll_seconds )); then
        echo "PHR scheduler or queue worker processes remained active for ${timeout_seconds} seconds." >&2
        exit 1
    fi

    "$sleep_bin" "$poll_seconds"
    remaining=$((remaining - poll_seconds))
done
