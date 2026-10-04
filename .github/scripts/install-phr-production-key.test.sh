#!/usr/bin/env bash
# Actual PHP and synthetic filesystem only; never contact SSH or Laravel.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
scratch=$(mktemp -d)
trap 'rm -rf -- "$scratch"' EXIT
php=$(command -v php)
task_home="$scratch/home"
control="$task_home/.deployments/phr-laravel"
stable="$task_home/phr-laravel"
release=7b33fe7445aa-37239370303-1
commit=7b33fe7445aa6d0a002f2e752497282b04c360a2
ssh-keygen -q -t ed25519 -N '' -C fixture -f "$scratch/key"
public_key=$(cat "$scratch/key.pub")
foreign='command="echo preserve",no-port-forwarding ssh-rsa AAAA foreign-app'
reset_fixture() {
    rm -rf -- "$task_home"
    mkdir -p "$control/state" "$control/releases" "$control/shared/public/ohif" "$control/shared/storage/framework" \
        "$stable/public" "$task_home/.ssh"
    ln -s "$control/shared/storage" "$stable/storage"
    ln -s "$control/shared/public/ohif" "$stable/public/ohif"
    printf 'release=%s\ncommit=%s\n' "$release" "$commit" > "$stable/.deploy-release"
    printf 'synthetic maintenance\n' > "$control/shared/storage/framework/down"
    printf '%s' "$foreign" > "$task_home/.ssh/authorized_keys"
    chmod 600 "$task_home/.ssh/authorized_keys"
}
install_key() {
    env HOME="$task_home" bash "$here/install-phr-production-key.sh" "$php" "$release" "$commit" "${1:-$public_key}" 123 1 \
        > "$scratch/output" 2>&1
}
reject() {
    if install_key "$@"; then echo 'Unsafe key/state accepted.' >&2; exit 1; fi
    grep -Fxq 'PHR production key installation refused; details redacted.' "$scratch/output"
    if grep -Fq "$public_key" "$scratch/output"; then echo 'Public key unexpectedly logged.' >&2; exit 1; fi
}
app_hash() { find "$control" "$stable" -type f -exec sha256sum {} + | sort; }
reset_fixture
before=$(app_hash)
install_key
[[ $(app_hash) == "$before" ]]
grep -Fxq "$foreign" "$task_home/.ssh/authorized_keys"
cmp <(printf '%s' "$foreign") "$task_home/.ssh/authorized_keys.phr-policy-backup-123-1"
[[ $(stat -c '%a' "$task_home/.ssh/authorized_keys") == 600 \
    && $(stat -c '%a' "$task_home/.ssh/authorized_keys.phr-policy-backup-123-1") == 600 ]]
grep -Fxq 'phr-production-key identity=exact restricted=yes authorized=installed' "$scratch/output"
installed=$(sha256sum "$task_home/.ssh/authorized_keys")
install_key
[[ $(sha256sum "$task_home/.ssh/authorized_keys") == "$installed" ]]
grep -Fxq 'phr-production-key identity=exact restricted=yes authorized=unchanged' "$scratch/output"

for key in 'ssh-rsa AAAA wrong-type' 'ssh-ed25519 AAAA short' "$public_key"$'\ncommand=bad' \
    "${public_key% *} invalid comment" "${public_key% *} ;touch-bad"; do
    reset_fixture
    reject "$key"
    [[ $(cat "$task_home/.ssh/authorized_keys") == "$foreign" && ! -e "$task_home/.ssh/authorized_keys.lock" ]]
done
# A same-length base64 value must still contain the correct OpenSSH wire format.
bad_blob=$(printf '%068d' 0)
reset_fixture
reject "ssh-ed25519 $bad_blob"

for path in ssh authorized lock; do
    reset_fixture
    case "$path" in
        ssh) mv "$task_home/.ssh" "$scratch/linked-ssh"; ln -s "$scratch/linked-ssh" "$task_home/.ssh" ;;
        authorized) mv "$task_home/.ssh/authorized_keys" "$scratch/linked-keys"; ln -s "$scratch/linked-keys" "$task_home/.ssh/authorized_keys" ;;
        lock) printf 'sentinel\n' > "$scratch/linked-lock"; ln -s "$scratch/linked-lock" "$task_home/.ssh/authorized_keys.lock" ;;
    esac
    reject
    rm -rf -- "$scratch/linked-ssh" "$scratch/linked-keys" "$scratch/linked-lock"
done

for state in busy interrupted wrong-release wrong-commit noncanonical; do
    reset_fixture
    case "$state" in
        busy) mkdir "$control/deploy.lock" ;;
        interrupted) mkdir "$control/state/unfinished" ;;
        wrong-release) sed -i 's/^release=.*/release=other/' "$stable/.deploy-release" ;;
        wrong-commit) sed -i 's/^commit=.*/commit=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/' "$stable/.deploy-release" ;;
        noncanonical) rm "$stable/storage"; ln -s "$scratch" "$stable/storage" ;;
    esac
    reject
    [[ $(cat "$task_home/.ssh/authorized_keys") == "$foreign" && ! -e "$task_home/.ssh/authorized_keys.lock" ]]
done
reset_fixture
printf '%s\n' "$public_key" > "$task_home/.ssh/authorized_keys"
reject
[[ $(cat "$task_home/.ssh/authorized_keys") == "$public_key" ]]
# A held account key lock prevents even a backup or key append.
reset_fixture
touch "$task_home/.ssh/authorized_keys.lock"
exec 9>"$task_home/.ssh/authorized_keys.lock"
flock 9
reject
exec 9>&-
[[ $(cat "$task_home/.ssh/authorized_keys") == "$foreign" && ! -e "$task_home/.ssh/authorized_keys.phr-policy-backup-123-1" ]]
echo 'PHR key format, canonical paths, exact idle identity, foreign keys, backups, lock and idempotence fixtures passed.'
