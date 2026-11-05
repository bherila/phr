#!/usr/bin/env bash
# Deliberately never inspect the application's directory, .env, database or disks.
set -euo pipefail
[[ $# == 4 && $1 =~ ^/tmp/phr-calibration\.[A-Za-z0-9]{10}$ && $2 =~ ^[a-f0-9]{64}$ \
    && $3 =~ ^[a-f0-9]{40}$ && $4 =~ ^/[A-Za-z0-9/._-]+$ && -x $4 ]] || exit 2
root=$1
bundle_digest=$2
candidate=$3
php_binary=$4
owned() {
    [[ -d "$root" && ! -L "$root" && $(realpath -- "$root") == "$root" \
        && $(stat -c %u -- "$root") == "$(id -u)" && $(stat -c %a -- "$root") == 700 \
        && -f "$root/.owned-synthetic-calibration" && ! -L "$root/.owned-synthetic-calibration" \
        && $(cat "$root/.owned-synthetic-calibration") == phr-host-calibration-v1 ]]
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
trap cleanup EXIT
umask 077
export PATH=/usr/bin:/bin
# Existing intake hashes into the null device. Allow precisely this verified
# non-data character device, never /dev or another host filesystem directory.
[[ ! -L /dev/null && -c /dev/null && $(realpath /dev/null) == /dev/null \
    && $(stat -c '%t:%T' /dev/null) == 1:3 ]] || exit 2
[[ -f "$root/bundle.tar.gz" && ! -L "$root/bundle.tar.gz" \
    && $(sha256sum "$root/bundle.tar.gz" | cut -d' ' -f1) == "$bundle_digest" ]] || exit 2
# The validator is independently transferred from the reviewed main checkout.
timeout --kill-after=5s 60s python3 "$root/validate-host-bundle.py" "$root/bundle.tar.gz" > "$root/bundle-size.json"
mkdir "$root/application" "$root/tmp"
tar --extract --gzip --file="$root/bundle.tar.gz" --directory="$root/application" --no-same-owner --no-same-permissions
python3 - "$root/application" "$candidate" <<'PY'
import hashlib
import json
from pathlib import Path
import sys
app = Path(sys.argv[1])
source = json.loads((app / "calibration-source.json").read_text())
if source["candidate"] != sys.argv[2]: raise ValueError("Candidate mismatch")
if source["lock_sha256"] != hashlib.sha256((app / "composer.lock").read_bytes()).hexdigest(): raise ValueError("Lock mismatch")
if (app / ".env").exists(): raise ValueError("Unexpected environment file")
if (app / "bootstrap/cache").exists(): raise ValueError("Unexpected compiled cache")
PY
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
run_php --prepare --shape=labs --records=1000 --history-records=2000 > "$root/prepare.log" 2> "$root/errors.log"
run_php --operation=preview > "$root/preview.json" 2> "$root/errors.log"
run_php --operation=lifecycle > "$root/lifecycle.json" 2> "$root/errors.log"
# CI fixtures contain only synthetic values and can expose a failed PHP exception
# for debugging. The SSH host has no GITHUB_ACTIONS variable and never does this.
if [[ ${GITHUB_ACTIONS:-} == true ]]; then
    python3 - "$root" <<'PYDEBUG' || {
import json
from pathlib import Path
import sys
for name in ("preview.json", "lifecycle.json"):
    json.loads((Path(sys.argv[1]) / name).read_text())
PYDEBUG
        head -c 16384 "$root/prepare.log" >&2
        head -c 16384 "$root/preview.json" >&2
        head -c 16384 "$root/lifecycle.json" >&2
        exit 1
    }
fi
# Never return raw fixtures, logs, DBs, source paths or archive bytes.
python3 - "$root" "$candidate" <<'PY'
import json
from pathlib import Path
import sys
root = Path(sys.argv[1])
size = json.loads((root / "bundle-size.json").read_text())
for operation in ("preview", "lifecycle"):
    data = (root / f"{operation}.json").read_bytes()
    if len(data) > 65536: raise ValueError("Aggregate output too large")
    result = json.loads(data)
    if result["operation"] != operation or result["shape"] != "labs": raise ValueError("Unexpected operation")
    if result["database_driver"] != "sqlite": raise ValueError("Unexpected database")
    result["measurement_kind"] = "deployment-host-isolated-sqlite"
    result["candidate"] = sys.argv[2]
    result["bundle_size"] = size
    result["sampled_rss_kib"] = None  # PHP ru_maxrss is retained; /proc is outside open_basedir.
    print(json.dumps(result))
PY
