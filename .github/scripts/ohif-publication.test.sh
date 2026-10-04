#!/usr/bin/env bash
# Actual filesystem/checksum/rsync operations with synthetic local SSH transport.
set -euo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
scratch=$(mktemp -d)
trap 'rm -rf -- "$scratch"' EXIT
fixture_home="$scratch/home"
bundle="$scratch/bundle"
mkdir -p "$fixture_home" "$bundle/assets" "$scratch/bin"
printf 'root marker excluded\n' > "$bundle/.ohif-digest"
printf 'nested asset included\n' > "$bundle/assets/.ohif-digest"
printf '<title>OHIF</title><script src="/ohif/app.bundle.abc.js"></script><script src="/ohif/app-config.js"></script>\n' > "$bundle/index.html"
printf 'console.log("a");\n' > "$bundle/app.bundle.abc.js"
printf 'window.config = { routerBasename: "/ohif/", defaultDataSourceName: "dicomjson" };\n' > "$bundle/app-config.js"
cat > "$scratch/bin/ssh" <<'SSH'
#!/usr/bin/env bash
set -euo pipefail
[[ "$1" == fixture-host ]] || exit 2
shift
exec env HOME="$FIXTURE_HOME" PATH="$FIXTURE_BIN:$PATH" bash -c "$1"
SSH
cat > "$scratch/bin/rsync" <<'RSYNC'
#!/usr/bin/env bash
set -euo pipefail
[[ "${FAIL_RSYNC:-}" != 1 ]] || exit 1
args=("$@")
destination=${args[${#args[@]}-1]}
relative=${destination#fixture-host:~/}
[[ "$relative" == .deployments/phr-laravel/shared/public/ohif/ ]] || exit 2
args[${#args[@]}-1]="$FIXTURE_HOME/$relative"
exec "$REAL_RSYNC" "${args[@]}"
RSYNC
cat > "$scratch/bin/mktemp" <<'MKTEMP'
#!/usr/bin/env bash
if [[ "${FAIL_GENERATION:-}" == 1 && "$*" == */.generation.* ]]; then exit 91; fi
exec "$REAL_MKTEMP" "$@"
MKTEMP
chmod +x "$scratch/bin/mktemp"
REAL_MKTEMP=$(command -v mktemp)
export REAL_MKTEMP FIXTURE_BIN="$scratch/bin"
chmod +x "$scratch/bin/ssh" "$scratch/bin/rsync"
REAL_RSYNC=$(command -v rsync)
export REAL_RSYNC FIXTURE_HOME="$fixture_home" PHR_OHIF_SSH_BIN="$scratch/bin/ssh" PHR_OHIF_RSYNC_BIN="$scratch/bin/rsync" \
    OHIF_SSH_TARGET=fixture-host OHIF_BUNDLE_DIR="$bundle" OHIF_RUN_ID=10 OHIF_ARTIFACT_ID=100 \
    GITHUB_RUN_ID=100 GITHUB_RUN_ATTEMPT=1
OHIF_ARTIFACT_DIGEST="sha256:$(printf 'a%.0s' {1..64})"
OHIF_SOURCE_COMMIT="$(printf 'a%.0s' {1..40})"
export OHIF_ARTIFACT_DIGEST OHIF_SOURCE_COMMIT
control="$fixture_home/.deployments/phr-laravel"
root="$control/shared/public/ohif"
publication="$control/shared/ohif-publication"
publish() { bash "$script_dir/publish-ohif-dist.sh" > "$scratch/output" 2>&1; }
reject() { if publish; then cat "$scratch/output"; exit 1; fi; }
FAIL_GENERATION=1 reject
[[ ! -e "$control/deploy.lock" ]]
publish
[[ ! -e "$control/deploy.lock" ]]
grep -Fxq 'run_id=10' "$publication"
cmp "$bundle/assets/.ohif-digest" "$root/assets/.ohif-digest"
cp "$publication" "$scratch/first-publication"
# A later desired run publishes its own identity even when the bytes are equal.
OHIF_RUN_ID=11 OHIF_ARTIFACT_ID=101 publish
grep -Fxq 'run_id=11' "$publication"
cp "$publication" "$scratch/current-publication"
OHIF_RUN_ID=9 OHIF_ARTIFACT_ID=999 publish
grep -Fq 'superseded by run=11 artifact=101' "$scratch/output"
cmp "$publication" "$scratch/current-publication"
# The same immutable artifact cannot be relabeled with different metadata.
OHIF_RUN_ID=11 OHIF_ARTIFACT_ID=101 OHIF_ARTIFACT_DIGEST="sha256:$(printf 'b%.0s' {1..64})" reject
cmp "$publication" "$scratch/current-publication"
# Nested marker-named files participate in identity and checksum repair.
printf 'corrupted nested asset\n' > "$root/assets/.ohif-digest"
OHIF_RUN_ID=11 OHIF_ARTIFACT_ID=101 publish
cmp "$bundle/assets/.ohif-digest" "$root/assets/.ohif-digest"
# Corruption with a matching v2 marker is repaired from the exact desired build.
printf 'console.log("b");\n' > "$root/app.bundle.abc.js"
touch -r "$bundle/app.bundle.abc.js" "$root/app.bundle.abc.js"
OHIF_RUN_ID=11 OHIF_ARTIFACT_ID=101 publish
cmp "$bundle/app.bundle.abc.js" "$root/app.bundle.abc.js"
# Both writer types use the same remote mutex, and borrowed locks survive.
mkdir "$control/deploy.lock"
printf 'app-release\n' > "$control/deploy.lock/owner"
printf 'app-release\n' > "$control/generation"
reject
[[ "$(cat "$control/deploy.lock/owner")" == app-release ]]
OHIF_RUN_ID=11 OHIF_ARTIFACT_ID=101 OHIF_LOCK_OWNER=app-release publish
[[ "$(cat "$control/deploy.lock/owner")" == app-release ]]
rm "$control/deploy.lock/owner"
rmdir "$control/deploy.lock"
# A failed transfer leaves no trusted digest and keeps its mutex for recovery.
printf 'console.log("new");\n' > "$bundle/app.bundle.abc.js"
OHIF_RUN_ID=12 OHIF_ARTIFACT_ID=102 FAIL_RSYNC=1 reject
[[ -d "$control/deploy.lock" && ! -e "$root/.ohif-digest" ]]
rm "$control/deploy.lock/owner"
rmdir "$control/deploy.lock"
OHIF_RUN_ID=12 OHIF_ARTIFACT_ID=102 publish
[[ ! -e "$control/deploy.lock" ]]
grep -Fxq 'run_id=12' "$publication"
# Never follow a publication record link into another file.
mv "$publication" "$scratch/publication"
printf 'SECRET\n' > "$scratch/private"
ln -s "$scratch/private" "$publication"
reject
if grep -Fq SECRET "$scratch/output"; then exit 1; fi
rm "$publication"
mv "$scratch/publication" "$publication"
echo 'OHIF source/artifact proof, byte repair, stale requests, mutex and interruption fixtures passed.'
