#!/usr/bin/env bash
#
# Write a deploy key and pinned host key for the OHIF convergence step under one SSH alias,
# leaving every other SSH setting on the runner alone. A job may also hold keys for private
# dependencies -- the application deploy job authorizes the private MCP bridge over SSH before
# this runs -- so this never overwrites ~/.ssh/config or a shared known_hosts file.
#
# Mirrors the pinned shared cPanel action's scripts/configure-ssh.sh deliberately: same alias
# shape, same per-alias key and known_hosts files, same append-with-duplicate-guard. Two writers
# using opposite conventions on one runner is how a working deploy becomes an intermittent one.
#
# Runs ON THE RUNNER. Environment: SSH_ALIAS, SSH_HOST_NAME, SSH_USER_NAME, SSH_PRIVATE_KEY,
# SSH_KNOWN_HOSTS.
set -euo pipefail

for pair in "alias:${SSH_ALIAS:-}" "host:${SSH_HOST_NAME:-}" "username:${SSH_USER_NAME:-}"; do
    value=${pair#*:}
    case $value in
        '' | *[!A-Za-z0-9._@-]*)
            echo "The SSH ${pair%%:*} is empty or contains characters not allowed in an SSH config value." >&2
            exit 2 ;;
    esac
done

if [ -z "${SSH_PRIVATE_KEY:-}" ] || [ -z "${SSH_KNOWN_HOSTS:-}" ]; then
    echo 'ssh-private-key and ssh-known-hosts are both required; host keys are never trusted on first use.' >&2
    exit 2
fi

install -d -m 700 "$HOME/.ssh"
key="$HOME/.ssh/$SSH_ALIAS.key"
known="$HOME/.ssh/$SSH_ALIAS.known_hosts"
(umask 077 && printf '%s\n' "$SSH_PRIVATE_KEY" >"$key")
(umask 077 && printf '%s\n' "$SSH_KNOWN_HOSTS" >"$known")

if [ -f "$HOME/.ssh/config" ] && grep -Eq "^Host[[:space:]]+$SSH_ALIAS([[:space:]]|$)" "$HOME/.ssh/config"; then
    echo "~/.ssh/config already defines Host $SSH_ALIAS; choose another alias." >&2
    exit 2
fi

{
    printf '\nHost %s\n' "$SSH_ALIAS"
    printf '    HostName %s\n' "$SSH_HOST_NAME"
    printf '    User %s\n' "$SSH_USER_NAME"
    printf '    IdentityFile %s\n' "$key"
    printf '    IdentitiesOnly yes\n'
    printf '    UserKnownHostsFile %s\n' "$known"
    printf '    StrictHostKeyChecking yes\n'
    printf '    BatchMode yes\n'
} >>"$HOME/.ssh/config"
chmod 600 "$HOME/.ssh/config"
