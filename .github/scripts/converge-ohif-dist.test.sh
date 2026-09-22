#!/usr/bin/env bash
# Fast offline harness for the OHIF convergence step.
#
# `ssh` and `rsync` are stubbed against a local directory standing in for the
# server, so the whole matrix runs in a second with no network and no daemon.
#
# THIS SUITE CANNOT PROVE THE TRANSPORT. A stub embodies the assumptions of
# whoever wrote it, and a stub that quietly "helpfully" expanded `$HOME` is
# precisely what let the broken rsync destination ship. What it does prove is
# the decision table: which destination the script resolves, what it refuses,
# and what it leaves untouched. The transport, the receiver's behaviour, and
# the shared deployment action's real preflight are proved by
# `converge-ohif-dist.integration.test.sh`, which uses a real sshd, real ssh
# and real rsync. Run both.

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly script_dir
readonly converge="$script_dir/converge-ohif-dist.sh"
test_root="$(mktemp -d)"
readonly test_root
trap 'rm -rf "$test_root"' EXIT

readonly remote_home="$test_root/remote-home"
readonly rsync_log="$test_root/rsync.log"

# Stands in for ssh. The convergence script sends every remote command as a
# script on stdin, so this runs that script under a shell whose HOME points at
# the fake server tree -- which is how a real remote shell would expand it.
cat >"$test_root/ssh" <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
shift # the ssh target
if [[ "$*" != 'bash -s' ]]; then
    echo "unexpected remote command form: $*" >&2
    exit 1
fi
script=$(cat)
printf '%s\n--\n' "$script" >>"$PHR_TEST_SSH_LOG"
# Stands in for a server that accepts the digest write and does not persist it
# -- a full or read-only filesystem -- so the read-back has something to catch.
if [[ "${PHR_TEST_DIGEST_WRITE_FAILS:-false}" == true \
      && "$script" == *'> "$HOME/$destination_rel/$digest_name"'* ]]; then
    exit 0
fi
HOME="$PHR_TEST_REMOTE_HOME" exec bash -s <<<"$script"
SCRIPT

# Stands in for rsync: mirrors the source into the destination with --delete
# and --exclude honored, and can be made to fail on demand.
cat >"$test_root/rsync" <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
exclude=''
args=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        --exclude=*) exclude="${1#--exclude=}"; shift ;;
        -a|--checksum|--delete) shift ;;
        *) args+=("$1"); shift ;;
    esac
done
source="${args[0]}"
destination="${args[1]#*:}"
# By default rsync backslash-escapes the remote arguments it hands to the
# remote shell, so a `$HOME` in the destination arrives as `\$HOME`, is never
# expanded, and the receiver creates a directory literally named `$HOME`. Only
# a leading `~`, which rsync deliberately leaves unescaped for the remote shell
# to expand, works. (`--secluded-args`/`-s`, which keeps the path out of the
# remote argv entirely, is an optional capability and is NOT the default.)
# An earlier version of this stub substituted `$HOME` here, which made a broken
# destination look fine and hid a bug that would have failed every deploy.
# Refusing it outright is what stops that returning; the real receiver's
# behaviour is proved in the integration suite, not here.
if [[ "$destination" == *'$HOME'* ]]; then
    echo 'rsync destination uses $HOME, which a real receiver will not expand.' >&2
    exit 1
fi
case "$destination" in
    '~/'*) destination="$PHR_TEST_REMOTE_HOME/${destination#\~/}" ;;
    /*) ;;
    *) echo "rsync destination is neither absolute nor ~-relative: $destination" >&2; exit 1 ;;
esac
printf '%s -> %s\n' "$source" "$destination" >>"$PHR_TEST_RSYNC_LOG"
# Real rsync creates the final destination directory when it is missing.
mkdir -p "$destination"
# --delete, minus the excluded record. Real rsync deletes and rewrites as it
# goes, so a synthetic failure has to happen *after* the destination has been
# mutated or it cannot exercise what a half-finished transfer leaves behind.
if [[ -n "$exclude" ]]; then
    find "$destination" -mindepth 1 -maxdepth 1 ! -name "$exclude" -exec rm -rf {} +
else
    find "$destination" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
fi
[[ "${PHR_TEST_RSYNC_FAILS:-false}" != true ]] || { echo 'synthetic rsync failure' >&2; exit 1; }
cp -a "$source". "$destination"
SCRIPT
chmod +x "$test_root/ssh" "$test_root/rsync"

export PHR_OHIF_SSH_BIN="$test_root/ssh"
export PHR_OHIF_RSYNC_BIN="$test_root/rsync"
export PHR_TEST_REMOTE_HOME="$remote_home"
export PHR_TEST_RSYNC_LOG="$rsync_log"
export PHR_TEST_SSH_LOG="$test_root/ssh.log"
export OHIF_SSH_TARGET=cpanel-deploy@host.example.test
export OHIF_DEPLOY_DIR=phr-laravel

readonly app_root="$remote_home/phr-laravel"
readonly live="$app_root/public/ohif"
readonly control="$remote_home/.deployments/phr-laravel"
readonly shared="$control/shared"
readonly managed="$shared/public/ohif"

fail() { echo "FAIL: $1" >&2; exit 1; }

# --- fixtures ---------------------------------------------------------------
#
# Sentinel files sit in every directory the script must not touch. Their
# combined digest is compared across every scenario below, so a stray rm, a
# misdirected --delete, or a transfer into the wrong tree shows up as a diff
# rather than as a silently passing test.
sentinels() {
    (cd "$remote_home" && LC_ALL=C find . -name SENTINEL -print0 \
        | LC_ALL=C sort -z | xargs -0 -r sha256sum)
}

reset_server() {
    rm -rf "$remote_home" "$rsync_log" "$PHR_TEST_SSH_LOG"
    mkdir -p "$remote_home"
    printf 'home\n' >"$remote_home/SENTINEL"
}

# A managed real-directory application root, as `atomic-layout:
# stable-directory` leaves it.
make_app() {
    mkdir -p "$app_root/public" "$app_root/storage"
    : >"$app_root/artisan"
    printf 'release=r1\ncommit=abcdef1234567\n' >"$app_root/.deploy-release"
    printf 'app\n' >"$app_root/SENTINEL"
    printf 'public\n' >"$app_root/public/SENTINEL"
    printf 'storage\n' >"$app_root/storage/SENTINEL"
}

# The managed shared tree the action keeps persistent paths in.
make_shared() {
    mkdir -p "$shared/public" "$shared/storage"
    printf 'shared\n' >"$shared/SENTINEL"
    printf 'shared-storage\n' >"$shared/storage/SENTINEL"
}

# A populated OHIF tree at an arbitrary path, tagged so its provenance is
# visible in assertions.
make_tree() {
    local dir="$1" tag="$2"
    mkdir -p "$dir/app"
    printf '<html><head><title>OHIF Viewer %s</title></head><body></body></html>\n' "$tag" >"$dir/index.html"
    printf 'console.log("%s");\n' "$tag" >"$dir/app/main.js"
}

make_bundle() {
    local dir="$1" tag="$2"
    rm -rf "$dir"
    make_tree "$dir" "$tag"
}

tag_at() {
    sed -n 's/.*<title>OHIF Viewer \(.*\)<\/title>.*/\1/p' "$1/index.html" 2>/dev/null || true
}

rsync_count() {
    [[ -f "$rsync_log" ]] && wc -l <"$rsync_log" | tr -d ' ' || echo 0
}

# Every scenario runs through this so the sentinel comparison can never be
# forgotten for a new case.
scenario() {
    scenario_name="$1"
    scenario_sentinels="$(sentinels)"
}
end_scenario() {
    [[ "$(sentinels)" == "$scenario_sentinels" ]] \
        || fail "$scenario_name: files outside the intended destination changed."
    echo "  ok: $scenario_name"
}

make_bundle "$test_root/v1" v3.12.0
make_bundle "$test_root/v2" v3.13.0

# --- healthy managed symlink ------------------------------------------------
reset_server; make_app; make_shared
mkdir -p "$managed"; ln -s "$managed" "$live"
scenario 'healthy managed symlink converges into the managed directory'
OHIF_BUNDLE_DIR="$test_root/v1" "$converge" >/dev/null
[[ "$(tag_at "$managed")" == v3.12.0 ]] || fail 'The bundle did not reach the managed shared directory.'
[[ -L "$live" ]] || fail 'The live path stopped being a symlink.'
[[ "$(readlink "$live")" == "$managed" ]] || fail 'The live symlink was retargeted.'
[[ -f "$managed/.ohif-digest" ]] || fail 'No digest was recorded.'
[[ "$(rsync_count)" == 1 ]] || fail 'The bundle was not transferred exactly once.'
grep -qF -- "-> $managed" "$rsync_log" || fail "The transfer did not target the managed directory."
end_scenario

# --- repeat convergence is a no-op ------------------------------------------
scenario 'a converged bundle is not redeployed'
OHIF_BUNDLE_DIR="$test_root/v1" "$converge" >/dev/null
[[ "$(rsync_count)" == 1 ]] || fail 'A converged bundle was redeployed.'
[[ -L "$live" ]] || fail 'The no-op path disturbed the live symlink.'
end_scenario

# --- a changed bundle converges, stale assets go --------------------------
printf 'stale\n' >"$managed/app/old-hash.js"
scenario 'a changed bundle converges and deletes stale assets'
OHIF_BUNDLE_DIR="$test_root/v2" "$converge" >/dev/null
[[ "$(tag_at "$managed")" == v3.13.0 ]] || fail 'A changed bundle was not converged.'
[[ ! -e "$managed/app/old-hash.js" ]] || fail 'A stale asset survived the convergence.'
[[ "$(rsync_count)" == 2 ]] || fail 'A changed bundle did not transfer.'
end_scenario

# --- missing live link, shared tree present (issue: the confirmed bug) ------
#
# `mkdir -p` on the live path here is what produced a second real tree and made
# the shared action's preflight refuse the next release outright. The script
# must converge the managed tree and restore the *link*, never create a
# directory at the live path.
reset_server; make_app; make_shared
make_tree "$managed" v3.11.0
scenario 'a missing live link converges the shared tree and restores the link'
OHIF_BUNDLE_DIR="$test_root/v2" "$converge" >/dev/null
[[ -L "$live" ]] || fail 'The live OHIF path is not a symlink.'
[[ "$(readlink "$live")" == "$managed" ]] || fail 'The restored link does not point at the managed directory.'
[[ "$(tag_at "$managed")" == v3.13.0 ]] || fail 'The managed tree was not converged.'
[[ "$(rsync_count)" == 1 ]] || fail 'The bundle was not transferred exactly once.'
grep -qF -- "-> $managed" "$rsync_log" || fail "The transfer did not target the managed directory."
end_scenario

# --- matching digest with missing structure ---------------------------------
#
# Equal digests must not short-circuit past a layout that still needs
# repairing: the record describes the bundle, not the link that serves it.
rm -rf "$live"
[[ ! -e "$live" ]] || fail 'fixture: the live path was not removed.'
scenario 'a matching digest still repairs the missing live link'
OHIF_BUNDLE_DIR="$test_root/v2" "$converge" >/dev/null
[[ "$(rsync_count)" == 1 ]] || fail 'A matching digest still transferred the bundle.'
[[ -L "$live" ]] || fail 'A matching digest returned without restoring the live link.'
[[ "$(readlink "$live")" == "$managed" ]] || fail 'The restored link does not point at the managed directory.'
end_scenario

# --- matching digest with a wrong symlink -----------------------------------
reset_server; make_app; make_shared
make_tree "$managed" v3.13.0
mkdir -p "$remote_home/elsewhere"; make_tree "$remote_home/elsewhere" v3.13.0
printf 'elsewhere\n' >"$remote_home/elsewhere/SENTINEL"
ln -s "$remote_home/elsewhere" "$live"
# Give the decoy the digest the desired bundle would have, so a script that
# read the record before validating the layout would report "already
# converged" and leave production pointing somewhere it should not.
OHIF_BUNDLE_DIR="$test_root/v2" bash -c '
    cd "$1"; LC_ALL=C find . -type f ! -name .ohif-digest -print0 | LC_ALL=C sort -z \
        | xargs -0 -r sha256sum | sha256sum | cut -d" " -f1' _ "$test_root/v2" \
    >"$remote_home/elsewhere/.ohif-digest"
scenario 'a wrong live symlink is refused even when its digest matches'
if OHIF_BUNDLE_DIR="$test_root/v2" "$converge" >/dev/null 2>&1; then
    fail 'A live symlink pointing outside the managed tree was accepted.'
fi
[[ "$(rsync_count)" == 0 ]] || fail 'A refused layout still transferred.'
[[ "$(readlink "$live")" == "$remote_home/elsewhere" ]] || fail 'A refused layout was mutated.'
end_scenario

# --- dangling symlink to somewhere other than the managed tree --------------
reset_server; make_app; make_shared
mkdir -p "$managed"
ln -s "$remote_home/nowhere" "$live"
scenario 'a dangling live symlink to an unrelated path is refused'
if OHIF_BUNDLE_DIR="$test_root/v1" "$converge" >/dev/null 2>&1; then
    fail 'A dangling symlink to an unrelated path was accepted.'
fi
[[ "$(rsync_count)" == 0 ]] || fail 'A refused layout still transferred.'
[[ ! -e "$remote_home/nowhere" ]] || fail 'The dangling target was created.'
end_scenario

# --- dangling symlink to the managed tree: repairable -----------------------
reset_server; make_app; make_shared
ln -s "$managed" "$live"
[[ ! -e "$managed" ]] || fail 'fixture: the managed directory should be absent.'
scenario 'a live symlink to an absent managed directory is repaired'
OHIF_BUNDLE_DIR="$test_root/v1" "$converge" >/dev/null
[[ -d "$managed" && ! -L "$managed" ]] || fail 'The managed directory was not established as a real directory.'
[[ "$(tag_at "$managed")" == v3.12.0 ]] || fail 'The repaired managed directory has no bundle.'
[[ "$(readlink "$live")" == "$managed" ]] || fail 'The live symlink changed.'
end_scenario

# --- both copies present: refuse ambiguity ----------------------------------
reset_server; make_app; make_shared
make_tree "$managed" v3.11.0; printf 'managed\n' >"$managed/SENTINEL"
make_tree "$live" v3.10.0; printf 'live\n' >"$live/SENTINEL"
scenario 'two pre-existing copies are refused rather than reconciled'
if OHIF_BUNDLE_DIR="$test_root/v1" "$converge" >/dev/null 2>&1; then
    fail 'Simultaneous live and managed copies were accepted.'
fi
[[ "$(rsync_count)" == 0 ]] || fail 'An ambiguous layout still transferred.'
[[ "$(tag_at "$managed")" == v3.11.0 ]] || fail 'The managed copy was modified.'
[[ "$(tag_at "$live")" == v3.10.0 ]] || fail 'The live copy was modified.'
end_scenario

# --- legitimate legacy directory migration ----------------------------------
#
# A real `public/ohif` inside an unconverted application root, with no managed
# copy, is the state the action migrates with `mv -T` on the next release.
# Converging in place keeps that migration intact.
reset_server; make_app
rm -f "$app_root/.deploy-release"   # legacy: no release metadata
make_tree "$live" v3.10.0
scenario 'a legacy directory converges in place and is left migratable'
OHIF_BUNDLE_DIR="$test_root/v1" "$converge" >/dev/null
[[ -d "$live" && ! -L "$live" ]] || fail 'The legacy directory stopped being a real directory.'
[[ "$(tag_at "$live")" == v3.12.0 ]] || fail 'The legacy directory was not converged.'
[[ ! -e "$managed" ]] || fail 'A second copy was created in managed shared storage.'
grep -qF -- "-> $live" "$rsync_log" || fail "The transfer did not target the legacy directory."
end_scenario

# --- truly absent application: stage, never fabricate an app root -----------
reset_server
scenario 'a bootstrap stages into managed storage without creating an app root'
OHIF_BUNDLE_DIR="$test_root/v1" "$converge" >/dev/null
[[ ! -e "$app_root" ]] || fail 'A skeletal application root was created.'
[[ -d "$managed" && ! -L "$managed" ]] || fail 'The bundle was not staged into managed shared storage.'
[[ "$(tag_at "$managed")" == v3.12.0 ]] || fail 'The staged bundle is missing.'
[[ ! -L "$shared" && -d "$shared" ]] || fail 'The managed shared root is not a real directory.'
end_scenario

# --- an application root that is not an application -------------------------
#
# Exactly the damage the old `mkdir -p ~/<deploy-dir>/public/ohif` did on a
# host with no install: the action calls this layout unsupported and demands
# manual recovery, so the convergence step must say so rather than add to it.
reset_server
mkdir -p "$app_root/public/ohif"
printf 'squatter\n' >"$app_root/SENTINEL"
scenario 'a non-application directory at the deploy path is refused'
if OHIF_BUNDLE_DIR="$test_root/v1" "$converge" >/dev/null 2>&1; then
    fail 'A directory that is not an application root was accepted.'
fi
[[ "$(rsync_count)" == 0 ]] || fail 'An unrecognised layout still transferred.'
end_scenario

# --- the managed shared path must never be a symlink ------------------------
reset_server; make_app; make_shared
mkdir -p "$remote_home/elsewhere"; printf 'elsewhere\n' >"$remote_home/elsewhere/SENTINEL"
ln -s "$remote_home/elsewhere" "$managed"
ln -s "$managed" "$live"
scenario 'a symlinked managed shared path is refused'
if OHIF_BUNDLE_DIR="$test_root/v1" "$converge" >/dev/null 2>&1; then
    fail 'A symlinked managed shared path was accepted.'
fi
[[ "$(rsync_count)" == 0 ]] || fail 'A forbidden managed layout still transferred.'
[[ -L "$managed" ]] || fail 'The managed symlink was replaced.'
end_scenario

# --- a corrupted digest record converges rather than being trusted ----------
# Documents the behaviour; it is not a mutation test of the format check,
# since a malformed value can never equal a real digest either way.
reset_server; make_app; make_shared
mkdir -p "$managed"; ln -s "$managed" "$live"
OHIF_BUNDLE_DIR="$test_root/v1" "$converge" >/dev/null
printf 'not-a-digest\n' >"$managed/.ohif-digest"
before=$(rsync_count)
scenario 'a corrupted digest record is not trusted'
OHIF_BUNDLE_DIR="$test_root/v1" "$converge" >/dev/null
[[ "$(rsync_count)" == $((before + 1)) ]] || fail 'A corrupted digest record was trusted.'
end_scenario

# --- a half-finished transfer must leave no claim about what is live --------
#
# This is the case that decides where the digest record is written. A transfer
# that dies partway has already deleted and rewritten files, so any record left
# behind would describe a tree that no longer exists. The record must therefore
# be gone, not merely unchanged.
make_bundle "$test_root/v4" v3.15.0
scenario 'an interrupted transfer leaves no digest record'
if PHR_TEST_RSYNC_FAILS=true OHIF_BUNDLE_DIR="$test_root/v4" "$converge" >/dev/null 2>&1; then
    fail 'A failed transfer reported success.'
fi
[[ ! -f "$managed/.ohif-digest" ]] \
    || fail 'A half-finished transfer left a digest record claiming a live bundle.'
[[ -L "$live" ]] || fail 'A failed transfer disturbed the live symlink.'
end_scenario

scenario 'the deploy after an interrupted transfer converges'
OHIF_BUNDLE_DIR="$test_root/v4" "$converge" >/dev/null
[[ "$(tag_at "$managed")" == v3.15.0 ]] || fail 'A retry after a failed transfer did not converge.'
[[ "$(cat "$managed/.ohif-digest")" =~ ^[0-9a-f]{64}$ ]] \
    || fail 'A successful retry did not restore the digest record.'
end_scenario

# --- a record that does not persist must not be reported as converged -------
make_bundle "$test_root/v5" v3.16.0
scenario 'a digest that does not persist fails the read-back'
if PHR_TEST_DIGEST_WRITE_FAILS=true OHIF_BUNDLE_DIR="$test_root/v5" "$converge" >/dev/null 2>&1; then
    fail 'A digest record that never persisted was reported as converged.'
fi
end_scenario

scenario 'the deploy after a failed marker write repairs the record'
OHIF_BUNDLE_DIR="$test_root/v5" "$converge" >/dev/null
[[ "$(cat "$managed/.ohif-digest")" =~ ^[0-9a-f]{64}$ ]] \
    || fail 'A retry did not restore the digest record.'
end_scenario

# --- the digest record is never part of the bundle or the transfer ----------
scenario 'the digest record is excluded from the digest and the transfer'
[[ ! -e "$test_root/v5/.ohif-digest" ]] || fail 'fixture: the source bundle has a digest record.'
before=$(rsync_count)
OHIF_BUNDLE_DIR="$test_root/v5" "$converge" >/dev/null
[[ "$(rsync_count)" == "$before" ]] || fail 'The recorded digest did not make the next deploy a no-op.'
end_scenario

# --- bundles that do not look like an OHIF build are refused ----------------
rm -rf "$test_root/bad"; mkdir -p "$test_root/bad"
if OHIF_BUNDLE_DIR="$test_root/bad" "$converge" >/dev/null 2>&1; then
    fail 'A bundle with no index.html was accepted.'
fi
printf '<html><head><title>Something else</title></head></html>\n' >"$test_root/bad/index.html"
if OHIF_BUNDLE_DIR="$test_root/bad" "$converge" >/dev/null 2>&1; then
    fail 'A bundle that is not an OHIF build was accepted.'
fi
rm -rf "$test_root/linked"; mkdir -p "$test_root/linked"
ln -s "$test_root/v5/index.html" "$test_root/linked/index.html"
if OHIF_BUNDLE_DIR="$test_root/linked" "$converge" >/dev/null 2>&1; then
    fail 'A bundle whose entrypoint is a symlink was accepted.'
fi

# --- unsafe inputs are refused before anything is contacted -----------------
if OHIF_SSH_TARGET=-V OHIF_BUNDLE_DIR="$test_root/v4" "$converge" >/dev/null 2>&1; then
    fail 'An option-like SSH target was accepted.'
fi
for unsafe in '../escape' '..' '.' '.hidden' 'has space'; do
    if OHIF_DEPLOY_DIR="$unsafe" OHIF_BUNDLE_DIR="$test_root/v4" "$converge" >/dev/null 2>&1; then
        fail "An unsafe deploy directory was accepted: $unsafe"
    fi
done
if OHIF_BUNDLE_DIR="$test_root/does-not-exist" "$converge" >/dev/null 2>&1; then
    fail 'A missing bundle directory was accepted.'
fi

# --- issue #126: several writers arriving while a deployment is active ------
#
# GitHub keeps one pending job per concurrency group, so of the writers that
# queue behind an active deployment only the last survives. This models that
# directly: three writers arrive, the middle one is evicted, and the surviving
# writer is an *application* deploy that requested no OHIF change at all.
#
# Before this change that sequence lost writer B permanently: B's bundle was
# the only thing that would ever have published it, and the application deploy
# excludes `ohif`. The assertion below is that it no longer can, because every
# deploy converges OHIF to the current desired bundle rather than to its own.
reset_server; make_app; make_shared
mkdir -p "$managed"; ln -s "$managed" "$live"
make_bundle "$test_root/writer-a" v4.0.0
make_bundle "$test_root/writer-b" v4.1.0

scenario 'an evicted OHIF writer is subsumed by the surviving deploy'
# Writer A is the active deployment: it holds the group and completes.
OHIF_BUNDLE_DIR="$test_root/writer-a" "$converge" >/dev/null
[[ "$(tag_at "$managed")" == v4.0.0 ]] || fail 'The active deployment did not publish its bundle.'

# Writer B queues behind it and becomes the desired OHIF bundle...
desired="$test_root/writer-b"
# ...and writer C, an application deploy, arrives and evicts B from the pending
# slot. B never runs. C converges against the desired bundle, which is B's.
before=$(rsync_count)
OHIF_BUNDLE_DIR="$desired" "$converge" >/dev/null
[[ "$(tag_at "$managed")" == v4.1.0 ]] || fail 'An evicted OHIF writer was lost by the surviving deploy.'
[[ "$(rsync_count)" == $((before + 1)) ]] || fail 'The surviving deploy did not transfer the desired bundle.'

# A fourth writer arriving with nothing new must be a no-op, so convergence on
# every deploy does not turn into a redeploy on every deploy.
before=$(rsync_count)
OHIF_BUNDLE_DIR="$desired" "$converge" >/dev/null
[[ "$(rsync_count)" == "$before" ]] || fail 'A deploy with nothing to converge still transferred.'
end_scenario

echo 'converge OHIF dist tests passed (stubbed transport; run the integration suite for the rest)'
