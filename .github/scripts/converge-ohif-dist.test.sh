#!/usr/bin/env bash
# Harness for the OHIF convergence step, including the multi-writer scenario
# issue #126 asks for. `ssh` and `rsync` are stubbed against a local directory
# standing in for the server, so the whole thing runs offline.

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly script_dir
readonly converge="$script_dir/converge-ohif-dist.sh"
test_root="$(mktemp -d)"
readonly test_root
trap 'rm -rf "$test_root"' EXIT

readonly remote_home="$test_root/remote-home"
readonly rsync_log="$test_root/rsync.log"
mkdir -p "$remote_home"

# Stands in for ssh: runs the command locally with $HOME pointed at the fake
# server tree, so the script's own `$HOME/...` quoting is exercised for real
# rather than being assumed correct.
cat >"$test_root/ssh" <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
shift # the ssh target
# Stands in for a server that accepts the write and does not persist it -- a
# full or read-only filesystem -- so the read-back has something to catch.
if [[ "${PHR_TEST_DIGEST_WRITE_FAILS:-false}" == true && "$*" == *'cat > '*.ohif-digest* ]]; then
    cat >/dev/null
    exit 0
fi
HOME="$PHR_TEST_REMOTE_HOME" exec bash -c "$*"
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
        -a|--delete) shift ;;
        *) args+=("$1"); shift ;;
    esac
done
source="${args[0]}"
destination="${args[1]#*:}"
destination="${destination/\$HOME/$PHR_TEST_REMOTE_HOME}"
printf '%s -> %s\n' "$source" "$destination" >>"$PHR_TEST_RSYNC_LOG"
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
export OHIF_SSH_TARGET=cpanel-deploy@host.example.test
export OHIF_DEPLOY_DIR=phr-laravel

readonly live_root="$remote_home/phr-laravel/public/ohif"

# Build a bundle whose contents are distinguishable per tag.
make_bundle() {
    local dir="$1" tag="$2"
    rm -rf "$dir"
    mkdir -p "$dir/app"
    printf '<html><head><title>OHIF Viewer %s</title></head><body></body></html>\n' "$tag" >"$dir/index.html"
    printf 'console.log("%s");\n' "$tag" >"$dir/app/main.js"
}

live_tag() {
    sed -n 's/.*<title>OHIF Viewer \(.*\)<\/title>.*/\1/p' "$live_root/index.html" 2>/dev/null || true
}

rsync_count() {
    [[ -f "$rsync_log" ]] && wc -l <"$rsync_log" | tr -d ' ' || echo 0
}

fail() { echo "$1" >&2; exit 1; }

# --- a first deployment onto a server with no recorded bundle ---------------
make_bundle "$test_root/v1" v3.12.0
OHIF_BUNDLE_DIR="$test_root/v1" "$converge" >/dev/null
[[ "$(live_tag)" == v3.12.0 ]] || fail 'The first convergence did not publish the bundle.'
[[ -f "$live_root/.ohif-digest" ]] || fail 'The first convergence recorded no digest.'
[[ "$(rsync_count)" == 1 ]] || fail 'The first convergence did not transfer exactly once.'

# --- an unchanged bundle must not touch the server --------------------------
OHIF_BUNDLE_DIR="$test_root/v1" "$converge" >/dev/null
[[ "$(rsync_count)" == 1 ]] || fail 'A converged bundle was redeployed.'

# --- a changed bundle must converge -----------------------------------------
make_bundle "$test_root/v2" v3.13.0
OHIF_BUNDLE_DIR="$test_root/v2" "$converge" >/dev/null
[[ "$(live_tag)" == v3.13.0 ]] || fail 'A changed bundle was not converged.'
[[ "$(rsync_count)" == 2 ]] || fail 'A changed bundle did not transfer.'

# --- stale files inside the tree are deleted, the digest record survives -----
printf 'stale\n' >"$live_root/app/old-hash.js"
make_bundle "$test_root/v3" v3.14.0
OHIF_BUNDLE_DIR="$test_root/v3" "$converge" >/dev/null
[[ ! -e "$live_root/app/old-hash.js" ]] || fail 'A stale asset survived the convergence.'
[[ -f "$live_root/.ohif-digest" ]] || fail 'A successful convergence recorded no digest.'

# --- a corrupted digest record converges rather than being trusted ----------
# Documents the behaviour; it is not a mutation test of the format check,
# since a malformed value can never equal a real digest either way.
printf 'not-a-digest\n' >"$live_root/.ohif-digest"
before=$(rsync_count)
OHIF_BUNDLE_DIR="$test_root/v3" "$converge" >/dev/null
[[ "$(rsync_count)" == $((before + 1)) ]] || fail 'A corrupted digest record was trusted.'

# --- a half-finished transfer must leave no claim about what is live --------
#
# This is the case that decides where the digest record is written. A transfer
# that dies partway has already deleted and rewritten files, so any record left
# behind would describe a tree that no longer exists. The record must therefore
# be gone, not merely unchanged.
make_bundle "$test_root/v4" v3.15.0
if PHR_TEST_RSYNC_FAILS=true OHIF_BUNDLE_DIR="$test_root/v4" "$converge" >/dev/null 2>&1; then
    fail 'A failed transfer reported success.'
fi
[[ ! -f "$live_root/.ohif-digest" ]] \
    || fail 'A half-finished transfer left a digest record claiming a live bundle.'
# The next deploy still sees work to do, so the failure is not sticky.
OHIF_BUNDLE_DIR="$test_root/v4" "$converge" >/dev/null
[[ "$(live_tag)" == v3.15.0 ]] || fail 'A retry after a failed transfer did not converge.'
[[ "$(cat "$live_root/.ohif-digest")" =~ ^[0-9a-f]{64}$ ]] \
    || fail 'A successful retry did not restore the digest record.'

# --- a record that does not persist must not be reported as converged -------
make_bundle "$test_root/v5" v3.16.0
if PHR_TEST_DIGEST_WRITE_FAILS=true OHIF_BUNDLE_DIR="$test_root/v5" "$converge" >/dev/null 2>&1; then
    fail 'A digest record that never persisted was reported as converged.'
fi
# And the next deploy repairs it rather than believing the tree is unrecorded.
OHIF_BUNDLE_DIR="$test_root/v5" "$converge" >/dev/null
[[ "$(cat "$live_root/.ohif-digest")" =~ ^[0-9a-f]{64}$ ]] \
    || fail 'A retry did not restore the digest record.'

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
if OHIF_DEPLOY_DIR='../escape' OHIF_BUNDLE_DIR="$test_root/v4" "$converge" >/dev/null 2>&1; then
    fail 'A path-escaping deploy directory was accepted.'
fi
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
make_bundle "$test_root/writer-a" v4.0.0
make_bundle "$test_root/writer-b" v4.1.0

# Writer A is the active deployment: it holds the group and completes.
OHIF_BUNDLE_DIR="$test_root/writer-a" "$converge" >/dev/null
[[ "$(live_tag)" == v4.0.0 ]] || fail 'The active deployment did not publish its bundle.'

# Writer B queues behind it and becomes the desired OHIF bundle...
desired="$test_root/writer-b"
# ...and writer C, an application deploy, arrives and evicts B from the pending
# slot. B never runs. C converges against the desired bundle, which is B's.
before=$(rsync_count)
OHIF_BUNDLE_DIR="$desired" "$converge" >/dev/null
[[ "$(live_tag)" == v4.1.0 ]] || fail 'An evicted OHIF writer was lost by the surviving deploy.'
[[ "$(rsync_count)" == $((before + 1)) ]] || fail 'The surviving deploy did not transfer the desired bundle.'

# A fourth writer arriving with nothing new must be a no-op, so convergence on
# every deploy does not turn into a redeploy on every deploy.
before=$(rsync_count)
OHIF_BUNDLE_DIR="$desired" "$converge" >/dev/null
[[ "$(rsync_count)" == "$before" ]] || fail 'A deploy with nothing to converge still transferred.'

echo 'converge OHIF dist tests passed'
