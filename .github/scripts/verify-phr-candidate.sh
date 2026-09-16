#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 3 ]]; then
    echo 'usage: verify-phr-candidate.sh <candidate-path> <php> <stable-path>' >&2
    exit 2
fi

readonly candidate_path="$1"
readonly php_bin="$2"
readonly stable_path="$3"
readonly memory_limit="${PHR_DEPLOY_MEMORY_LIMIT:-1G}"

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
        echo 'The candidate verifier received an unsafe application path.' >&2
        exit 2
    fi
done
case "$php_bin" in
    /*) ;;
    *)
        echo 'The candidate verifier requires an absolute PHP path.' >&2
        exit 2
        ;;
esac
if [[ ! "$memory_limit" =~ ^[1-9][0-9]*[MGmg]$ ]]; then
    echo 'PHR_DEPLOY_MEMORY_LIMIT must be a positive M/G value.' >&2
    exit 2
fi
if [[ ! -x "$php_bin" ]]; then
    echo 'The configured PHP binary is not executable.' >&2
    exit 1
fi

readonly candidate_root="$HOME/$candidate_path"
readonly stable_root="$HOME/$stable_path"
for app_root in "$candidate_root" "$stable_root"; do
    if [[ ! -f "$app_root/artisan" || ! -f "$app_root/.env" ]]; then
        echo 'Candidate verification requires complete candidate and stable Laravel roots.' >&2
        exit 1
    fi
done

read_digest_key() {
    local env_file="$1" required="$2" lines count value
    lines="$(grep -E '^AGENT_API_MUTATION_DIGEST_KEY=' "$env_file" || true)"
    count="$(grep -Ec '^AGENT_API_MUTATION_DIGEST_KEY=' "$env_file" || true)"
    if [[ "$count" == 0 && "$required" == false ]]; then
        return 3
    fi
    if [[ "$count" != 1 ]]; then
        echo 'The mutation digest key must occur exactly once when present in a deployment environment.' >&2
        return 1
    fi
    value="${lines#AGENT_API_MUTATION_DIGEST_KEY=}"
    if [[ ! "$value" =~ ^base64:[A-Za-z0-9+/]{43}=$ ]]; then
        echo 'The mutation digest key is malformed.' >&2
        return 1
    fi
    printf '%s' "$value"
}

candidate_digest="$(read_digest_key "$candidate_root/.env" true)"
stable_status=0
stable_digest="$(read_digest_key "$stable_root/.env" false)" || stable_status=$?
case "$stable_status" in
    0)
        if [[ "$candidate_digest" != "$stable_digest" ]]; then
            echo 'The candidate mutation digest key differs from the selected stable release.' >&2
            exit 1
        fi
        ;;
    3) ;; # Candidate-only bootstrap; activation makes this key the next stable value.
    *) exit 1 ;;
esac

cd "$candidate_root"
bootstrap_memory_limit="$(
    PHR_CRON_MEMORY_LIMIT="$memory_limit" \
        "$php_bin" -d memory_limit=128M -r \
        'require "vendor/autoload.php"; require "bootstrap/app.php"; echo ini_get("memory_limit");'
)"
if [[ "${bootstrap_memory_limit^^}" != "${memory_limit^^}" ]]; then
    echo 'Laravel bootstrap did not apply the scheduled child memory limit.' >&2
    exit 1
fi

schedule_output="$("$php_bin" -d "memory_limit=$memory_limit" artisan schedule:list --no-ansi)"
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

queue_driver_output="$("$php_bin" -d "memory_limit=$memory_limit" artisan config:show queue.default --no-ansi)"
if ! grep -Eq 'queue\.default[[:space:].]+database[[:space:]]*$' <<<"$queue_driver_output"; then
    echo 'The managed queue worker requires queue.default=database.' >&2
    exit 1
fi

queue_audit_output="$("$php_bin" -d "memory_limit=$memory_limit" artisan phr:queue:audit --no-ansi)"
retry_after="$(sed -nE 's/^queue-audit .*retry_after=([0-9]+).*$/\1/p' <<<"$queue_audit_output")"
if [[ -z "$retry_after" || "$retry_after" -le 3600 ]]; then
    echo 'The database queue retry_after must exceed the 3,600-second native restore timeout.' >&2
    exit 1
fi

"$php_bin" -d "memory_limit=$memory_limit" artisan phr:agent-api:verify-oauth-keys --no-ansi >/dev/null
echo 'PHR candidate schedule, queue, OAuth, memory, and mutation digest invariants are valid.'
