#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly script_dir
readonly provisioner="$script_dir/provision-phr-candidate-secrets.sh"
test_root="$(mktemp -d)"
readonly test_root
trap 'rm -rf "$test_root"' EXIT

export HOME="$test_root/home"
candidate_path='.deployments/phr-laravel/releases/candidate'
candidate_root="$HOME/$candidate_path"
stable_root="$HOME/phr-laravel"
mkdir -p "$candidate_root/scripts" "$stable_root"
cp "$script_dir/../../scripts/configure-agent-mutation-digest-key.php" "$candidate_root/scripts/"
php_bin="$(command -v php)"
readonly digest='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='

printf 'APP_KEY=synthetic\nAGENT_API_MUTATION_DIGEST_KEY=%s\n' "$digest" >"$stable_root/.env"
cp "$stable_root/.env" "$candidate_root/.env"
"$provisioner" "$candidate_path" "$php_bin" phr-laravel >/dev/null
grep -Fqx "AGENT_API_MUTATION_DIGEST_KEY=$digest" "$candidate_root/.env"

printf 'APP_KEY=synthetic\n' >"$candidate_root/.env"
if "$provisioner" "$candidate_path" "$php_bin" phr-laravel >/dev/null 2>&1; then
    echo 'Expected a candidate that lost the stable key to fail.' >&2
    exit 1
fi

printf 'APP_KEY=synthetic\n' >"$stable_root/.env"
cp "$stable_root/.env" "$candidate_root/.env"
"$provisioner" "$candidate_path" "$php_bin" phr-laravel >/dev/null
grep -Eq '^AGENT_API_MUTATION_DIGEST_KEY=base64:[A-Za-z0-9+/]{43}=$' "$candidate_root/.env"
if grep -q '^AGENT_API_MUTATION_DIGEST_KEY=' "$stable_root/.env"; then
    echo 'Candidate provisioning mutated the selected release environment.' >&2
    exit 1
fi

echo 'provision PHR candidate secrets tests passed'
