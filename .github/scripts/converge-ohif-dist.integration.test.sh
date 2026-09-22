#!/usr/bin/env bash
# Real-transport harness for the OHIF convergence step.
#
# Nothing here is stubbed: a real `sshd` listening on loopback, a real `ssh`
# client, and real sender/receiver `rsync`. The only synthetic parts are the
# data (a handful of files that spell "OHIF Viewer <tag>") and the account (a
# throwaway system user with a throwaway HOME, created and removed by this
# script). No production credentials, no production host, no patient data.
#
# Why this exists: the sibling stub suite can only ever prove the assumptions
# of whoever wrote the stubs. An rsync stub that "helpfully" expanded `$HOME`
# in the destination is exactly what let a destination ship that no real
# receiver would ever have expanded. So the transport gets exercised for real,
# and so -- where the pinned action is available -- does the deployment
# action's own `begin`/`preflight`, rather than a re-implementation of what we
# believe it checks.
#
# Requirements: root (to create the throwaway account and run sshd), openssh
# server and client, rsync, su, git. PHR_SHARED_ACTION_DIR names a checkout of
# bherila/shared-cpanel-deployment and PHR_SHARED_ACTION_SHA the 40-hex SHA the
# workflows pin; the checkout's HEAD must equal it.
#
# Set PHR_OHIF_INTEGRATION_REQUIRED=true to turn "cannot run here" into a
# failure instead of a skip: every prerequisite, including the action checkout
# at the pinned SHA, is then mandatory, and a skipped scenario fails the run.
# CI sets it, so a runner that silently loses sshd or the action checkout does
# not silently lose this suite. Without it, missing prerequisites skip loudly.
#
# PHR_OHIF_CONVERGE_SCRIPT overrides the script under test (default: the
# sibling converge-ohif-dist.sh), so the suite can be pointed at a deliberately
# broken copy. Stale-content failures carry the stable marker `STALE-CONTENT:`
# so such a run can tell the intended failure apart from any other.
#
# Account safety: the throwaway account name is generated per invocation, the
# run refuses to start if that name (or a group of that name) already exists,
# and cleanup deletes only an account this invocation created.
# PHR_OHIF_TEST_ACCOUNT forces the name; it exists only so that refusal can be
# demonstrated, and is subject to the same refusal.

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly script_dir
readonly converge="${PHR_OHIF_CONVERGE_SCRIPT:-$script_dir/converge-ohif-dist.sh}"
readonly required="${PHR_OHIF_INTEGRATION_REQUIRED:-false}"
readonly action_dir="${PHR_SHARED_ACTION_DIR:-}"
readonly action_sha="${PHR_SHARED_ACTION_SHA:-}"

fail() { echo "FAIL: $1" >&2; exit 1; }

unavailable() {
    if [[ "$required" == true ]]; then
        echo "FAIL: the real-transport OHIF suite cannot run here: $1" >&2
        exit 1
    fi
    echo "SKIPPED: the real-transport OHIF suite did not run: $1" >&2
    echo 'NOT EXERCISED: real ssh transport, real rsync sender/receiver behaviour,' >&2
    echo 'the remote receiver, and the deployment action preflight. The stub suite' >&2
    echo 'covers the decision table only.' >&2
    exit 0
}

# --- prerequisites: all of them, before any account or daemon exists --------
[[ -f "$converge" && -r "$converge" ]] || fail "the script under test is not a readable file: $converge"
[[ "$(id -u)" == 0 ]] || unavailable 'it needs root to create a throwaway account and run sshd'
for tool in /usr/sbin/sshd ssh ssh-keygen rsync useradd usermod userdel getent su git sha256sum od; do
    command -v "$tool" >/dev/null 2>&1 || [[ -x "$tool" ]] \
        || unavailable "'$tool' is not installed"
done

# The pinned action. Required mode demands the checkout, the expected SHA, and
# that they agree: a suite that validates against some other revision of the
# action proves nothing about the one production runs. A mismatch is fatal in
# either mode, since it is a misconfiguration rather than a missing capability.
action_revision=''
if [[ -n "$action_dir" && -f "$action_dir/scripts/atomic-release.sh" ]]; then
    # The checkout is typically owned by the CI user while this runs as root;
    # without `safe.directory` git refuses to read it at all.
    action_revision="$(git -c safe.directory="$action_dir" -C "$action_dir" rev-parse HEAD 2>/dev/null || true)"
    if [[ -n "$action_sha" ]]; then
        [[ "$action_sha" =~ ^[0-9a-f]{40}$ ]] \
            || fail "PHR_SHARED_ACTION_SHA is not a 40-hex commit SHA: '$action_sha'"
        [[ "$action_revision" == "$action_sha" ]] \
            || fail "the action checkout at $action_dir is at '${action_revision:-unknown}', not the pinned $action_sha"
    elif [[ "$required" == true ]]; then
        fail 'PHR_SHARED_ACTION_SHA must name the pinned action SHA in required mode'
    else
        echo "NOTE: PHR_SHARED_ACTION_SHA is unset; the action checkout's revision (${action_revision:-unknown}) is not verified against a pin." >&2
    fi
elif [[ "$required" == true ]]; then
    fail "PHR_SHARED_ACTION_DIR ('$action_dir') is not a checkout of bherila/shared-cpanel-deployment with scripts/atomic-release.sh; required mode cannot skip the action scenarios"
else
    echo 'NOTE: PHR_SHARED_ACTION_DIR is not set to a checkout of the pinned' >&2
    echo 'bherila/shared-cpanel-deployment action, so the scenarios that run its' >&2
    echo 'real begin/preflight are skipped (and counted). Everything else runs.' >&2
    echo >&2
fi

# The throwaway account's name: generated, never adopted.
if [[ -n "${PHR_OHIF_TEST_ACCOUNT:-}" ]]; then
    server_user="$PHR_OHIF_TEST_ACCOUNT"
else
    server_user="phrohif$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')"
fi
readonly server_user
[[ "$server_user" =~ ^[a-z_][a-z0-9_-]{0,30}$ ]] || fail "unusable throwaway account name: '$server_user'"
if getent passwd "$server_user" >/dev/null || getent group "$server_user" >/dev/null; then
    fail "an account or group named '$server_user' already exists; refusing to adopt, reconfigure, or delete it"
fi

port=0
for candidate_port in $(seq 2222 2299); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$candidate_port") 2>/dev/null; then
        port=$candidate_port
        break
    fi
done
[[ "$port" != 0 ]] || unavailable 'no free loopback port in 2222-2299'

# --- setup: from here on, cleanup undoes exactly what this run created -------
account_created=false
run_sshd_created=false
root_dir="$(mktemp -d)"
readonly root_dir

cleanup() {
    local pid
    if [[ -f "$root_dir/sshd.pid" ]]; then
        pid="$(cat "$root_dir/sshd.pid")"
        kill "$pid" 2>/dev/null || true
        # userdel refuses an account that still has processes.
        for _ in $(seq 1 50); do kill -0 "$pid" 2>/dev/null || break; sleep 0.1; done
    fi
    # A fixture may leave a deliberately unreadable directory behind.
    chmod -R u+rwX "$root_dir" 2>/dev/null || true
    if [[ "$account_created" == true ]]; then
        userdel "$server_user" 2>/dev/null \
            || echo "WARNING: could not delete the throwaway account $server_user this run created." >&2
    fi
    if [[ "$run_sshd_created" == true ]]; then
        rmdir /run/sshd 2>/dev/null || true
    fi
    rm -rf "$root_dir"
    return 0
}
trap cleanup EXIT

# sshd resolves the account's home through this path as an unprivileged user.
chmod 755 "$root_dir"

readonly server_home="$root_dir/serverhome"
readonly client_home="$root_dir/clienthome"
readonly ssh_config="$client_home/.ssh/config"
readonly app_root="$server_home/phr-laravel"
readonly live="$app_root/public/ohif"
readonly control="$server_home/.deployments/phr-laravel"
readonly shared="$control/shared"
readonly managed="$shared/public/ohif"
readonly unrelated="$server_home/unrelated-tree"

# --- the server -------------------------------------------------------------
mkdir -p "$server_home/.ssh" "$client_home/.ssh"
if [[ ! -d /run/sshd ]]; then
    mkdir -p /run/sshd
    run_sshd_created=true
fi
useradd -M -d "$server_home" -s /bin/bash "$server_user"
account_created=true
# `useradd` leaves the account password-locked, which sshd refuses before it
# ever looks at a key. This is the account this run just created.
usermod -p '*' "$server_user"

ssh-keygen -q -t ed25519 -N '' -f "$root_dir/hostkey"
ssh-keygen -q -t ed25519 -N '' -f "$client_home/.ssh/id_ed25519"
cp "$client_home/.ssh/id_ed25519.pub" "$server_home/.ssh/authorized_keys"

cat >"$root_dir/sshd_config" <<EOF
Port $port
ListenAddress 127.0.0.1
HostKey $root_dir/hostkey
PidFile $root_dir/sshd.pid
AuthorizedKeysFile %h/.ssh/authorized_keys
AllowUsers $server_user
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
UsePAM no
StrictModes no
Subsystem sftp /usr/lib/openssh/sftp-server
EOF
# Loopback only, as sshd itself will apply it -- not as this file claims.
listen="$(/usr/sbin/sshd -T -f "$root_dir/sshd_config" 2>/dev/null | awk '$1 == "listenaddress" { print $2 }')"
[[ "$listen" == "127.0.0.1:$port" ]] \
    || fail "the throwaway sshd would listen on '${listen//$'\n'/, }', not only 127.0.0.1:$port"

cat >"$ssh_config" <<EOF
Host ohif-integration
  HostName 127.0.0.1
  Port $port
  User $server_user
  IdentityFile $client_home/.ssh/id_ed25519
  IdentitiesOnly yes
  UserKnownHostsFile $client_home/.ssh/known_hosts
  StrictHostKeyChecking accept-new
  BatchMode yes
EOF
chmod 600 "$ssh_config"

ssh_binary="$(command -v ssh)"
readonly ssh_binary
rsync_binary="$(command -v rsync)"
readonly rsync_binary

# The convergence script calls `ssh` with production's argument list. `ssh` is
# the real client binary either way; this wrapper only supplies the connection
# options that production supplies through the deploy key and known_hosts that
# `ci.yml` installs. `rsync` is invoked with no wrapper by default -- RSYNC_RSH
# is rsync's own documented hook for the same options, and it does not change
# how rsync builds or escapes the remote argument under test.
cat >"$root_dir/ssh-client" <<EOF
#!/usr/bin/env bash
exec $ssh_binary -F "$ssh_config" "\$@"
EOF
# Scenarios that must prove rsync was *not* run swap in this instead: it
# records the invocation and then execs the real rsync with the argv untouched.
cat >"$root_dir/rsync-recorder" <<EOF
#!/usr/bin/env bash
printf '%s\n' "\$*" >>"$root_dir/rsync-calls.log"
exec $rsync_binary "\$@"
EOF
chmod +x "$root_dir/ssh-client" "$root_dir/rsync-recorder"

/usr/sbin/sshd -f "$root_dir/sshd_config" -E "$root_dir/sshd.log"
for _ in $(seq 1 50); do
    grep -q 'Server listening' "$root_dir/sshd.log" && break
    sleep 0.1
done
grep -q 'Server listening' "$root_dir/sshd.log" || unavailable "sshd did not start: $(tail -3 "$root_dir/sshd.log")"
grep -q 'Server listening on 127.0.0.1 port' "$root_dir/sshd.log" \
    || fail "sshd is listening somewhere other than loopback: $(grep 'Server listening' "$root_dir/sshd.log")"

export PHR_OHIF_SSH_BIN="$root_dir/ssh-client"
export RSYNC_RSH="$ssh_binary -F $ssh_config"
export OHIF_SSH_TARGET=ohif-integration
export OHIF_DEPLOY_DIR=phr-laravel

"$root_dir/ssh-client" ohif-integration 'true' \
    || unavailable 'the throwaway sshd rejected the throwaway key'

# --- versions, for the record ----------------------------------------------
versions() {
    echo "bash:   $BASH_VERSION"
    echo "ssh:    $(ssh -V 2>&1)"
    echo "sshd:   $(/usr/sbin/sshd -V 2>&1 | head -1 || true)"
    echo "rsync:  $(rsync --version | head -1)"
    echo "shared action: ${action_revision:-not available}${action_sha:+ (pinned $action_sha)}"
    echo "script under test: $converge"
}
echo '=== tool versions ==='
versions
rsync --version | sed -n '/Capabilities/,/^$/p' | sed 's/^/        /'
# The one rsync capability this script's destination spelling depends on. It is
# reported as *optional*, which is why the destination must use `~` and not
# `$HOME`: without it the remote path goes through the remote shell, escaped.
echo "secluded-args: $(rsync --version | tr ',' '\n' | grep -i 'secluded' | tr -d ' ' || echo 'not reported')"
echo "throwaway account: $server_user (created by this run); sshd on 127.0.0.1:$port"
echo

# --- fixtures ---------------------------------------------------------------
reset_server() {
    chmod -R u+rwX "$server_home" 2>/dev/null || true
    find "$server_home" -mindepth 1 -maxdepth 1 ! -name .ssh -exec rm -rf {} +
    rm -f "$root_dir/rsync-calls.log"
    printf 'home\n' >"$server_home/SENTINEL"
    own_server
}
own_server() { chown -R "$server_user:$server_user" "$server_home"; }

make_app() {
    mkdir -p "$app_root/public" "$app_root/storage"
    : >"$app_root/artisan"
    printf 'release=r1\ncommit=abcdef1234567\n' >"$app_root/.deploy-release"
    printf 'app\n' >"$app_root/SENTINEL"
    printf 'public\n' >"$app_root/public/SENTINEL"
}
make_shared() {
    mkdir -p "$shared/public" "$shared/storage"
    printf 'shared\n' >"$shared/SENTINEL"
    printf 'shared-storage\n' >"$shared/storage/SENTINEL"
}
make_tree() {
    local dir="$1" tag="$2"
    mkdir -p "$dir/app"
    printf '<html><head><title>OHIF Viewer %s</title></head><body></body></html>\n' "$tag" >"$dir/index.html"
    printf 'console.log("%s");\n' "$tag" >"$dir/app/main.js"
}
tag_at() { sed -n 's/.*<title>OHIF Viewer \(.*\)<\/title>.*/\1/p' "$1/index.html" 2>/dev/null || true; }
# The digest the script computes for a bundle, for planting records by hand.
digest_of() {
    (cd "$1" && LC_ALL=C find . -type f ! -name .ohif-digest -print0 | LC_ALL=C sort -z \
        | xargs -0 -r sha256sum | sha256sum | cut -d' ' -f1)
}
rsync_calls() { [[ -f "$root_dir/rsync-calls.log" ]] && wc -l <"$root_dir/rsync-calls.log" | tr -d ' ' || echo 0; }
run_converge() { bash "$converge"; }

sentinels() {
    (cd "$server_home" && LC_ALL=C find . -name SENTINEL -print0 \
        | LC_ALL=C sort -z | xargs -0 -r sha256sum)
}
executed=0
skipped=0
scenario() { scenario_name="$1"; scenario_sentinels="$(sentinels)"; }
end_scenario() {
    [[ "$(sentinels)" == "$scenario_sentinels" ]] \
        || fail "$scenario_name: files outside the intended destination changed."
    executed=$((executed + 1))
    echo "  ok: $scenario_name"
}
skip_scenario() {
    skipped=$((skipped + 1))
    echo "  SKIPPED: $1 ($2)"
}

make_tree "$root_dir/v1" v3.12.0
make_tree "$root_dir/v2" v3.13.0
# Deliberately adversarial: the two bundles' entrypoints differ in content but
# share a byte count and a modification time, which is exactly the case rsync's
# default quick check skips. Real rsync silently leaves the old file in place,
# and the script would then publish a digest claiming the new bundle is live --
# and, because the record would match from then on, never repair it. This is
# what the transfer's --checksum buys, and it cannot be observed against a stub
# that simply copies. Assertions that catch it are marked STALE-CONTENT:.
touch -d '2026-01-01T00:00:00Z' "$root_dir/v1/index.html" "$root_dir/v2/index.html"
[[ "$(stat -c %s "$root_dir/v1/index.html")" == "$(stat -c %s "$root_dir/v2/index.html")" ]] \
    || fail 'fixture: the two entrypoints must share a byte count to exercise the quick check.'
! cmp -s "$root_dir/v1/index.html" "$root_dir/v2/index.html" \
    || fail 'fixture: the two entrypoints must differ in content.'
v1_digest="$(digest_of "$root_dir/v1")"
v2_digest="$(digest_of "$root_dir/v2")"
readonly v1_digest v2_digest

# --- the pinned deployment action -------------------------------------------
action=''
if [[ -n "$action_dir" && -f "$action_dir/scripts/atomic-release.sh" ]]; then
    action="$root_dir/atomic-release.sh"
    cp "$action_dir/scripts/atomic-release.sh" "$action"
    chmod 755 "$action"
fi

# Runs the *actual pinned* action as the deploy account: `begin`, an empty but
# structurally valid candidate, then `preflight`. Echoes the action's own
# output so a refusal is quoted rather than paraphrased. Candidates are named
# `cand-*` so clear_transaction can remove exactly them and nothing else under
# `releases/`.
run_real_preflight() {
    local release="cand-$1" initial_commit="${2-abcdef1234567}" out status=0
    [[ -n "$action" ]] || return 99
    out=$(su -s /bin/bash "$server_user" -c \
        "HOME='$server_home' bash '$action' begin phr-laravel '$release' abcdef1234567 300 2 maintenance '$initial_commit' stable-directory public/ohif" 2>&1) || status=$?
    if [[ "$status" -ne 0 ]]; then
        printf '%s\n' "$out"
        return "$status"
    fi
    : >"$control/releases/$release/artisan"
    chown "$server_user:$server_user" "$control/releases/$release/artisan"
    status=0
    out=$(su -s /bin/bash "$server_user" -c \
        "HOME='$server_home' bash '$action' preflight phr-laravel '$release' ''" 2>&1) || status=$?
    printf '%s\n' "$out"
    return "$status"
}
clear_transaction() {
    rm -rf "$control/deploy.lock" "$control/state" "$control/recovery" "$control/releases"/cand-*
    rmdir "$control/releases" 2>/dev/null || true
}
# Runs an action scenario, or counts it as skipped when the action is absent.
# (Required mode never reaches the skip: the prerequisites above fail first,
# and the summary below fails the run if anything was skipped anyway.)
action_scenario() {
    if [[ -n "$action" ]]; then return 0; fi
    skip_scenario "$1" 'no pinned action checkout'
    return 1
}

echo '=== real transport ==='

# --- the destination spelling, against a real receiver ----------------------
#
# This is the assertion the stub cannot make. `$HOME` is not expanded by
# anything on the way: rsync backslash-escapes the remote argument to protect
# it from the remote shell, so the receiver is asked for a directory whose name
# is the five characters `$HOME`. A leading `~` is left unescaped and the
# remote shell expands it.
reset_server
# Every `$HOME` in this block is meant to stay the five literal characters.
# shellcheck disable=SC2016
probe_dollar() {
    scenario 'a real receiver does not expand $HOME in an rsync destination'
    if rsync -a "$root_dir/v1/" 'ohif-integration:$HOME/probe-dollar/' >/dev/null 2>&1; then
        fail 'rsync accepted a $HOME destination against a real receiver.'
    fi
    [[ ! -d "$server_home/probe-dollar" ]] || fail 'A $HOME destination unexpectedly worked.'
}
probe_dollar
rsync -a "$root_dir/v1/" 'ohif-integration:~/probe-tilde/' >/dev/null
[[ -d "$server_home/probe-tilde" ]] || fail 'A ~-relative destination did not reach the account home.'
[[ "$(tag_at "$server_home/probe-tilde")" == v3.12.0 ]] || fail 'The ~-relative transfer did not deliver the bundle.'
rm -rf "$server_home/probe-tilde"
end_scenario

# --- healthy managed symlink ------------------------------------------------
reset_server; make_app; make_shared
mkdir -p "$managed"; ln -s "$managed" "$live"; own_server
scenario 'healthy managed symlink converges over real ssh into the managed directory'
OHIF_BUNDLE_DIR="$root_dir/v1" run_converge >/dev/null
[[ "$(tag_at "$managed")" == v3.12.0 ]] || fail 'The bundle did not reach the managed shared directory.'
[[ -L "$live" && "$(readlink "$live")" == "$managed" ]] || fail 'The live symlink did not survive.'
[[ "$(cat "$managed/.ohif-digest")" == "v2:$v1_digest" ]] || fail 'No v2 digest record was written.'
end_scenario

if action_scenario 'the pinned action preflight accepts the converged healthy layout'; then
    scenario 'the pinned action preflight accepts the converged healthy layout'
    clear_transaction
    preflight_out="$(run_real_preflight healthy)" \
        || fail "The pinned action's preflight rejected the converged healthy layout: $preflight_out"
    grep -q 'Preflight persistent path public/ohif: live=symlink' <<<"$preflight_out" \
        || fail "The action did not see a live symlink: $preflight_out"
    clear_transaction
    end_scenario
fi

# --- repeat convergence -----------------------------------------------------
#
# rsync -a --checksum over identical files changes nothing either, so the tree
# alone cannot tell a no-op from a redundant transfer. The record can: the
# converge path deletes and rewrites it, the no-op path never touches it. The
# recorder shows rsync was not run at all.
scenario 'a second run over real ssh transfers nothing'
before_record="$(stat -c '%i %Y %s' "$managed/.ohif-digest")"
before_entry="$(stat -c '%i %Y' "$managed/index.html")"
sleep 1.1
out="$(OHIF_BUNDLE_DIR="$root_dir/v1" PHR_OHIF_RSYNC_BIN="$root_dir/rsync-recorder" run_converge)"
grep -q 'already converged' <<<"$out" || fail "A converged bundle was not recognised: $out"
[[ "$(rsync_calls)" == 0 ]] || fail 'A no-op run still invoked rsync.'
[[ "$(stat -c '%i %Y %s' "$managed/.ohif-digest")" == "$before_record" ]] || fail 'A no-op run rewrote the digest record.'
[[ "$(stat -c '%i %Y' "$managed/index.html")" == "$before_entry" ]] || fail 'A no-op run rewrote the tree.'
end_scenario

# --- a legacy record that lies about what is live ---------------------------
#
# The regression the versioned record exists for. Before the transfer used
# --checksum, it could skip a same-size, same-mtime entrypoint and still write
# a record naming the new bundle: remote bytes are A, the (bare, legacy) record
# says digest(B). A script that trusts that record short-circuits forever. The
# fixed script must treat it as untrusted, converge A -> B for real, write a
# `v2:` record, and only then become a no-op.
reset_server; make_app; make_shared
mkdir -p "$managed"; cp -a "$root_dir/v1/." "$managed/"
printf '%s\n' "$v2_digest" >"$managed/.ohif-digest"
ln -s "$managed" "$live"; own_server
[[ "$(stat -c '%s %Y' "$managed/index.html")" == "$(stat -c '%s %Y' "$root_dir/v2/index.html")" ]] \
    || fail 'fixture: live and desired entrypoints must share size and mtime.'
[[ "$(tag_at "$managed")" == v3.12.0 ]] || fail 'fixture: the live bundle is not A.'
scenario 'a legacy record naming the desired bundle over stale bytes is repaired'
out="$(OHIF_BUNDLE_DIR="$root_dir/v2" PHR_OHIF_RSYNC_BIN="$root_dir/rsync-recorder" run_converge)" \
    || fail "The legacy-record convergence failed: $out"
[[ "$(tag_at "$managed")" == v3.13.0 ]] \
    || fail "STALE-CONTENT: a legacy record naming the desired bundle left stale bytes live: the live entrypoint is still $(tag_at "$managed"), not v3.13.0. (Trusted the record, or skipped a same-size, same-mtime file.)"
cmp -s "$managed/index.html" "$root_dir/v2/index.html" \
    || fail 'STALE-CONTENT: the live entrypoint does not match the desired bundle byte for byte.'
[[ "$(rsync_calls)" == 1 ]] || fail 'The legacy-record convergence did not run exactly one transfer.'
[[ "$(cat "$managed/.ohif-digest")" == "v2:$v2_digest" ]] || fail 'The legacy record was not replaced with a v2 record.'
end_scenario

scenario 'the run after a legacy-record repair is a genuine no-op'
before_record="$(stat -c '%i %Y %s' "$managed/.ohif-digest")"
sleep 1.1
out="$(OHIF_BUNDLE_DIR="$root_dir/v2" PHR_OHIF_RSYNC_BIN="$root_dir/rsync-recorder" run_converge)"
grep -q 'already converged' <<<"$out" || fail "The repaired bundle was not recognised as converged: $out"
[[ "$(rsync_calls)" == 1 ]] || fail 'The run after the repair invoked rsync again.'
[[ "$(stat -c '%i %Y %s' "$managed/.ohif-digest")" == "$before_record" ]] || fail 'The run after the repair rewrote the record.'
end_scenario

# --- a trusted record over the right bytes ----------------------------------
reset_server; make_app; make_shared
mkdir -p "$managed"; cp -a "$root_dir/v2/." "$managed/"
printf 'v2:%s\n' "$v2_digest" >"$managed/.ohif-digest"
ln -s "$managed" "$live"; own_server
scenario 'a trusted v2 record matching the desired bundle transfers nothing'
before_record="$(stat -c '%i %Y %s' "$managed/.ohif-digest")"
out="$(OHIF_BUNDLE_DIR="$root_dir/v2" PHR_OHIF_RSYNC_BIN="$root_dir/rsync-recorder" run_converge)"
grep -q 'already converged' <<<"$out" || fail "A trusted matching record was not honoured: $out"
[[ "$(rsync_calls)" == 0 ]] || fail 'A trusted matching record still invoked rsync.'
[[ "$(stat -c '%i %Y %s' "$managed/.ohif-digest")" == "$before_record" ]] || fail 'A trusted record was rewritten.'
end_scenario

# --- the confirmed bug: the old mkdir, judged by the real preflight ---------
#
# Same starting state for both halves: the managed tree is the only copy and
# the live symlink is gone. First the behaviour this change removes, then the
# behaviour that replaces it, each judged by the pinned action rather than by
# our belief about it.
if action_scenario 'the pinned action refuses the two-copy layout the old mkdir made'; then
    reset_server; make_app; make_shared; make_tree "$managed" v3.11.0; own_server
    scenario 'the pinned action refuses the two-copy layout the old mkdir made'
    "$root_dir/ssh-client" ohif-integration 'mkdir -p ~/phr-laravel/public/ohif'
    rsync -a --delete "$root_dir/v2/" 'ohif-integration:~/phr-laravel/public/ohif/' >/dev/null
    [[ -d "$live" && ! -L "$live" ]] || fail 'fixture: the old behaviour did not create a second real tree.'
    clear_transaction
    if bug_out="$(run_real_preflight oldway)"; then
        fail "The pinned action accepted two copies, so this fixture no longer models the bug: $bug_out"
    fi
    grep -q 'refusing to choose or delete either copy' <<<"$bug_out" \
        || fail "The action refused for an unexpected reason: $bug_out"
    echo "  confirmed: $(grep -o '::error::.*' <<<"$bug_out" | head -1)"
    clear_transaction
    end_scenario
fi

reset_server; make_app; make_shared; make_tree "$managed" v3.11.0; own_server
scenario 'a missing live link converges the managed tree and restores the link'
OHIF_BUNDLE_DIR="$root_dir/v2" run_converge >/dev/null
[[ -L "$live" ]] || fail 'The live OHIF path is not a symlink.'
[[ "$(readlink "$live")" == "$managed" ]] || fail 'The restored link does not point at the managed directory.'
[[ "$(tag_at "$managed")" == v3.13.0 ]] || fail 'The managed tree was not converged.'
end_scenario

if action_scenario 'the pinned action preflight accepts the recovered layout'; then
    scenario 'the pinned action preflight accepts the recovered layout'
    clear_transaction
    preflight_out="$(run_real_preflight recovered)" \
        || fail "The pinned action's preflight rejected the recovered layout: $preflight_out"
    grep -q 'Preflight persistent path public/ohif: live=symlink' <<<"$preflight_out" \
        || fail "The action did not see a live symlink after recovery: $preflight_out"
    clear_transaction
    end_scenario
fi

# --- conflicting copies -----------------------------------------------------
reset_server; make_app; make_shared
make_tree "$managed" v3.11.0; printf 'managed\n' >"$managed/SENTINEL"
make_tree "$live" v3.10.0; printf 'live\n' >"$live/SENTINEL"; own_server
scenario 'two pre-existing copies are refused over real ssh'
if OHIF_BUNDLE_DIR="$root_dir/v1" run_converge >/dev/null 2>&1; then
    fail 'Simultaneous live and managed copies were accepted.'
fi
[[ "$(tag_at "$managed")" == v3.11.0 ]] || fail 'The managed copy was modified.'
[[ "$(tag_at "$live")" == v3.10.0 ]] || fail 'The live copy was modified.'
end_scenario

# --- legitimate legacy directory --------------------------------------------
reset_server; make_app; rm -f "$app_root/.deploy-release"
make_tree "$live" v3.10.0; own_server
scenario 'a legacy directory converges in place over real ssh'
OHIF_BUNDLE_DIR="$root_dir/v1" run_converge >/dev/null
[[ -d "$live" && ! -L "$live" ]] || fail 'The legacy directory stopped being a real directory.'
[[ "$(tag_at "$live")" == v3.12.0 ]] || fail 'The legacy directory was not converged.'
[[ ! -e "$managed" ]] || fail 'A second copy was created in managed shared storage.'
end_scenario

if action_scenario 'the pinned action preflight still sees a migratable legacy directory'; then
    scenario 'the pinned action preflight still sees a migratable legacy directory'
    clear_transaction
    preflight_out="$(run_real_preflight legacyrun)" \
        || fail "The pinned action's preflight rejected the converged legacy layout: $preflight_out"
    grep -q 'Preflight persistent path public/ohif: live=directory; shared=absent' <<<"$preflight_out" \
        || fail "The action did not see a migratable legacy directory: $preflight_out"
    clear_transaction
    end_scenario
fi

# --- containment: the deploy path or `public` pointing somewhere else --------
#
# Each fixture routes the write path into an unrelated tree that holds a real
# `ohif` directory, a record, and sentinels. The script must refuse before it
# deletes the record or runs rsync, and every sentinel -- including the ones
# inside that `ohif`, which a `--delete` transfer would remove -- must survive
# byte-identical. Assertions are ordered so a script that writes through
# reports CONTAINMENT: rather than merely "accepted".
make_unrelated_ohif() {
    local ohif_dir="$1"
    mkdir -p "$unrelated"
    printf 'unrelated\n' >"$unrelated/SENTINEL"
    make_tree "$ohif_dir" v3.10.0
    printf 'unrelated-ohif\n' >"$ohif_dir/SENTINEL"
    printf 'v2:%s\n' "$v1_digest" >"$ohif_dir/.ohif-digest"
}
expect_contained() {
    local label="$1" ohif_dir="$2" status=0 record
    record="$(cat "$ohif_dir/.ohif-digest")"
    OHIF_BUNDLE_DIR="$root_dir/v1" PHR_OHIF_RSYNC_BIN="$root_dir/rsync-recorder" run_converge \
        >"$root_dir/contain.out" 2>&1 || status=$?
    [[ "$(rsync_calls)" == 0 ]] || fail "CONTAINMENT: $label: rsync ran against the unrelated tree."
    [[ -f "$ohif_dir/.ohif-digest" && "$(cat "$ohif_dir/.ohif-digest")" == "$record" ]] \
        || fail "CONTAINMENT: $label: the unrelated tree's record was deleted or changed."
    [[ "$(tag_at "$ohif_dir")" == v3.10.0 ]] || fail "CONTAINMENT: $label: the unrelated bundle changed."
    [[ "$(sentinels)" == "$scenario_sentinels" ]] || fail "CONTAINMENT: $label: a sentinel changed."
    [[ "$status" -ne 0 ]] || fail "$label: the script reported success: $(cat "$root_dir/contain.out")"
    grep -q 'Nothing was changed on the remote host' "$root_dir/contain.out" \
        || fail "$label: refused late or for an unexpected reason: $(cat "$root_dir/contain.out")"
}

reset_server; make_shared
mkdir -p "$unrelated/public"; : >"$unrelated/artisan"
make_unrelated_ohif "$unrelated/public/ohif"
ln -s unrelated-tree "$app_root"; own_server
scenario 'an app-root symlink to an unrelated Laravel-looking tree is refused over real ssh'
expect_contained 'app-root symlink to an unrelated tree' "$unrelated/public/ohif"
grep -q 'not a managed release directory' "$root_dir/contain.out" \
    || fail "Unexpected refusal for an unmanaged app-root symlink: $(cat "$root_dir/contain.out")"
end_scenario

if action_scenario 'the pinned action refuses the same unmanaged app-root symlink'; then
    scenario 'the pinned action refuses the same unmanaged app-root symlink'
    clear_transaction
    if preflight_out="$(run_real_preflight appsym)"; then
        fail "The pinned action accepted an unmanaged app-root symlink: $preflight_out"
    fi
    grep -q 'outside the managed release tree' <<<"$preflight_out" \
        || fail "The action refused for an unexpected reason: $preflight_out"
    echo "  confirmed: $(grep -o '::error::.*' <<<"$preflight_out" | head -1)"
    clear_transaction
    end_scenario
fi

reset_server; make_app; make_shared
rm -rf "$app_root/public"
make_unrelated_ohif "$unrelated/ohif"
ln -s "$unrelated" "$app_root/public"; own_server
scenario 'a symlinked public under a real Laravel root is refused over real ssh'
expect_contained 'public symlink to an unrelated tree' "$unrelated/ohif"
grep -q 'public is a symlink' "$root_dir/contain.out" \
    || fail "Unexpected refusal for a symlinked public: $(cat "$root_dir/contain.out")"
end_scenario

if action_scenario 'the pinned action refuses the same symlinked public'; then
    scenario 'the pinned action refuses the same symlinked public'
    clear_transaction
    if preflight_out="$(run_real_preflight pubsym)"; then
        fail "The pinned action accepted a symlinked public ancestor: $preflight_out"
    fi
    grep -q 'traverses unsafe ancestor' <<<"$preflight_out" \
        || fail "The action refused for an unexpected reason: $preflight_out"
    echo "  confirmed: $(grep -o '::error::.*' <<<"$preflight_out" | head -1)"
    clear_transaction
    end_scenario
fi

# --- a managed release app-root symlink stays supported ---------------------
make_release_app() {
    mkdir -p "$control/releases/r1/public"
    : >"$control/releases/r1/artisan"
    printf 'release=r1\ncommit=abcdef1234567\n' >"$control/releases/r1/.deploy-release"
    printf 'release\n' >"$control/releases/r1/SENTINEL"
    ln -s .deployments/phr-laravel/releases/r1 "$app_root"
}
reset_server; make_shared; make_release_app; make_tree "$managed" v3.11.0; own_server
scenario 'a missing live link under a managed release symlink is restored over real ssh'
OHIF_BUNDLE_DIR="$root_dir/v2" run_converge >/dev/null
[[ -L "$control/releases/r1/public/ohif" ]] || fail 'The live link was not restored inside the managed release.'
[[ "$(readlink "$live")" == "$managed" ]] || fail 'The restored link does not point at the managed directory.'
[[ "$(tag_at "$managed")" == v3.13.0 ]] || fail 'The managed tree was not converged.'
end_scenario

if action_scenario 'the pinned action preflight accepts the managed release layout'; then
    scenario 'the pinned action preflight accepts the managed release layout'
    clear_transaction
    preflight_out="$(run_real_preflight relsym)" \
        || fail "The pinned action's preflight rejected the managed release layout: $preflight_out"
    grep -q 'Preflight persistent path public/ohif: live=symlink' <<<"$preflight_out" \
        || fail "The action did not see a live symlink in the managed release: $preflight_out"
    clear_transaction
    end_scenario
fi

# --- genuine bootstrap ------------------------------------------------------
reset_server
scenario 'a bootstrap stages into managed storage over real ssh'
OHIF_BUNDLE_DIR="$root_dir/v1" run_converge >/dev/null
[[ ! -e "$app_root" ]] || fail 'A skeletal application root was created.'
[[ -d "$managed" && ! -L "$managed" ]] || fail 'The bundle was not staged into managed shared storage.'
[[ "$(tag_at "$managed")" == v3.12.0 ]] || fail 'The staged bundle is missing.'
end_scenario

if action_scenario 'the pinned action preflight accepts a staged bootstrap'; then
    scenario 'the pinned action preflight accepts a staged bootstrap'
    clear_transaction
    preflight_out="$(run_real_preflight bootstrap '')" \
        || fail "The pinned action's preflight rejected the staged bootstrap: $preflight_out"
    grep -q 'Preflight persistent path public/ohif: live=absent; shared=present' <<<"$preflight_out" \
        || fail "The action did not see staged shared state: $preflight_out"
    clear_transaction
    end_scenario
fi

# --- a genuinely interrupted real transfer ----------------------------------
#
# The receiver is made unable to finish: a subtree inside the destination that
# the deploy account can neither read nor delete. rsync mutates what it can and
# exits non-zero, which is the shape of a transfer killed partway.
reset_server; make_app; make_shared
mkdir -p "$managed"; ln -s "$managed" "$live"; own_server
OHIF_BUNDLE_DIR="$root_dir/v1" run_converge >/dev/null
[[ -f "$managed/.ohif-digest" ]] || fail 'fixture: the first convergence recorded no digest.'
mkdir -p "$managed/unreadable"; printf 'x\n' >"$managed/unreadable/x"
chown -R root:root "$managed/unreadable"; chmod 000 "$managed/unreadable"
scenario 'an interrupted real transfer leaves no digest record'
if OHIF_BUNDLE_DIR="$root_dir/v2" run_converge >/dev/null 2>&1; then
    fail 'A transfer the receiver could not complete reported success.'
fi
[[ ! -f "$managed/.ohif-digest" ]] \
    || fail 'A half-finished transfer left a digest record claiming a live bundle.'
chmod 755 "$managed/unreadable"; rm -rf "$managed/unreadable"
end_scenario

scenario 'the deploy after an interrupted real transfer converges'
OHIF_BUNDLE_DIR="$root_dir/v2" run_converge >/dev/null
[[ "$(tag_at "$managed")" == v3.13.0 ]] \
    || fail "STALE-CONTENT: a retry after a failed transfer did not converge: the live entrypoint is still $(tag_at "$managed"). A same-size, same-mtime file was skipped by rsync's quick check."
[[ "$(cat "$managed/.ohif-digest")" == "v2:$v2_digest" ]] || fail 'A retry did not restore the digest record.'
end_scenario

# --- a marker path of the wrong type ----------------------------------------
#
# The record is invalidated before the tree is touched. If that step cannot
# run, nothing after it may run either.
rm -f "$managed/.ohif-digest"; mkdir -p "$managed/.ohif-digest/occupied"; own_server
scenario 'a marker path of the wrong type aborts before the tree is touched'
if OHIF_BUNDLE_DIR="$root_dir/v1" run_converge >/dev/null 2>&1; then
    fail 'A digest path that could not be invalidated was ignored.'
fi
[[ "$(tag_at "$managed")" == v3.13.0 ]] || fail 'The tree was mutated after the marker step failed.'
[[ -d "$managed/.ohif-digest" ]] || fail 'The marker directory was removed.'
end_scenario

# --- summary ----------------------------------------------------------------
echo
echo '=== summary ==='
echo "scenarios executed: $executed"
echo "scenarios skipped:  $skipped"
versions
if [[ "$skipped" -ne 0 && "$required" == true ]]; then
    fail "$skipped scenario(s) were skipped in required mode."
fi
echo
if [[ "$skipped" -ne 0 ]]; then
    echo "converge OHIF dist integration tests passed with $skipped scenario(s) SKIPPED (real sshd, real ssh, real rsync)"
else
    echo 'converge OHIF dist integration tests passed (real sshd, real ssh, real rsync)'
fi
