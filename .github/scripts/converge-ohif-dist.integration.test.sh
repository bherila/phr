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
# action's own `preflight`, rather than a re-implementation of what we believe
# it checks.
#
# Requirements: root (to create the throwaway account and run sshd), openssh
# server and client, rsync. Optionally PHR_SHARED_ACTION_DIR pointing at a
# checkout of bherila/shared-cpanel-deployment at the SHA `ci.yml` pins; the
# scenarios that run the action's real `begin`/`preflight` are skipped, loudly,
# without it.
#
# Set PHR_OHIF_INTEGRATION_REQUIRED=true to turn "cannot run here" into a
# failure instead of a skip. CI should set it, so a runner that silently loses
# sshd does not silently lose this suite.

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly script_dir
readonly converge="$script_dir/converge-ohif-dist.sh"
readonly required="${PHR_OHIF_INTEGRATION_REQUIRED:-false}"

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

[[ "$(id -u)" == 0 ]] || unavailable 'it needs root to create a throwaway account and run sshd'
for tool in /usr/sbin/sshd ssh ssh-keygen rsync useradd usermod userdel; do
    command -v "$tool" >/dev/null 2>&1 || [[ -x "$tool" ]] \
        || unavailable "'$tool' is not installed"
done

readonly server_user=phrohifsrv
root_dir="$(mktemp -d)"
readonly root_dir
# sshd resolves the account's home through this path as an unprivileged user.
chmod 755 "$root_dir"

cleanup() {
    [[ -f "$root_dir/sshd.pid" ]] && kill "$(cat "$root_dir/sshd.pid")" 2>/dev/null
    # A fixture may leave a deliberately unreadable directory behind.
    chmod -R u+rwX "$root_dir" 2>/dev/null || true
    userdel "$server_user" 2>/dev/null || true
    rm -rf "$root_dir"
    return 0
}
trap cleanup EXIT

readonly server_home="$root_dir/serverhome"
readonly client_home="$root_dir/clienthome"
readonly ssh_config="$client_home/.ssh/config"
readonly app_root="$server_home/phr-laravel"
readonly live="$app_root/public/ohif"
readonly control="$server_home/.deployments/phr-laravel"
readonly shared="$control/shared"
readonly managed="$shared/public/ohif"

fail() { echo "FAIL: $1" >&2; exit 1; }

# --- the server -------------------------------------------------------------
mkdir -p "$server_home/.ssh" "$client_home/.ssh" /run/sshd
if id "$server_user" >/dev/null 2>&1; then
    usermod -d "$server_home" -s /bin/bash "$server_user"
else
    useradd -M -d "$server_home" -s /bin/bash "$server_user"
fi
# `useradd` leaves the account password-locked, which sshd refuses before it
# ever looks at a key.
usermod -p '*' "$server_user"

ssh-keygen -q -t ed25519 -N '' -f "$root_dir/hostkey"
ssh-keygen -q -t ed25519 -N '' -f "$client_home/.ssh/id_ed25519"
cp "$client_home/.ssh/id_ed25519.pub" "$server_home/.ssh/authorized_keys"

port=0
for candidate_port in $(seq 2222 2299); do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$candidate_port") 2>/dev/null; then
        port=$candidate_port
        break
    fi
done
[[ "$port" != 0 ]] || unavailable 'no free loopback port in 2222-2299'

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

# The convergence script calls `ssh` with production's argument list. `ssh` is
# the real client binary either way; this wrapper only supplies the connection
# options that production supplies through the deploy key and known_hosts that
# `ci.yml` installs. `rsync` is invoked with no wrapper at all -- RSYNC_RSH is
# rsync's own documented hook for the same options, and it does not change how
# rsync builds or escapes the remote argument under test.
cat >"$root_dir/ssh-client" <<EOF
#!/usr/bin/env bash
exec $ssh_binary -F "$ssh_config" "\$@"
EOF
chmod +x "$root_dir/ssh-client"

/usr/sbin/sshd -f "$root_dir/sshd_config" -E "$root_dir/sshd.log"
for _ in $(seq 1 50); do
    grep -q 'Server listening' "$root_dir/sshd.log" && break
    sleep 0.1
done
grep -q 'Server listening' "$root_dir/sshd.log" || unavailable "sshd did not start: $(tail -3 "$root_dir/sshd.log")"

export PHR_OHIF_SSH_BIN="$root_dir/ssh-client"
export RSYNC_RSH="$ssh_binary -F $ssh_config"
export OHIF_SSH_TARGET=ohif-integration
export OHIF_DEPLOY_DIR=phr-laravel

"$root_dir/ssh-client" ohif-integration 'true' \
    || unavailable 'the throwaway sshd rejected the throwaway key'

# --- versions, for the record ----------------------------------------------
echo '=== tool versions ==='
echo "bash:   $BASH_VERSION"
echo "ssh:    $(ssh -V 2>&1)"
echo "sshd:   $(/usr/sbin/sshd -V 2>&1 | head -1 || true)"
echo "rsync:  $(rsync --version | head -1)"
rsync --version | sed -n '/Capabilities/,/^$/p' | sed 's/^/        /'
# The one rsync capability this script's destination spelling depends on. It is
# reported as *optional*, which is why the destination must use `~` and not
# `$HOME`: without it the remote path goes through the remote shell, escaped.
echo "secluded-args: $(rsync --version | tr ',' '\n' | grep -i 'secluded' | tr -d ' ' || echo 'not reported')"
if [[ -n "${PHR_SHARED_ACTION_DIR:-}" && -f "${PHR_SHARED_ACTION_DIR}/scripts/atomic-release.sh" ]]; then
    echo "shared action: $(git -C "$PHR_SHARED_ACTION_DIR" rev-parse HEAD 2>/dev/null || echo 'unknown revision')"
fi
echo

# --- fixtures ---------------------------------------------------------------
reset_server() {
    chmod -R u+rwX "$server_home" 2>/dev/null || true
    find "$server_home" -mindepth 1 -maxdepth 1 ! -name .ssh -exec rm -rf {} +
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

sentinels() {
    (cd "$server_home" && LC_ALL=C find . -name SENTINEL -print0 \
        | LC_ALL=C sort -z | xargs -0 -r sha256sum)
}
scenario() { scenario_name="$1"; scenario_sentinels="$(sentinels)"; }
end_scenario() {
    [[ "$(sentinels)" == "$scenario_sentinels" ]] \
        || fail "$scenario_name: files outside the intended destination changed."
    echo "  ok: $scenario_name"
}

make_tree "$root_dir/v1" v3.12.0
make_tree "$root_dir/v2" v3.13.0
# Deliberately adversarial: the two bundles' entrypoints differ in content but
# share a byte count and a modification time, which is exactly the case rsync's
# default quick check skips. Real rsync silently leaves the old file in place,
# and the script would then publish a digest claiming the new bundle is live --
# and, because the record would match from then on, never repair it. This is
# what the transfer's --checksum buys, and it cannot be observed against a stub
# that simply copies.
touch -d '2026-01-01T00:00:00Z' "$root_dir/v1/index.html" "$root_dir/v2/index.html"
[[ "$(stat -c %s "$root_dir/v1/index.html")" == "$(stat -c %s "$root_dir/v2/index.html")" ]] \
    || fail 'fixture: the two entrypoints must share a byte count to exercise the quick check.'

# --- the pinned deployment action -------------------------------------------
action=''
if [[ -n "${PHR_SHARED_ACTION_DIR:-}" && -f "${PHR_SHARED_ACTION_DIR}/scripts/atomic-release.sh" ]]; then
    action="$root_dir/atomic-release.sh"
    cp "${PHR_SHARED_ACTION_DIR}/scripts/atomic-release.sh" "$action"
    chmod 755 "$action"
else
    echo 'NOTE: PHR_SHARED_ACTION_DIR is not set to a checkout of the pinned' >&2
    echo 'bherila/shared-cpanel-deployment action, so the scenarios that run its' >&2
    echo 'real begin/preflight are skipped. Everything else still runs.' >&2
    echo >&2
fi

# Runs the *actual pinned* action as the deploy account: `begin`, an empty but
# structurally valid candidate, then `preflight`. Echoes the action's own
# output so a refusal is quoted rather than paraphrased.
run_real_preflight() {
    local release="$1" initial_commit="${2:-abcdef1234567}" out status=0
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
clear_transaction() { rm -rf "$control/deploy.lock" "$control/state" "$control/releases"; }

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
OHIF_BUNDLE_DIR="$root_dir/v1" "$converge" >/dev/null
[[ "$(tag_at "$managed")" == v3.12.0 ]] || fail 'The bundle did not reach the managed shared directory.'
[[ -L "$live" && "$(readlink "$live")" == "$managed" ]] || fail 'The live symlink did not survive.'
[[ "$(cat "$managed/.ohif-digest")" =~ ^[0-9a-f]{64}$ ]] || fail 'No digest was recorded.'
end_scenario

if [[ -n "$action" ]]; then
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
scenario 'a second run over real ssh transfers nothing'
before_mtime="$(stat -c %Y "$managed/index.html")"
sleep 1
OHIF_BUNDLE_DIR="$root_dir/v1" "$converge" | grep -q 'already converged' \
    || fail 'A converged bundle was not recognised.'
[[ "$(stat -c %Y "$managed/index.html")" == "$before_mtime" ]] || fail 'A no-op run rewrote the tree.'
end_scenario

# --- the confirmed bug: the old mkdir, judged by the real preflight ---------
#
# Same starting state for both halves: the managed tree is the only copy and
# the live symlink is gone. First the behaviour this change removes, then the
# behaviour that replaces it, each judged by the pinned action rather than by
# our belief about it.
if [[ -n "$action" ]]; then
    reset_server; make_app; make_shared; make_tree "$managed" v3.11.0; own_server
    echo '  -- the old behaviour, for contrast --'
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
fi

reset_server; make_app; make_shared; make_tree "$managed" v3.11.0; own_server
scenario 'a missing live link converges the managed tree and restores the link'
OHIF_BUNDLE_DIR="$root_dir/v2" "$converge" >/dev/null
[[ -L "$live" ]] || fail 'The live OHIF path is not a symlink.'
[[ "$(readlink "$live")" == "$managed" ]] || fail 'The restored link does not point at the managed directory.'
[[ "$(tag_at "$managed")" == v3.13.0 ]] || fail 'The managed tree was not converged.'
end_scenario

if [[ -n "$action" ]]; then
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
if OHIF_BUNDLE_DIR="$root_dir/v1" "$converge" >/dev/null 2>&1; then
    fail 'Simultaneous live and managed copies were accepted.'
fi
[[ "$(tag_at "$managed")" == v3.11.0 ]] || fail 'The managed copy was modified.'
[[ "$(tag_at "$live")" == v3.10.0 ]] || fail 'The live copy was modified.'
end_scenario

# --- legitimate legacy directory --------------------------------------------
reset_server; make_app; rm -f "$app_root/.deploy-release"
make_tree "$live" v3.10.0; own_server
scenario 'a legacy directory converges in place over real ssh'
OHIF_BUNDLE_DIR="$root_dir/v1" "$converge" >/dev/null
[[ -d "$live" && ! -L "$live" ]] || fail 'The legacy directory stopped being a real directory.'
[[ "$(tag_at "$live")" == v3.12.0 ]] || fail 'The legacy directory was not converged.'
[[ ! -e "$managed" ]] || fail 'A second copy was created in managed shared storage.'
end_scenario

if [[ -n "$action" ]]; then
    scenario 'the pinned action preflight still sees a migratable legacy directory'
    clear_transaction
    preflight_out="$(run_real_preflight legacyrun)" \
        || fail "The pinned action's preflight rejected the converged legacy layout: $preflight_out"
    grep -q 'Preflight persistent path public/ohif: live=directory; shared=absent' <<<"$preflight_out" \
        || fail "The action did not see a migratable legacy directory: $preflight_out"
    clear_transaction
    end_scenario
fi

# --- genuine bootstrap ------------------------------------------------------
reset_server
scenario 'a bootstrap stages into managed storage over real ssh'
OHIF_BUNDLE_DIR="$root_dir/v1" "$converge" >/dev/null
[[ ! -e "$app_root" ]] || fail 'A skeletal application root was created.'
[[ -d "$managed" && ! -L "$managed" ]] || fail 'The bundle was not staged into managed shared storage.'
[[ "$(tag_at "$managed")" == v3.12.0 ]] || fail 'The staged bundle is missing.'
end_scenario

if [[ -n "$action" ]]; then
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
OHIF_BUNDLE_DIR="$root_dir/v1" "$converge" >/dev/null
[[ -f "$managed/.ohif-digest" ]] || fail 'fixture: the first convergence recorded no digest.'
mkdir -p "$managed/unreadable"; printf 'x\n' >"$managed/unreadable/x"
chown -R root:root "$managed/unreadable"; chmod 000 "$managed/unreadable"
scenario 'an interrupted real transfer leaves no digest record'
if OHIF_BUNDLE_DIR="$root_dir/v2" "$converge" >/dev/null 2>&1; then
    fail 'A transfer the receiver could not complete reported success.'
fi
[[ ! -f "$managed/.ohif-digest" ]] \
    || fail 'A half-finished transfer left a digest record claiming a live bundle.'
chmod 755 "$managed/unreadable"; rm -rf "$managed/unreadable"
end_scenario

scenario 'the deploy after an interrupted real transfer converges'
OHIF_BUNDLE_DIR="$root_dir/v2" "$converge" >/dev/null
[[ "$(tag_at "$managed")" == v3.13.0 ]] \
    || fail "A retry after a failed transfer did not converge: the live entrypoint is still $(tag_at "$managed"). A same-size, same-mtime file was skipped by rsync's quick check."
[[ "$(cat "$managed/.ohif-digest")" =~ ^[0-9a-f]{64}$ ]] || fail 'A retry did not restore the digest record.'
end_scenario

# --- a marker path of the wrong type ----------------------------------------
#
# The record is invalidated before the tree is touched. If that step cannot
# run, nothing after it may run either.
rm -f "$managed/.ohif-digest"; mkdir -p "$managed/.ohif-digest/occupied"; own_server
scenario 'a marker path of the wrong type aborts before the tree is touched'
if OHIF_BUNDLE_DIR="$root_dir/v1" "$converge" >/dev/null 2>&1; then
    fail 'A digest path that could not be invalidated was ignored.'
fi
[[ "$(tag_at "$managed")" == v3.13.0 ]] || fail 'The tree was mutated after the marker step failed.'
[[ -d "$managed/.ohif-digest" ]] || fail 'The marker directory was removed.'
end_scenario

echo
echo 'converge OHIF dist integration tests passed (real sshd, real ssh, real rsync)'
