#!/usr/bin/env bash
set -euo pipefail

# Reuse the existing verified publisher, but only through this pilot's SSH alias
# and while borrowing the pilot application's atomic transaction mutex.
for name in OHIF_SSH_TARGET OHIF_RUN_ID OHIF_ARTIFACT_ID OHIF_ARTIFACT_DIGEST OHIF_SOURCE_COMMIT DEPLOY_DIR DEPLOY_RELEASE_ID DEPLOY_SSH_TARGET DEPLOY_PHP_BINARY DEPLOY_SOURCE_COMMIT; do
    [[ -n "${!name:-}" ]] || exit 2
done
[[ "$OHIF_SSH_TARGET" == pilot-bootstrap && "$DEPLOY_DIR" == phr-laravel ]] || exit 2
OHIF_LOCK_OWNER="$DEPLOY_RELEASE_ID" bash .github/scripts/publish-ohif-dist.sh

# Publication can create new files after the activation hook. Grant only the
# public trees' read/traversal ACLs before verifying the HTTP representation.
# shellcheck disable=SC2029 # Each remote argument is escaped with printf %q.
ssh "$DEPLOY_SSH_TARGET" "bash -s -- $(printf '%q ' "$DEPLOY_DIR" "$DEPLOY_PHP_BINARY" "$DEPLOY_DIR")" \
    < scripts/deploy/pilot/post-activate.sh
# shellcheck disable=SC2029 # Each remote argument is escaped with printf %q.
ssh "$DEPLOY_SSH_TARGET" "bash -s -- $(printf '%q ' "$DEPLOY_DIR" "$DEPLOY_PHP_BINARY" "$DEPLOY_RELEASE_ID" "$DEPLOY_SOURCE_COMMIT" "$DEPLOY_RELEASE_ID")" \
    < .github/scripts/verify-phr-ohif-artifact.sh
exec node "$(dirname "$0")/verify-live.mjs"
