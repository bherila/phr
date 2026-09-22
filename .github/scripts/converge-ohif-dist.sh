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
# This script is that fix. Both writers in the group run it: CI's deploy job
# runs it *before* the shared cPanel action releases the application (the
# action's verification then proves the bundle this step published), and the
# OHIF workflow's deploy job runs it as its only remote write. It moves the
# live bundle to the newest built one only when the recorded digest says they
# differ, so an application deploy that evicts a pending OHIF deploy converges
# OHIF on its behalf.
#
# The repair is one-directional, not symmetric. The OHIF deploy job ships no
# application code, so an OHIF deploy that evicts a pending application deploy
# does not subsume it; that application change waits for the next CI deploy.
# What this script guarantees is only that no OHIF bundle is lost to eviction.
#
# # Where the bundle actually lives
#
# `public/ohif` is a *persistent path* of the shared cPanel deployment action
# (`bherila/shared-cpanel-deployment`, `atomic-layout: stable-directory`). The
# canonical tree is therefore not the path the web server reaches directly. It
# is the managed shared directory
#
#     ~/.deployments/<deploy-dir>/shared/public/ohif      (canonical, real dir)
#
# and the path under the serving release
#
#     ~/<deploy-dir>/public/ohif                          (a symlink to it)
#
# is a link the action creates and maintains. Writing through the link happens
# to work while the link is healthy, and is catastrophic when it is not: a bare
# `mkdir -p` on the live path turns an absent link into a *second real tree*,
# and the action's `preflight` then refuses the next deploy outright --
# "Both live '...' and managed '...' exist; refusing to choose or delete either
# copy." That refusal is correct on its part. This script's job is to never
# manufacture the conflict, so it resolves and validates the destination first
# and only ever transfers into a directory it has proven is the intended one.
#
# # What it does not do
#
# It never reads, writes, or transmits patient data. It only ever touches the
# OHIF static viewer bundle: the managed shared `public/ohif` tree, or -- for a
# not-yet-converted legacy install -- the real `public/ohif` directory inside
# the application root. It never deletes or chooses between two pre-existing
# copies, and it never creates an application root.

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
# The shared action's own `plain_name`: a non-hidden account-home name. The
# character class alone is not enough -- `.` and `..` contain only permitted
# characters and would both escape the account home.
case "$OHIF_DEPLOY_DIR" in
    ''|.|..|.*|*[!A-Za-z0-9._-]*) echo 'OHIF_DEPLOY_DIR is unsafe.' >&2; exit 2 ;;
esac
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
# The record format this script trusts. Records written before it -- a bare
# sha256 -- came from a transfer that used rsync's size+mtime quick check, so
# one can claim a bundle whose bytes never actually landed. Only a record in
# this format, which is written exclusively after a `--checksum` transfer, may
# short-circuit a deploy; anything else is an untrusted cache entry.
readonly record_prefix='v2:'

# Remote paths come in two flavours and the difference is load-bearing.
#
# `rsync`'s destination must be written with a leading `~` and never `$HOME`.
# By default rsync passes the remote path through the remote shell but
# backslash-escapes it first, to protect it from that shell's expansions: a
# `$HOME` in the destination arrives as `\$HOME`, is never expanded, and the
# receiver creates a directory literally named `$HOME` in the login directory.
# A leading `~` is deliberately left unescaped, so the remote shell does expand
# it. That is why `~` is the one portable spelling here. (rsync's
# `--secluded-args`/`-s`, which drops the path from the remote argv entirely
# and sends it over the protocol instead, is an *optional* compiled capability
# -- `rsync --version` on 3.2.7 lists it under "optional secluded-args" -- and
# is not on by default; the default really is the escaped-argv behaviour above.)
#
# The `ssh` commands below are ordinary remote shell commands rather than rsync
# arguments, so nothing escapes them and they use `$HOME` directly, expanded on
# the remote side.
#
# Destinations are carried as paths *relative to the remote account home*. The
# rsync spelling prefixes `~/`; the remote shell scripts prefix `$HOME/`.
readonly managed_rel=".deployments/$OHIF_DEPLOY_DIR/shared/public/ohif"
readonly live_rel="$OHIF_DEPLOY_DIR/public/ohif"
# shellcheck disable=SC2088  # The tilde is deliberately NOT expanded locally:
# it has to reach the remote side as a tilde. SC2088's suggested fix, `$HOME`,
# is the exact bug this comment block describes.
readonly remote_managed="~/$managed_rel"
# shellcheck disable=SC2088  # Remote-side tilde; see remote_managed above.
readonly remote_live="~/$live_rel"

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

# Every remote command is a script on stdin rather than a quoted argument
# string, so nothing this script sends depends on remote-shell word splitting.
# `$OHIF_DEPLOY_DIR` is validated above to a plain account-home name, and is
# the only interpolation.
remote_bash() {
    "$ssh_bin" "$OHIF_SSH_TARGET" 'bash -s'
}

# Remote definitions shared by the read-only inspection and by every step that
# mutates the remote tree. The mutating steps re-run these checks themselves,
# immediately before they act, so the layout they write into is the one that
# was validated rather than whatever occupies the path by then.
#
# `app_state` mirrors the pinned shared action (bherila/shared-cpanel-deployment,
# scripts/atomic-release.sh): `selected_target` treats a real directory holding
# `artisan` as the application, and takes a symlink's *literal* target, which
# `validate_release_target` accepts only when it is exactly
# `.deployments/<deploy-dir>/releases/<plain-name>` and names a real directory
# holding `artisan`. Any other symlink -- including one that resolves to a tree
# that merely looks like Laravel -- is `bad-symlink`, not an application root.
# (The `releases`/control-directory symlink checks are stricter than the action,
# which only checks the leaf; the action refuses symlinked control paths
# elsewhere, so this never refuses a layout the action would accept.)
remote_prelude() {
    printf 'deploy_dir=%q\n' "$OHIF_DEPLOY_DIR"
    cat <<'REMOTE'
app_root=$HOME/$deploy_dir
control=$HOME/.deployments/$deploy_dir
shared=$control/shared

path_type() {
    if [ -L "$1" ]; then echo symlink
    elif [ -d "$1" ]; then echo dir
    elif [ -f "$1" ]; then echo file
    elif [ -e "$1" ]; then echo other
    else echo absent
    fi
}

app_state() {
    local target release
    if [ -L "$app_root" ]; then
        target=$(readlink "$app_root") || { echo bad-symlink; return; }
        release=${target##*/}
        case $release in
            '' | . | .. | .* | *[!A-Za-z0-9._-]*) echo bad-symlink; return ;;
        esac
        if [ "$target" = ".deployments/$deploy_dir/releases/$release" ] \
            && [ ! -L "$HOME/.deployments" ] && [ ! -L "$control" ] \
            && [ -d "$control/releases" ] && [ ! -L "$control/releases" ] \
            && [ -d "$HOME/$target" ] && [ ! -L "$HOME/$target" ] \
            && [ -f "$HOME/$target/artisan" ]; then
            echo release-symlink
        else
            echo bad-symlink
        fi
    elif [ -d "$app_root" ]; then
        if [ -f "$app_root/artisan" ]; then echo laravel; else echo other; fi
    elif [ -e "$app_root" ]; then echo other
    else echo absent
    fi
}
REMOTE
}

# Report the remote layout as key=value lines. Reads only: this runs before any
# decision is made, so it must never create or modify anything.
#
# `readlink -m` canonicalizes without requiring the path to exist, so a
# dangling link still reports the target it *intends*, which is exactly what
# has to be compared against the canonical managed directory.
inspect_remote() {
    {
        remote_prelude
        cat <<'REMOTE'
set -u
printf 'app=%s\n' "$(app_state)"
printf 'app_link=%s\n' "$(readlink "$app_root" 2>/dev/null || true)"
printf 'app_public=%s\n' "$(path_type "$app_root/public")"
printf 'live=%s\n' "$(path_type "$app_root/public/ohif")"
printf 'live_link=%s\n' "$(readlink "$app_root/public/ohif" 2>/dev/null || true)"
printf 'live_canon=%s\n' "$(readlink -m "$app_root/public/ohif" 2>/dev/null || true)"
printf 'deployments=%s\n' "$(path_type "$HOME/.deployments")"
printf 'control=%s\n' "$(path_type "$control")"
printf 'shared=%s\n' "$(path_type "$shared")"
printf 'shared_public=%s\n' "$(path_type "$shared/public")"
printf 'managed=%s\n' "$(path_type "$shared/public/ohif")"
printf 'managed_canon=%s\n' "$(readlink -m "$shared/public/ohif" 2>/dev/null || true)"
printf 'ok=1\n'
REMOTE
    } | remote_bash
}

declare -A fact=()
while IFS='=' read -r key value; do
    case "$key" in
        app|app_link|app_public|live|live_link|live_canon|deployments|control|shared|shared_public|managed|managed_canon|ok)
            fact["$key"]="$value" ;;
    esac
done < <(inspect_remote)

if [[ "${fact[ok]:-}" != 1 ]]; then
    echo 'Could not inspect the remote OHIF layout; refusing to deploy blind.' >&2
    exit 1
fi

refuse() {
    echo "$1" >&2
    echo "Nothing was changed on the remote host." >&2
    exit 1
}

# --- resolve the destination ------------------------------------------------
#
# Everything below decides *where* the bundle goes and proves the surrounding
# structure is the one the shared action maintains. No remote state is mutated
# until this has produced a destination, so an ambiguous or unsafe layout is
# refused before any marker removal or `rsync --delete`.

# The action's `preflight`/`link_persistent_path` both refuse a symlinked
# managed shared path outright, so this script must never create or tolerate
# one -- it would convert a recoverable layout into a manual recovery.
case "${fact[managed]}" in
    absent|dir) ;;
    symlink) refuse "The managed shared OHIF path is a symlink; the deployment action forbids that and will refuse the next release. Resolve $remote_managed by hand." ;;
    *) refuse "The managed shared OHIF path exists but is not a directory (${fact[managed]}). Resolve $remote_managed by hand." ;;
esac
for control_path in deployments:'~/.deployments' control:"~/.deployments/$OHIF_DEPLOY_DIR" \
                    shared:"~/.deployments/$OHIF_DEPLOY_DIR/shared" \
                    shared_public:"~/.deployments/$OHIF_DEPLOY_DIR/shared/public"; do
    control_key="${control_path%%:*}"
    case "${fact[$control_key]}" in
        absent|dir) ;;
        *) refuse "Deployment control path ${control_path#*:} is a ${fact[$control_key]}, not a real directory. Resolve it by hand." ;;
    esac
done

# The application root and its `public` ancestor, checked once for every
# layout rather than per branch, so no branch can write through either. The
# rules are the pinned action's: an app-root symlink must be a managed release
# link (`app_state` above), and a persistent path may not traverse a symlinked
# or non-directory ancestor -- `ensure_safe_ancestors`, which `preflight` runs
# against the selected root and `link_persistent_path` against every root it
# links. A `public` that is a symlink would let the legacy-directory transfer,
# or the restored live link, land in whatever tree it points at.
case "${fact[app]}" in
    laravel|release-symlink|absent) ;;
    bad-symlink)
        # shellcheck disable=SC2088  # A message, not a path to expand.
        refuse "~/$OHIF_DEPLOY_DIR is a symlink to '${fact[app_link]}', which is not a managed release directory (.deployments/$OHIF_DEPLOY_DIR/releases/<name> holding artisan). The deployment action refuses it too; resolve it by hand before deploying OHIF." ;;
    *)
        # shellcheck disable=SC2088  # A message, not a path to expand.
        refuse "~/$OHIF_DEPLOY_DIR exists but is neither an application root nor a managed release symlink (${fact[app]}). The deployment action will refuse it too; resolve it by hand before deploying OHIF." ;;
esac
case "${fact[app_public]}" in
    absent|dir) ;;
    *)
        # shellcheck disable=SC2088  # A message, not a path to expand.
        refuse "~/$OHIF_DEPLOY_DIR/public is a ${fact[app_public]}, not a real directory. The deployment action refuses a persistent path under a symlinked or non-directory ancestor; refusing to write through it." ;;
esac

# `mode` records which layout was recognised; `destination` is the remote path
# the bundle is transferred into; `create_destination` says whether this script
# may establish it (only ever the canonical managed directory).
create_destination=false
restore_live_link=false

case "${fact[live]}" in
    symlink)
        # An arbitrary symlink is not proof of the intended target. Compare the
        # canonical resolution, which is also what the action's preflight does.
        if [[ -z "${fact[live_canon]}" || -z "${fact[managed_canon]}" \
              || "${fact[live_canon]}" != "${fact[managed_canon]}" ]]; then
            refuse "The live OHIF path $remote_live is a symlink to '${fact[live_link]}' (resolving to '${fact[live_canon]:-nothing}'), not to the managed shared directory '${fact[managed_canon]:-unknown}'. Refusing to write through it."
        fi
        destination_rel="$managed_rel"
        if [[ "${fact[managed]}" == absent ]]; then
            mode='repair-dangling-link'
            create_destination=true
        else
            mode='managed'
        fi
        ;;
    dir)
        # A real directory at the live path is only legitimate while the
        # install has not been converted yet: the action migrates it into the
        # managed shared tree with `mv -T` on the next release. If a managed
        # copy also exists, the two are exactly the ambiguity the action
        # refuses to resolve, and so does this.
        if [[ "${fact[managed]}" != absent ]]; then
            refuse "Both the live OHIF directory $remote_live and the managed shared directory $remote_managed exist. Refusing to choose between or delete either copy; reconcile them by hand."
        fi
        # The application root and `public` were proven above; a real `public`
        # under a real or managed-release root is the only place this may be.
        case "${fact[app]}:${fact[app_public]}" in
            laravel:dir|release-symlink:dir) ;;
            *) refuse "The live OHIF path $remote_live is a real directory but ~/$OHIF_DEPLOY_DIR is not an application root with a real public directory (app=${fact[app]}, public=${fact[app_public]}). Refusing to write into an unrecognised layout." ;;
        esac
        destination_rel="$live_rel"
        mode='legacy-directory'
        ;;
    absent)
        destination_rel="$managed_rel"
        if [[ "${fact[managed]}" == absent ]]; then
            create_destination=true
        fi
        case "${fact[app]}" in
            laravel|release-symlink)
                if [[ "${fact[app_public]}" == dir ]]; then
                    # The confirmed recovery case: the managed tree is the only
                    # copy and the live link is gone. Creating a directory here
                    # would manufacture the two-copy conflict; the link is what
                    # is missing, so the link is what gets restored -- and only
                    # after the bundle is in place.
                    mode='restore-live-link'
                    restore_live_link=true
                else
                    mode='stage-managed'
                fi
                ;;
            absent)
                # Genuine bootstrap. Staging into managed storage is what lets
                # the action's `prepare` link the first release to it; creating
                # a skeletal ~/<deploy-dir> instead would make an absent
                # install look like an unsupported or legacy one and hard-fail
                # the action.
                mode='bootstrap-stage'
                ;;
            *)
                # Unreachable while the application-root gate above holds;
                # kept so a future state cannot fall through to a write.
                refuse "Unrecognised application root state '${fact[app]}'."
                ;;
        esac
        ;;
    *)
        refuse "The live OHIF path $remote_live is a ${fact[live]}, which is not a supported layout. Resolve it by hand."
        ;;
esac
readonly destination_rel mode create_destination restore_live_link
# shellcheck disable=SC2088  # Remote-side tilde again; see remote_managed.
readonly destination="~/$destination_rel"

echo "OHIF layout: app=${fact[app]} live=${fact[live]} managed=${fact[managed]}; resolved destination $destination (${mode})."

# --- the digest gate --------------------------------------------------------
#
# Read only after the destination has been resolved and the structure proven,
# so a matching digest can never short-circuit past a layout that still needs
# repairing.
read_live_record() {
    {
        printf 'destination_rel=%q\n' "$destination_rel"
        printf 'digest_name=%q\n' "$digest_name"
        cat <<'REMOTE'
set -u
cat "$HOME/$destination_rel/$digest_name" 2>/dev/null || true
REMOTE
    } | remote_bash | tr -d '[:space:]'
}

# Only a `v2:` record is evidence of what is live, because only this script's
# `--checksum` transfer writes one. A bare sha256 is a legacy record from the
# quick-check transfer: it may claim bytes that never landed (same size, same
# mtime, different content), so it is an untrusted cache entry and forces the
# full convergence below, which repairs the tree and replaces it with a `v2:`
# record. An absent or unrecognised record is treated the same way. Nothing
# but a trusted record equal to the desired one takes the no-op path.
live_record="$(read_live_record)"
live_digest=''
case "$live_record" in
    '')
        record_state=absent ;;
    "$record_prefix"*)
        if [[ "${live_record#"$record_prefix"}" =~ ^[0-9a-f]{64}$ ]]; then
            record_state=trusted
            live_digest="${live_record#"$record_prefix"}"
        else
            record_state=unrecognised
        fi
        ;;
    *)
        if [[ "$live_record" =~ ^[0-9a-f]{64}$ ]]; then record_state=legacy; else record_state=unrecognised; fi
        ;;
esac
readonly live_record record_state live_digest

# Everything that mutates the remote tree runs this first, on the remote side
# and in the same shell as the mutation. It re-proves what the inspection
# proved about the application root, its `public` ancestor, and the managed
# control chain, so a layout that changed after inspection is refused rather
# than written through. (It narrows the window; it cannot make the later rsync
# atomic with it.)
remote_guard() {
    remote_prelude
    printf 'expect_app=%q\n' "${fact[app]}"
    printf 'expect_app_link=%q\n' "${fact[app_link]}"
    cat <<'REMOTE'
set -eu
guard_fail() { echo "$1; not writing." >&2; exit 1; }
[ "$(app_state)" = "$expect_app" ] \
    || guard_fail "~/$deploy_dir is now '$(app_state)', not the '$expect_app' that was inspected"
if [ "$expect_app" != absent ]; then
    [ "$(readlink "$app_root" 2>/dev/null || true)" = "$expect_app_link" ] \
        || guard_fail "~/$deploy_dir was retargeted after inspection"
    case $(path_type "$app_root/public") in
        absent | dir) ;;
        *) guard_fail "~/$deploy_dir/public is not a real directory" ;;
    esac
fi
for directory in "$HOME/.deployments" "$control" "$shared" "$shared/public" "$shared/public/ohif"; do
    case $(path_type "$directory") in
        absent | dir) ;;
        *) guard_fail "Deployment control path $directory is not a real directory" ;;
    esac
done
REMOTE
}

# Restoring the missing live symlink happens whether or not the bundle itself
# needed transferring, because its absence blocks the *next* application
# release: the action's `prepare` requires an established release to link every
# persistent path to managed shared state, and errors out when it does not.
# The guard re-validates the application root and `public` first, and the link
# is only ever created where nothing exists: a path that reappeared is never
# replaced.
restore_link_if_needed() {
    [[ "$restore_live_link" == true ]] || return 0
    {
        remote_guard
        cat <<'REMOTE'
live=$app_root/public/ohif
managed=$shared/public/ohif
[ -d "$app_root/public" ] && [ ! -L "$app_root/public" ] || { echo "~/$deploy_dir/public is not a real directory; not linking into it." >&2; exit 1; }
[ ! -e "$live" ] && [ ! -L "$live" ] || { echo "The live OHIF path reappeared; not replacing it." >&2; exit 1; }
[ -d "$managed" ] && [ ! -L "$managed" ] || { echo "The managed shared OHIF directory is not a real directory; not linking to it." >&2; exit 1; }
ln -s "$managed" "$live"
[ -L "$live" ] || { echo "The live OHIF symlink was not created." >&2; exit 1; }
REMOTE
    } | remote_bash
    echo "Restored the live OHIF symlink $remote_live -> $remote_managed."
}

if [[ "$record_state" == trusted && "$live_digest" == "$desired_digest" ]]; then
    echo "OHIF bundle already converged at ${desired_digest:0:12}; nothing to transfer."
    restore_link_if_needed
    exit 0
fi

case "$record_state" in
    trusted)
        echo "Converging OHIF bundle ${live_digest:0:12} -> ${desired_digest:0:12}." ;;
    legacy)
        echo "The recorded OHIF digest ${live_record:0:12} is a legacy record from a quick-check transfer and is not trusted; forcing a full checksum convergence to ${desired_digest:0:12}." ;;
    unrecognised)
        echo "The recorded OHIF digest is not in a recognised format and is not trusted; forcing a full checksum convergence to ${desired_digest:0:12}." ;;
    *)
        echo "Converging OHIF bundle to ${desired_digest:0:12}; no recorded live bundle." ;;
esac

# Only ever the canonical managed directory, and only when the inspection
# proved every ancestor is absent or already a real directory. The control
# ancestors get the same 700 the action's own `ensure_real_dir` and
# `ensure_safe_ancestors` use, so a later release finds the layout it expects.
if [[ "$create_destination" == true ]]; then
    {
        remote_guard
        cat <<'REMOTE'
# Only ever create what is missing. `install -d` would also re-apply the mode
# to directories that already exist, and these are the deployment action's own
# control directories, not this script's to re-permission.
for directory in "$HOME/.deployments" "$control" "$shared" "$shared/public"; do
    [ -d "$directory" ] || install -d -m 700 "$directory"
done
[ -d "$shared/public/ohif" ] || install -d -m 755 "$shared/public/ohif"
REMOTE
    } | remote_bash
    echo "Established the managed shared OHIF directory $remote_managed."
fi

# The record is removed *before* the tree is touched and written back only
# after the transfer succeeds, so it exists only while it actually describes
# what is live. Keeping the old record across a transfer would be worse than
# having none: rsync deletes and rewrites files as it goes, so the moment the
# transfer starts the old digest is a false claim about a tree that no longer
# matches it. An interrupted deploy therefore leaves no record, and the next
# deploy converges rather than trusting a stale one. The guard runs first, and
# the destination itself must be a real directory by now: it either existed or
# was just established above.
{
    remote_guard
    printf 'destination_rel=%q\n' "$destination_rel"
    printf 'digest_name=%q\n' "$digest_name"
    cat <<'REMOTE'
[ -d "$HOME/$destination_rel" ] && [ ! -L "$HOME/$destination_rel" ] \
    || guard_fail "The OHIF destination ~/$destination_rel is not a real directory"
rm -f "$HOME/$destination_rel/$digest_name"
REMOTE
} | remote_bash

# --delete is scoped to the resolved OHIF directory so stale asset hashes go
# away, and rsync never sees a path outside the two endpoints named here. The
# record is excluded so that this step neither depends on nor fights the
# removal above.
#
# --checksum is not optional here, it is what makes the published digest
# honest. rsync's default quick check compares size and modification time and
# skips a file when both match; two different builds of `index.html` can easily
# be the same size, and an artifact download can easily reproduce a timestamp.
# A skipped file would leave the live tree differing from the bundle whose
# digest this script then records as converged -- and because the record then
# matches, every later deploy would short-circuit and never repair it. The
# bundle is small and deploys are infrequent, so paying for a checksum pass is
# the cheap side of that trade. It is also why a bare legacy record, written
# before this transfer used --checksum, is not trusted by the gate above. (The
# real-transport suite pins both down with a fixture whose two versions share a
# size and a timestamp, and marks the resulting failure `STALE-CONTENT:`.)
"$rsync_bin" -a --checksum --delete "--exclude=$digest_name" \
    "$OHIF_BUNDLE_DIR/" "$OHIF_SSH_TARGET:$destination/"

{
    printf 'destination_rel=%q\n' "$destination_rel"
    printf 'digest_name=%q\n' "$digest_name"
    printf 'record=%q\n' "$record_prefix$desired_digest"
    cat <<'REMOTE'
set -eu
printf '%s\n' "$record" > "$HOME/$destination_rel/$digest_name"
REMOTE
} | remote_bash

confirmed="$(read_live_record)"
if [[ "$confirmed" != "$record_prefix$desired_digest" ]]; then
    echo 'The OHIF bundle digest did not read back as the bundle that was just deployed.' >&2
    exit 1
fi

restore_link_if_needed

echo "OHIF bundle converged to ${desired_digest:0:12} at $destination."
