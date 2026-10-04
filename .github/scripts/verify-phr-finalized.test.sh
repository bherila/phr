#!/usr/bin/env bash
# Synthetic finalized layout and actual PHP; SSH/crontab are local transports.
set -euo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
scratch=$(mktemp -d)
trap 'rm -rf -- "$scratch"' EXIT
fixture_home="$scratch/home"
control="$fixture_home/.deployments/phr-laravel"
shared="$control/shared/storage"
stable="$fixture_home/phr-laravel"
mkdir -p "$control/state" "$shared/framework" "$shared/app/private/oauth" "$stable" "$scratch/bin"
ln -s "$shared" "$stable/storage"
export DEPLOY_SSH_TARGET=fixture-host DEPLOY_DIR=phr-laravel DEPLOY_RELEASE_ID=fixture-release \
    DEPLOY_SOURCE_COMMIT=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
    DEPLOY_LIVE_RELEASE=fixture-release DEPLOY_LIVE_COMMIT=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa DEPLOY_LIVE_STATE=serving
DEPLOY_PHP_BINARY=$(command -v php)
export DEPLOY_PHP_BINARY
printf 'release=%s\ncommit=%s\n' "$DEPLOY_RELEASE_ID" "$DEPLOY_SOURCE_COMMIT" > "$stable/.deploy-release"
printf 'synthetic-private-key\n' > "$shared/app/private/oauth/oauth-private.key"
printf 'synthetic-public-key\n' > "$shared/app/private/oauth/oauth-public.key"
cat > "$scratch/bin/ssh" <<'SSH'
#!/usr/bin/env bash
set -euo pipefail
[[ "$1" == fixture-host ]] || exit 2
shift
if [[ "$1" == 'crontab -l' ]]; then
    printf '*/5 * * * * cd "$HOME/phr-laravel" && PHR_CRON_MEMORY_LIMIT=1G %s -d memory_limit=1G artisan phr:uptime:run-scheduler >> /dev/null 2>&1 # JOB:phr-laravel-scheduler\n' "$DEPLOY_PHP_BINARY"
    printf '*/5 * * * * cd "$HOME/phr-laravel" && PHR_CRON_MEMORY_LIMIT=1G /usr/bin/flock -n "$HOME/phr-laravel/storage/framework/phr-queue-worker.lock" %s -d memory_limit=1G artisan phr:uptime:run-worker >> /dev/null 2>&1 # JOB:phr-laravel-queue-worker\n' "$DEPLOY_PHP_BINARY"
    if [[ "${BAD_CRON:-}" == 1 ]]; then echo '* * * * * synthetic # JOB:phr-laravel-scheduler'; fi
else
    env HOME="$FIXTURE_HOME" bash -c "$1"
fi
SSH
chmod +x "$scratch/bin/ssh"
export PHR_VERIFY_SSH_BIN="$scratch/bin/ssh" FIXTURE_HOME="$fixture_home"
verify() { bash "$script_dir/verify-phr-finalized.sh"; }
reject() {
    if verify > "$scratch/output" 2>&1; then echo 'unexpected finalized proof success' >&2; exit 1; fi
    if grep -Fq synthetic-private-key "$scratch/output"; then exit 1; fi
}
before=$(find "$fixture_home" -type f -exec sha256sum {} + | sort)
verify
[[ "$(find "$fixture_home" -type f -exec sha256sum {} + | sort)" == "$before" ]]
mkdir "$control/deploy.lock"
reject
rmdir "$control/deploy.lock"
mkdir "$control/state/incomplete"
reject
rmdir "$control/state/incomplete"
touch "$shared/framework/down"
reject
rm "$shared/framework/down"
mv "$shared/app/private/oauth/oauth-private.key" "$scratch/private"
reject
mv "$scratch/private" "$shared/app/private/oauth/oauth-private.key"
ln -s "$scratch/private" "$shared/app/private/oauth/dangling"
BAD_CRON=1 reject
DEPLOY_LIVE_COMMIT=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb reject
cp "$stable/.deploy-release" "$scratch/meta"
echo 'commit=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' >> "$stable/.deploy-release"
reject
cp "$scratch/meta" "$stable/.deploy-release"
verify > /dev/null
echo 'Finalized identity, lock, transactions, keys, serving, cron and read-only fixtures passed.'
