#!/usr/bin/env bash
# Run only after the shared action has finalized and released its remote lock.
set -euo pipefail
for name in DEPLOY_SSH_TARGET DEPLOY_PHP_BINARY DEPLOY_DIR DEPLOY_RELEASE_ID DEPLOY_SOURCE_COMMIT DEPLOY_LIVE_RELEASE DEPLOY_LIVE_COMMIT DEPLOY_LIVE_STATE; do
    [[ -n "${!name:-}" ]] || { echo 'Finalizer diagnostic input missing.' >&2; exit 2; }
done
[[ "$DEPLOY_SSH_TARGET" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*(@[A-Za-z0-9][A-Za-z0-9.-]*)?$ \
    && "$DEPLOY_DIR" == phr-laravel && "$DEPLOY_PHP_BINARY" =~ ^/[A-Za-z0-9/._-]+$ \
    && "$DEPLOY_RELEASE_ID" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ && "$DEPLOY_SOURCE_COMMIT" =~ ^[a-f0-9]{40}$ ]] || exit 2
[[ "$DEPLOY_LIVE_RELEASE" == "$DEPLOY_RELEASE_ID" && "$DEPLOY_LIVE_COMMIT" == "$DEPLOY_SOURCE_COMMIT" && "$DEPLOY_LIVE_STATE" == serving ]] || {
    echo 'Finalizer did not report the exact serving PHR release.' >&2; exit 1;
}
ssh_bin=${PHR_VERIFY_SSH_BIN:-ssh}
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
scratch=$(mktemp -d)
trap 'rm -rf -- "$scratch"' EXIT
if ! timeout --kill-after=5s 60s "$ssh_bin" "$DEPLOY_SSH_TARGET" \
    "bash -s -- $(printf '%q ' "$DEPLOY_DIR" "$DEPLOY_PHP_BINARY" "$DEPLOY_RELEASE_ID" "$DEPLOY_SOURCE_COMMIT")" \
    > "$scratch/proof" 2> "$scratch/error" <<'REMOTE'
set -euo pipefail
[[ $# == 4 && $1 == phr-laravel && $2 == /* && -x $2 ]] || exit 2
timeout --kill-after=2s 30s "$2" -d memory_limit=1G -d display_errors=0 -d log_errors=0 /dev/stdin "$HOME" "$1" "$3" "$4" <<'PHP'
<?php
try {
    [, $home, $app, $release, $commit] = $argv;
    $stable = $home.'/'.$app;
    $control = $home.'/.deployments/'.$app;
    $storage = $control.'/shared/storage';
    foreach ([$home, $home.'/.deployments', $control, $control.'/state', $control.'/shared', $storage, $stable] as $dir) {
        if (!is_dir($dir) || is_link($dir) || realpath($dir) !== $dir) { exit(1); }
    }
    if (file_exists($control.'/deploy.lock') || is_link($control.'/deploy.lock') || scandir($control.'/state') !== ['.', '..']
        || !is_link($stable.'/storage') || realpath($stable.'/storage') !== $storage
        || file_exists($storage.'/framework/down') || is_link($storage.'/framework/down')) { exit(1); }
    $metaPath = $stable.'/.deploy-release';
    if (!is_file($metaPath) || is_link($metaPath) || filesize($metaPath) > 4096) { exit(1); }
    $meta = file_get_contents($metaPath);
    preg_match_all('/^release=(.*)$/m', $meta, $releases);
    preg_match_all('/^commit=(.*)$/m', $meta, $commits);
    if ($releases[1] !== [$release] || $commits[1] !== [$commit]) { exit(1); }
    foreach (['oauth-private.key', 'oauth-public.key'] as $key) {
        $path = $storage.'/app/private/oauth/'.$key;
        if (!is_file($path) || is_link($path) || realpath($path) !== $path || filesize($path) < 1 || !is_readable($path)) { exit(1); }
    }
    echo "phr-finalized identity=exact serving=yes lock=absent transactions=0 oauth_keys=present\n";
} catch (Throwable) { exit(1); }
PHP
REMOTE
then
    echo 'Read-only finalized PHR proof failed; diagnostics redacted.' >&2; exit 1
fi
[[ "$(cat "$scratch/proof")" == 'phr-finalized identity=exact serving=yes lock=absent transactions=0 oauth_keys=present' ]] || exit 1
if ! timeout --kill-after=5s 60s "$ssh_bin" "$DEPLOY_SSH_TARGET" 'crontab -l' > "$scratch/crontab" 2> "$scratch/error"; then
    echo 'Finalized PHR cron could not be read; diagnostics redacted.' >&2; exit 1
fi
# shellcheck source=./verify-phr-cron.sh
source "$script_dir/verify-phr-cron.sh"
verify_phr_cron "$scratch/crontab"
echo 'Finalized PHR release, OAuth key pair and canonical cron proved read-only.'
