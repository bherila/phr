#!/usr/bin/env bash
# Actual control filesystem with a synthetic PHP maintenance command.
set -euo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
scratch=$(mktemp -d)
trap 'rm -rf -- "$scratch"' EXIT
fixture_home="$scratch/home"
control="$fixture_home/.deployments/phr-laravel"
stable="$fixture_home/phr-laravel"
mkdir -p "$control"/{state,deploy.lock,shared/storage/framework} "$stable"
ln -s "$control/shared/storage" "$stable/storage"
touch "$stable/artisan"
printf 'release=7b33fe7445aa-37239370303-1\ncommit=7b33fe7445aa6d0a002f2e752497282b04c360a2\n' > "$stable/.deploy-release"
printf 'recovery-1-1\n' > "$control/deploy.lock/owner"
printf 'synthetic-maintenance\n' > "$stable/storage/framework/down"
touch -d @1791152420 "$stable/storage/framework/down"
cat > "$scratch/php" <<'PHP'
#!/usr/bin/env bash
set -euo pipefail
[[ "$1" == -d && "$2" == memory_limit=1G && "$3" == artisan ]]
echo SECRET_STDOUT
echo SECRET_STDERR >&2
case "$4" in up) rm storage/framework/down ;; down) printf 'synthetic-maintenance\n' > storage/framework/down ;; *) exit 2 ;; esac
PHP
chmod +x "$scratch/php"
remote() { env HOME="$fixture_home" bash "$script_dir/resume-reviewed-release-remote.sh" "$1" recovery-1-1 "${2:-}" "$scratch/php"; }
reject() { if remote "$@" > "$scratch/output" 2>&1; then exit 1; fi; [[ -f "$stable/storage/framework/down" ]]; }
marker=$(remote inspect)
touch -d @1791156000 "$stable/storage/framework/down"
reject up "$marker"
touch -d @1791152420 "$stable/storage/framework/down"
printf 'other-owner\n' > "$control/deploy.lock/owner"
reject up "$marker"
printf 'recovery-1-1\n' > "$control/deploy.lock/owner"
printf 'operator-modified\n' > "$stable/storage/framework/down"
touch -d @1791152420 "$stable/storage/framework/down"
reject up "$marker"
printf 'synthetic-maintenance\n' > "$stable/storage/framework/down"
touch -d @1791152420 "$stable/storage/framework/down"
cp "$stable/.deploy-release" "$scratch/metadata"
printf 'release=wrong\ncommit=7b33fe7445aa6d0a002f2e752497282b04c360a2\n' > "$stable/.deploy-release"
reject up "$marker"
cp "$scratch/metadata" "$stable/.deploy-release"
remote up "$marker" > "$scratch/output" 2>&1
[[ ! -e "$stable/storage/framework/down" ]]
if grep -Fq SECRET "$scratch/output"; then exit 1; fi
remote down > "$scratch/output" 2>&1
[[ -f "$stable/storage/framework/down" ]]
if grep -Fq SECRET "$scratch/output"; then exit 1; fi
echo 'Recovery release identity, owner, operator-marker window/hash and redaction fixtures passed.'
