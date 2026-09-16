#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 3 ]]; then
    echo 'usage: provision-phr-candidate-secrets.sh <candidate-path> <php> <stable-path>' >&2
    exit 2
fi

readonly candidate_path="$1"
readonly php_bin="$2"
readonly stable_path="$3"

case "$stable_path" in
    '' | .* | */* | *[!A-Za-z0-9._-]*) echo 'The secret provisioner received an unsafe stable path.' >&2; exit 2 ;;
esac
case "$candidate_path" in
    .deployments/"$stable_path"/releases/*)
        release_id="${candidate_path##*/}"
        [[ -n "$release_id" && "$release_id" != .* && "$release_id" != *[!A-Za-z0-9._-]* ]] \
            || { echo 'The secret provisioner received an unsafe release id.' >&2; exit 2; }
        ;;
    *) echo 'The secret provisioner received an unrelated candidate path.' >&2; exit 2 ;;
esac
case "$php_bin" in /*) ;; *) echo 'The secret provisioner requires an absolute PHP path.' >&2; exit 2 ;; esac

readonly candidate_root="$HOME/$candidate_path"
readonly stable_root="$HOME/$stable_path"
readonly candidate_env="$candidate_root/.env"
readonly stable_env="$stable_root/.env"
readonly installer="$candidate_root/scripts/configure-agent-mutation-digest-key.php"
if [[ ! -x "$php_bin" || ! -f "$candidate_env" || ! -f "$installer" ]]; then
    echo 'Candidate secret provisioning requires PHP, .env, and the digest-key installer.' >&2
    exit 1
fi

read_key() {
    local env_file="$1" required="$2" lines count value
    [[ -f "$env_file" ]] || {
        [[ "$required" == false ]] && return 3
        echo 'The required deployment environment is missing.' >&2
        return 1
    }
    lines="$(grep -E '^AGENT_API_MUTATION_DIGEST_KEY=' "$env_file" || true)"
    count="$(grep -Ec '^AGENT_API_MUTATION_DIGEST_KEY=' "$env_file" || true)"
    if [[ "$count" == 0 && "$required" == false ]]; then
        return 3
    fi
    if [[ "$count" != 1 ]]; then
        echo 'The mutation digest key must occur exactly once in the deployment environment.' >&2
        return 1
    fi
    value="${lines#AGENT_API_MUTATION_DIGEST_KEY=}"
    if [[ ! "$value" =~ ^base64:[A-Za-z0-9+/]{43}=$ ]]; then
        echo 'The mutation digest key is malformed.' >&2
        return 1
    fi
    printf '%s' "$value"
}

stable_status=0
stable_key="$(read_key "$stable_env" false)" || stable_status=$?
case "$stable_status" in
    0)
        candidate_status=0
        candidate_key="$(read_key "$candidate_env" true)" || candidate_status=$?
        if [[ "$candidate_status" -ne 0 || "$candidate_key" != "$stable_key" ]]; then
            echo 'The candidate did not inherit the selected release mutation digest key.' >&2
            exit 1
        fi
        ;;
    3) ;;
    *) exit 1 ;;
esac

PHR_ENV_FILE="$candidate_env" "$php_bin" "$installer" >/dev/null
candidate_key="$(read_key "$candidate_env" true)"
if [[ "$stable_status" -eq 0 && "$candidate_key" != "$stable_key" ]]; then
    echo 'Candidate secret provisioning changed the selected mutation digest key.' >&2
    exit 1
fi

echo 'Candidate-local agent mutation digest key is configured.'
