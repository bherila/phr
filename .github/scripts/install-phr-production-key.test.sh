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
ssh-keygen -q -t ed25519 -N '' -C wrong-key -f "$scratch/wrong-key"
POLICY_PRIVATE_KEY=$(cat "$scratch/key")
export POLICY_PRIVATE_KEY PUBLIC_KEY="$public_key"
# Mimic the workflow's preflight barrier: no transport can run with a wrong key.
mkdir "$scratch/bin"
cat > "$scratch/bin/ssh" <<'SSH'
#!/usr/bin/env bash
touch "$FIXTURE_SSH_TRACE"
SSH
chmod +x "$scratch/bin/ssh"
export FIXTURE_SSH_TRACE="$scratch/ssh-called"
preflight_then_transport() {
    bash "$here/verify-phr-production-key-pair.sh" > "$scratch/pair-output" 2>&1 \
        && PATH="$scratch/bin:$PATH" ssh synthetic-host
}
preflight_then_transport
[[ -f "$FIXTURE_SSH_TRACE" ]]
rm "$FIXTURE_SSH_TRACE"
POLICY_PRIVATE_KEY=$(cat "$scratch/wrong-key")
if preflight_then_transport; then echo 'Mismatched key reached SSH.' >&2; exit 1; fi
[[ ! -e "$FIXTURE_SSH_TRACE" ]]
grep -Fxq 'Dedicated PHR key pair validation failed; details redacted.' "$scratch/pair-output"
POLICY_PRIVATE_KEY='not a private key'
if preflight_then_transport; then exit 1; fi
[[ ! -e "$FIXTURE_SSH_TRACE" ]]
ssh-keygen -q -t ed25519 -N synthetic-passphrase -C encrypted-fixture -f "$scratch/encrypted-key"
POLICY_PRIVATE_KEY=$(cat "$scratch/encrypted-key")
if preflight_then_transport; then echo 'Encrypted key reached SSH.' >&2; exit 1; fi
[[ ! -e "$FIXTURE_SSH_TRACE" ]]
unset POLICY_PRIVATE_KEY PUBLIC_KEY
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

# Interpose PHP filesystem calls only in a private fixture copy. This injects an
# actual foreign append at the last mutation boundary; the old rename loses it.
python3 - "$here/install-phr-production-key.sh" "$scratch/racing-installer.sh" <<'PYFIXTURE'
from pathlib import Path
import sys
source = Path(sys.argv[1]).read_text()
assert source.count('<?php\n') == 1
fixture = r'''
namespace PhrKeyFixture;
use \RuntimeException;
use \Throwable;
function injectKeyRace(string $path): void {
    $mode = getenv('FIXTURE_KEY_RACE');
    if ($mode === 'append') { \file_put_contents($path, "\nforeign-concurrent-key", FILE_APPEND); }
    elseif ($mode === 'replace') {
        \rename($path, $path.'.prior-inode');
        \file_put_contents($path, "foreign-replacement-key\n");
    }
}
function fwrite($handle, $bytes) {
    $path = \stream_get_meta_data($handle)['uri'];
    if (str_ends_with($path, '/authorized_keys')) { injectKeyRace($path); }
    return \fwrite($handle, $bytes);
}
function rename($from, $to) {
    if (str_ends_with($to, '/authorized_keys')) { injectKeyRace($to); }
    return \rename($from, $to);
}
function fopen($path, $mode) {
    if (getenv('FIXTURE_KEY_RACE') === 'create' && str_ends_with($path, '/authorized_keys') && $mode === 'x+b') {
        \file_put_contents($path, "foreign-created-key\n");
    }
    return \fopen($path, $mode);
}
'''
Path(sys.argv[2]).write_text(source.replace('<?php\n', '<?php\n'+fixture, 1))
PYFIXTURE
race_install() {
    env HOME="$task_home" FIXTURE_KEY_RACE="$1" bash "$scratch/racing-installer.sh" \
        "$php" "$release" "$commit" "$public_key" 123 1 > "$scratch/output" 2>&1
}
reset_fixture
race_install append
# No final newline in the foreign append: our leading separator must remain valid.
grep -Fxq "$foreign" "$task_home/.ssh/authorized_keys"
grep -Fxq 'foreign-concurrent-key' "$task_home/.ssh/authorized_keys"
grep -Eq '^restrict ssh-ed25519 [A-Za-z0-9+/]{68} phr-production-policy$' "$task_home/.ssh/authorized_keys"
cmp <(printf '%s' "$foreign") "$task_home/.ssh/authorized_keys.phr-policy-backup-123-1"
reset_fixture
rm "$task_home/.ssh/authorized_keys"
race_install append
grep -Fxq 'foreign-concurrent-key' "$task_home/.ssh/authorized_keys"
grep -Eq '^restrict ssh-ed25519 [A-Za-z0-9+/]{68} phr-production-policy$' "$task_home/.ssh/authorized_keys"
reset_fixture
if race_install replace; then echo 'Replaced authorized_keys inode accepted.' >&2; exit 1; fi
[[ $(cat "$task_home/.ssh/authorized_keys") == foreign-replacement-key ]]
grep -Fxq 'PHR production key installation refused; details redacted.' "$scratch/output"
reset_fixture
rm "$task_home/.ssh/authorized_keys"
if race_install create; then echo 'Concurrent authorized_keys creation accepted.' >&2; exit 1; fi
[[ $(cat "$task_home/.ssh/authorized_keys") == foreign-created-key ]]
grep -Fxq 'PHR production key installation refused; details redacted.' "$scratch/output"
reset_fixture
rm "$task_home/.ssh/authorized_keys"
install_key
[[ $(stat -c '%a' "$task_home/.ssh/authorized_keys") == 600 ]]
[[ ! -s "$task_home/.ssh/authorized_keys.phr-policy-backup-123-1" ]]

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

for path in ssh authorized lock backup; do
    reset_fixture
    case "$path" in
        ssh) mv "$task_home/.ssh" "$scratch/linked-ssh"; ln -s "$scratch/linked-ssh" "$task_home/.ssh" ;;
        authorized) mv "$task_home/.ssh/authorized_keys" "$scratch/linked-keys"; ln -s "$scratch/linked-keys" "$task_home/.ssh/authorized_keys" ;;
        lock) printf 'sentinel\n' > "$scratch/linked-lock"; ln -s "$scratch/linked-lock" "$task_home/.ssh/authorized_keys.lock" ;;
        backup) printf 'sentinel\n' > "$scratch/linked-backup"; ln -s "$scratch/linked-backup" "$task_home/.ssh/authorized_keys.phr-policy-backup-123-1" ;;
    esac
    reject
    [[ "$path" != backup || $(cat "$scratch/linked-backup") == sentinel ]]
    rm -rf -- "$scratch/linked-ssh" "$scratch/linked-keys" "$scratch/linked-lock" "$scratch/linked-backup"
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
