#!/usr/bin/env bash
# Converge the deployed OHIF bundle to a desired local bundle, in one place.
#
# # Why this exists (issue #126)
#
# The application deploy and the OHIF deploy used to be separate jobs sharing
# the GitHub Actions concurrency group `phr-production-files`. GitHub keeps at
# most one *pending* job per group, so a newly queued writer replaces an older
# pending one. For the application that is harmless: every run deploys current
# `main`, so the run that evicts a pending run already contains its commits.
# For OHIF it was not. Each OHIF run deployed one specific tag's artifact, and
# the application rsync excludes `ohif`, so an evicted OHIF deploy never
# reached production and nothing later repaired it.
#
# The remote atomic lock cannot be the serializer instead: the shared action
# takes it with a single `mkdir` and no retry, and hard-fails demanding manual
# recovery when it is held. A losing writer does not queue, it breaks. So the
# GitHub-level serializer has to stay, and the fix is to stop the OHIF writer
# being the kind of work that eviction can lose.
#
# This script is that fix. It runs inside the one deploy job, after the
# application release is serving, and moves the live bundle to the desired one
# only when they differ. Because every deploy converges both subsystems, an
# evicted run is subsumed by the run that evicted it, for both.
#
# # What it does not do
#
# It never reads, writes, or transmits patient data. It only ever touches
# `<deploy-dir>/public/ohif`, which holds the static OHIF viewer bundle.

set -euo pipefail

for name in OHIF_SSH_TARGET OHIF_DEPLOY_DIR OHIF_BUNDLE_DIR; do
    if [[ -z "${!name:-}" ]]; then
        echo "Required OHIF convergence value is missing: ${name}" >&2
        exit 2
    fi
done

# Same target/dir shapes the deployment verifier already enforces, so a
# malformed secret cannot turn into an ssh option or a path escape.
if [[ ! "$OHIF_SSH_TARGET" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*(@[A-Za-z0-9][A-Za-z0-9.-]*)?$ ]]; then
    echo 'OHIF_SSH_TARGET is unsafe.' >&2
    exit 2
fi
case "$OHIF_DEPLOY_DIR" in *[!A-Za-z0-9._-]*|'') echo 'OHIF_DEPLOY_DIR is unsafe.' >&2; exit 2 ;; esac
if [[ ! -d "$OHIF_BUNDLE_DIR" ]]; then
    echo 'OHIF_BUNDLE_DIR is not a directory.' >&2
    exit 2
fi

readonly ssh_bin="${PHR_OHIF_SSH_BIN:-ssh}"
readonly rsync_bin="${PHR_OHIF_RSYNC_BIN:-rsync}"
# The bundle must look like an OHIF build before it is allowed near production.
readonly entrypoint="$OHIF_BUNDLE_DIR/index.html"
# Cleared before the tree is touched and rewritten only after the transfer
# succeeds, so the record is present only while it describes what is live.
readonly digest_name='.ohif-digest'
readonly remote_root="\$HOME/$OHIF_DEPLOY_DIR/public/ohif"

if [[ ! -f "$entrypoint" || -L "$entrypoint" ]]; then
    echo 'The desired OHIF bundle has no regular index.html; refusing to deploy it.' >&2
    exit 1
fi
if ! grep -qiE '<title[^>]*>[^<]*OHIF[^<]*</title>' "$entrypoint"; then
    echo 'The desired OHIF bundle does not identify itself as an OHIF viewer build.' >&2
    exit 1
fi

# A digest over every path *and* every content byte, so a renamed, added, or
# deleted file changes it. LC_ALL=C fixes the sort order across runners, and
# -print0/-z keep it correct for any filename the build can produce.
bundle_digest() {
    local root="$1"
    (
        cd "$root"
        LC_ALL=C find . -type f ! -name "$digest_name" -print0 \
            | LC_ALL=C sort -z \
            | xargs -0 -r sha256sum \
            | sha256sum \
            | cut -d' ' -f1
    )
}

desired_digest="$(bundle_digest "$OHIF_BUNDLE_DIR")"
if [[ ! "$desired_digest" =~ ^[0-9a-f]{64}$ ]]; then
    echo 'Could not compute a digest for the desired OHIF bundle.' >&2
    exit 1
fi
readonly desired_digest

# An unreadable, absent, or malformed record reads as empty, which simply
# means "converge". Note that the validation below is defensive rather than
# load-bearing: a malformed value could never equal a real digest anyway, so
# it would force a redeploy with or without the check.
live_digest="$("$ssh_bin" "$OHIF_SSH_TARGET" \
    "cat $remote_root/$digest_name 2>/dev/null || true" | tr -d '[:space:]')"
if [[ ! "$live_digest" =~ ^[0-9a-f]{64}$ ]]; then
    live_digest=''
fi
readonly live_digest

if [[ "$live_digest" == "$desired_digest" ]]; then
    echo "OHIF bundle already converged at ${desired_digest:0:12}; nothing to deploy."
    exit 0
fi

if [[ -n "$live_digest" ]]; then
    echo "Converging OHIF bundle ${live_digest:0:12} -> ${desired_digest:0:12}."
else
    echo "Converging OHIF bundle to ${desired_digest:0:12}; no recorded live bundle."
fi

"$ssh_bin" "$OHIF_SSH_TARGET" "mkdir -p $remote_root"

# The record is removed *before* the tree is touched and written back only
# after the transfer succeeds, so it exists only while it actually describes
# what is live. Keeping the old record across a transfer would be worse than
# having none: rsync deletes and rewrites files as it goes, so the moment the
# transfer starts the old digest is a false claim about a tree that no longer
# matches it. An interrupted deploy therefore leaves no record, and the next
# deploy converges rather than trusting a stale one.
"$ssh_bin" "$OHIF_SSH_TARGET" "rm -f $remote_root/$digest_name"

# --delete is scoped to public/ohif so stale asset hashes go away, and rsync
# never sees a path outside the two endpoints named here. The record is
# excluded so that this step neither depends on nor fights the removal above.
"$rsync_bin" -a --delete "--exclude=$digest_name" \
    "$OHIF_BUNDLE_DIR/" "$OHIF_SSH_TARGET:$remote_root/"

printf '%s\n' "$desired_digest" \
    | "$ssh_bin" "$OHIF_SSH_TARGET" "cat > $remote_root/$digest_name"

confirmed="$("$ssh_bin" "$OHIF_SSH_TARGET" \
    "cat $remote_root/$digest_name 2>/dev/null || true" | tr -d '[:space:]')"
if [[ "$confirmed" != "$desired_digest" ]]; then
    echo 'The OHIF bundle digest did not read back as the bundle that was just deployed.' >&2
    exit 1
fi

echo "OHIF bundle converged to ${desired_digest:0:12}."
