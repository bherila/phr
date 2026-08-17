#!/usr/bin/env bash
set -euo pipefail

phr_test_root="$(mktemp -d)"
trap 'rm -rf "$phr_test_root"' EXIT
phr_app_dir="$phr_test_root/phr-laravel"
mkdir -p "$phr_app_dir"
printf 'APP_KEY=synthetic-test-key\n' > "$phr_app_dir/.env"

PHR_APP_DIR="$phr_app_dir" PHR_PHP_BIN="$(command -v php)" \
  bash .github/scripts/configure-agent-mutation-digest-key.sh >/dev/null
grep -Eq '^AGENT_API_MUTATION_DIGEST_KEY=base64:[A-Za-z0-9+/]{43}=$' "$phr_app_dir/.env"
phr_first_hash="$(sha256sum "$phr_app_dir/.env")"

PHR_APP_DIR="$phr_app_dir" PHR_PHP_BIN="$(command -v php)" \
  bash .github/scripts/configure-agent-mutation-digest-key.sh >/dev/null
test "$phr_first_hash" = "$(sha256sum "$phr_app_dir/.env")"

printf 'AGENT_API_MUTATION_DIGEST_KEY=invalid\n' > "$phr_app_dir/.env"
if PHR_APP_DIR="$phr_app_dir" PHR_PHP_BIN="$(command -v php)" \
  bash .github/scripts/configure-agent-mutation-digest-key.sh >/dev/null 2>&1; then
  echo 'Malformed mutation digest key was accepted.' >&2
  exit 1
fi

echo 'agent mutation digest key installer tests passed'
