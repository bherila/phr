#!/usr/bin/env bash
# Runner orchestration for the reviewed failed-probe incident; no source upload.
set -euo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
shared_dir=${PHR_SHARED_ACTION_DIR:?}
owner="recovery-${GITHUB_RUN_ID:?}-${GITHUB_RUN_ATTEMPT:?}"
export DEPLOY_SSH_TARGET=phr-ohif DEPLOY_DIR=phr-laravel DEPLOY_STABLE_DIR=phr-laravel DEPLOY_CANDIDATE_DIR=phr-laravel \
    DEPLOY_RELEASE_ID=7b33fe7445aa-37239370303-1 DEPLOY_LIVE_RELEASE=7b33fe7445aa-37239370303-1 \
    DEPLOY_SOURCE_COMMIT=7b33fe7445aa6d0a002f2e752497282b04c360a2 DEPLOY_LIVE_COMMIT=7b33fe7445aa6d0a002f2e752497282b04c360a2 \
    DEPLOY_LIVE_STATE=serving DEPLOY_PHP_BINARY=/opt/cpanel/ea-php85/root/usr/bin/php DEPLOY_SITE_URL=https://phr.bherila.net
control() {
    timeout --kill-after=5s 60s ssh "$DEPLOY_SSH_TARGET" "bash -s -- $(printf '%q ' "$1" phr-laravel "${@:2}")" < "$script_dir/ohif-publication-remote.sh"
}
maintenance() {
    timeout --kill-after=5s 60s ssh "$DEPLOY_SSH_TARGET" "bash -s -- $(printf '%q ' "$1" "$owner" "${2:-}" "$DEPLOY_PHP_BINARY")" < "$script_dir/resume-reviewed-release-remote.sh"
}
audit() {
    timeout --kill-after=5s 60s ssh "$DEPLOY_SSH_TARGET" "bash -s -- $(printf '%q ' phr-laravel "$DEPLOY_PHP_BINARY" 1G "$DEPLOY_RELEASE_ID" "$DEPLOY_SOURCE_COMMIT" $'storage\npublic/ohif' selected)" < "$shared_dir/scripts/operational-audit.sh"
}
# Prove the intended app before creating any control metadata.
timeout --kill-after=5s 60s ssh "$DEPLOY_SSH_TARGET" 'bash -s' < "$script_dir/read-phr-live-identity.sh" | node -e '
const fs = require("node:fs");
try { const value = JSON.parse(fs.readFileSync(0)); if (value.state !== "selected" || value.commit !== "7b33fe7445aa6d0a002f2e752497282b04c360a2" || value.release !== "7b33fe7445aa-37239370303-1") throw new Error(); }
catch { console.error("Recovery selected release is not exact."); process.exit(1); }
'
owned=false
attempted=false
cleanup() {
    local status=$?
    if [[ "$owned" == true && "$attempted" == true && "$status" != 0 ]]; then
        if ! maintenance down; then
            owned=false
            echo 'Maintenance restoration is unproven; retain the recovery mutex for manual inspection.' >&2
        fi
    fi
    if [[ "$owned" == true ]]; then control release "$owner" || status=1; fi
    exit "$status"
}
trap cleanup EXIT
trap 'owned=false; exit 1' INT TERM
[[ "$(control acquire "$owner" '')" == owned ]] || exit 1
owned=true
marker=$(maintenance inspect)
[[ "$marker" =~ ^[a-f0-9]{64}$ ]] || exit 1
audit
timeout --kill-after=5s 180s bash "$shared_dir/scripts/verify-web-php.sh" "$DEPLOY_SSH_TARGET" "$DEPLOY_DIR" "$DEPLOY_SITE_URL" 8.5 1G
attempted=true
maintenance up "$marker"
# This recovery verifies the existing static bundle; it never publishes OHIF.
env -u OHIF_RUN_ID bash "$script_dir/verify-phr-deployment.sh"
audit
control release "$owner"
owned=false
bash "$script_dir/verify-phr-finalized.sh"
echo 'The exact reviewed release is serving, with canonical runtime, keys and cron proved.'
