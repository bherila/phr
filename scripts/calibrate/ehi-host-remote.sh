#!/usr/bin/env bash
# Deliberately never inspect the application's directory, .env, database or disks.
set -euo pipefail
[[ $# == 6 && $1 =~ ^/tmp/phr-calibration\.[A-Za-z0-9]{10}$ && $2 =~ ^[a-f0-9]{64}$ \
    && $3 =~ ^[a-f0-9]{40}$ && $4 =~ ^/[A-Za-z0-9/._-]+$ && -x $4 && $5 =~ ^[a-f0-9]{64}$ && $6 =~ ^[a-f0-9]{64}$ ]] || exit 2
root=$1
manifest_digest=$2
candidate=$3
php_binary=$4
nonce=$5
verifier_digest=$6
owned() {
    [[ -d "$root" && ! -L "$root" && $(realpath -- "$root") == "$root" \
        && $(stat -c %u -- "$root") == "$(id -u)" && $(stat -c %a -- "$root") == 700 \
        && -f "$root/.owned-synthetic-calibration" && ! -L "$root/.owned-synthetic-calibration" \
        && $(cat "$root/.owned-synthetic-calibration") == "phr-host-calibration-v1:$nonce" ]]
}
owned || exit 2
cleanup() {
    if owned && find "$root" -xdev -depth -delete; then
        echo '{"cleanup":"confirmed","scope":"owned-synthetic-root"}'
    else
        echo '{"cleanup":"unconfirmed","scope":"owned-synthetic-root"}'
        exit 1
    fi
}
stage=ownership
finish() {
    local status=$?
    if [[ $status != 0 ]]; then
        printf '{"stage":"%s","status":"refused","code":"phase_failed"}\n' "$stage"
    fi
    cleanup
}
trap finish EXIT
umask 077
export PATH=/usr/bin:/bin
# Existing intake hashes into the null device. Allow precisely this verified
# non-data character device, never /dev or another host filesystem directory.
[[ ! -L /dev/null && -c /dev/null && $(realpath /dev/null) == /dev/null \
    && $(stat -c '%t:%T' /dev/null) == 1:3 ]] || exit 2
stage=tree_admission
[[ -f "$root/verify-host-tree.php" && ! -L "$root/verify-host-tree.php" \
    && $(sha256sum "$root/verify-host-tree.php" | cut -d' ' -f1) == "$verifier_digest" ]] || exit 1
# No archive extraction or application bootstrap on the host before full verification.
timeout --kill-after=5s 60s env -i PATH=/usr/bin:/bin "$php_binary" -n \
    -d memory_limit=1G -d "open_basedir=$root" -d display_errors=0 -d log_errors=0 \
    "$root/verify-host-tree.php" "$root" "$manifest_digest" "$candidate" > "$root/bundle-size.json"
mkdir "$root/tmp"
mkdir -p "$root/application/bootstrap/cache" "$root/application/storage/framework/cache/data" \
    "$root/application/storage/framework/sessions" "$root/application/storage/framework/views" "$root/application/storage/logs"
cat > "$root/application/.env" <<'ENV'
APP_ENV=testing
APP_DEBUG=false
APP_KEY=base64:c3ludGhldGljLWNhbGlicmF0aW9uLWtleS0wMDAwMDA=
LOG_CHANNEL=null
DB_CONNECTION=sqlite
CACHE_STORE=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
PHR_STORAGE=local
ENV
cd "$root/application"
fixture="$root/tmp/phr-resource-deployment-host"
# No inherited deployment secrets/configuration reaches PHP, even during bootstrap.
run_php() {
    env -i PATH=/usr/bin:/bin TMPDIR="$root/tmp" APP_ENV=testing \
        DB_CONNECTION=sqlite DB_DATABASE="$fixture/database.sqlite" LOG_CHANNEL=null \
        CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync PHR_STORAGE=local \
        PHR_EHI_PREVIEW_MAX_SECONDS=240 \
        timeout --kill-after=5s 300s "$php_binary" -d memory_limit=1G \
        -d "open_basedir=$root:/dev/null" -d "sys_temp_dir=$root/tmp" -d "upload_tmp_dir=$root/tmp" \
        -d display_errors=0 -d log_errors=0 scripts/measure-phr-resources.php --dir="$fixture" "$@"
}
# A single bounded baseline measures actual-host PHP/filesystem, not production SQL.
# Realistic 1,000 incoming + 2,000 prior lab history; current application defaults only.
stage=prepare
run_php --prepare --shape=labs --records=1000 --history-records=2000 > "$root/prepare.log" 2> "$root/errors.log"
[[ -f "$fixture/state.json" ]] || exit 1
stage=preview
run_php --operation=preview > "$root/preview.json" 2> "$root/errors.log"
stage=lifecycle
run_php --operation=lifecycle > "$root/lifecycle.json" 2> "$root/errors.log"
stage=publish
# Emit only bounded operation aggregates. The CI runner validates and decorates them.
env -i PATH=/usr/bin:/bin "$php_binary" -n -d memory_limit=1G -d "open_basedir=$root" \
    -d display_errors=0 -d log_errors=0 -r '
foreach (["preview", "lifecycle"] as $operation) {
    $path = $argv[1]."/".$operation.".json";
    if (!is_file($path) || is_link($path) || filesize($path) > 65536) { exit(1); }
    $result = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (($result["operation"] ?? null) !== $operation || ($result["shape"] ?? null) !== "labs"
        || ($result["database_driver"] ?? null) !== "sqlite") { exit(1); }
    echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
}
' -- "$root"
