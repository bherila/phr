#!/usr/bin/env bash
set -euo pipefail

phr_app_dir="${PHR_APP_DIR:-$HOME/phr-laravel}"
phr_php_bin="${PHR_PHP_BIN:-/opt/cpanel/ea-php85/root/usr/bin/php}"
phr_env_file="$phr_app_dir/.env"

if [ ! -f "$phr_env_file" ]; then
  echo 'PHR .env is missing; refusing to create the mutation digest key.' >&2
  exit 1
fi

phr_key_count="$(grep -c '^AGENT_API_MUTATION_DIGEST_KEY=' "$phr_env_file" || true)"
if [ "$phr_key_count" -gt 1 ]; then
  echo 'AGENT_API_MUTATION_DIGEST_KEY appears more than once; refusing an ambiguous configuration.' >&2
  exit 1
fi

if [ "$phr_key_count" -eq 0 ]; then
  phr_digest_key="$($phr_php_bin -r 'echo "base64:".base64_encode(random_bytes(32));')"
  printf '\nAGENT_API_MUTATION_DIGEST_KEY=%s\n' "$phr_digest_key" >> "$phr_env_file"
  unset phr_digest_key
fi

if ! grep -Eq '^AGENT_API_MUTATION_DIGEST_KEY=base64:[A-Za-z0-9+/]{43}=$' "$phr_env_file"; then
  echo 'AGENT_API_MUTATION_DIGEST_KEY is malformed; refusing to deploy.' >&2
  exit 1
fi

chmod 600 "$phr_env_file"
echo 'Persistent agent mutation digest key is configured.'
