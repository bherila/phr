#!/usr/bin/env bash
#
# The point of this harness is the preservation property: a pre-existing private-dependency
# SSH configuration must survive. The previous inline step overwrote ~/.ssh/config and
# ~/.ssh/known_hosts wholesale, which would have silently discarded it.

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly script_dir
readonly configure="$script_dir/configure-ohif-ssh.sh"
test_root="$(mktemp -d)"
readonly test_root
trap 'rm -rf "$test_root"' EXIT

export HOME="$test_root/home"
mkdir -p "$HOME"
export SSH_ALIAS=phr-ohif
export SSH_HOST_NAME=host.example.test
export SSH_USER_NAME=cpanel-deploy
export SSH_PRIVATE_KEY='-----BEGIN OPENSSH PRIVATE KEY-----
c3ludGhldGlj
-----END OPENSSH PRIVATE KEY-----'
export SSH_KNOWN_HOSTS='host.example.test ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAISYNTHETIC'

fail() { echo "$1" >&2; exit 1; }

# A private-dependency config, as webfactory/ssh-agent plus a github.com entry would leave it.
install -d -m 700 "$HOME/.ssh"
printf 'Host github.com\n    IdentityFile %s/.ssh/mcp_bridge\n' "$HOME" >"$HOME/.ssh/config"
printf 'github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPRIVATEDEP\n' >"$HOME/.ssh/known_hosts"
printf 'synthetic-bridge-key\n' >"$HOME/.ssh/mcp_bridge"

"$configure"

# --- the preservation property -----------------------------------------------
grep -q 'Host github.com' "$HOME/.ssh/config" \
    || fail 'The private-dependency ssh config entry was discarded.'
grep -q 'PRIVATEDEP' "$HOME/.ssh/known_hosts" \
    || fail 'The shared known_hosts file was overwritten.'
[[ "$(cat "$HOME/.ssh/mcp_bridge")" == synthetic-bridge-key ]] \
    || fail 'The private-dependency key was clobbered.'

# --- the alias is scoped to its own files ------------------------------------
grep -q "Host $SSH_ALIAS" "$HOME/.ssh/config" || fail 'The alias was not written.'
[[ -f "$HOME/.ssh/$SSH_ALIAS.key" ]] || fail 'The alias key file is missing.'
[[ -f "$HOME/.ssh/$SSH_ALIAS.known_hosts" ]] || fail 'The alias known_hosts file is missing.'
grep -q "UserKnownHostsFile $HOME/.ssh/$SSH_ALIAS.known_hosts" "$HOME/.ssh/config" \
    || fail 'The alias does not pin its own known_hosts file.'
grep -q 'StrictHostKeyChecking yes' "$HOME/.ssh/config" \
    || fail 'Strict host key checking was not required.'
grep -q 'BatchMode yes' "$HOME/.ssh/config" || fail 'Noninteractive mode was not required.'
grep -q 'IdentitiesOnly yes' "$HOME/.ssh/config" || fail 'IdentitiesOnly was not required.'

# --- permissions --------------------------------------------------------------
[[ "$(stat -c '%a' "$HOME/.ssh/$SSH_ALIAS.key")" == 600 ]] || fail 'The key file is not 0600.'
[[ "$(stat -c '%a' "$HOME/.ssh/config")" == 600 ]] || fail 'The ssh config is not 0600.'

# --- ssh itself must agree, not just the file --------------------------------
if command -v ssh >/dev/null; then
    composed="$(ssh -F "$HOME/.ssh/config" -G "$SSH_ALIAS" 2>/dev/null)"
    grep -qi "^hostname $SSH_HOST_NAME$" <<<"$composed" || fail 'ssh did not resolve the alias hostname.'
    grep -qi "^user $SSH_USER_NAME$" <<<"$composed" || fail 'ssh did not resolve the alias user.'
    # ssh -G normalizes `yes` to `true`, so assert on what ssh reports, not on
    # the spelling used in the config file.
    grep -qiE '^stricthostkeychecking (yes|true)$' <<<"$composed" \
        || fail 'ssh did not compose strict host key checking.'
    grep -qiE '^batchmode (yes|true)$' <<<"$composed" \
        || fail 'ssh did not compose noninteractive mode.'
    # The private-dependency host must be untouched by the alias block.
    github_identity="$(ssh -F "$HOME/.ssh/config" -G github.com 2>/dev/null | grep -i "^identityfile" || true)"
    grep -q 'mcp_bridge' <<<"$github_identity" \
        || fail 'The alias block changed how github.com resolves.'
else
    echo 'note: ssh not on PATH; skipped -G composition checks' >&2
fi

# --- a duplicate alias is refused rather than silently appended twice --------
if "$configure" 2>/dev/null; then
    fail 'A duplicate alias definition was accepted.'
fi
[[ "$(grep -c "^Host $SSH_ALIAS\$" "$HOME/.ssh/config")" == 1 ]] \
    || fail 'The alias was defined more than once.'

# --- unsafe inputs are refused -----------------------------------------------
( export SSH_ALIAS='bad alias'; "$configure" 2>/dev/null ) && fail 'An unsafe alias was accepted.'
( export SSH_HOST_NAME='-oProxyCommand=x'; "$configure" 2>/dev/null ) && fail 'An option-like host was accepted.'
( export SSH_KNOWN_HOSTS=''; "$configure" 2>/dev/null ) && fail 'An empty known_hosts was accepted.'

echo 'configure OHIF ssh tests passed'
