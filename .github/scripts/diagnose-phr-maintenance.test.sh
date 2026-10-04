#!/usr/bin/env bash
# Synthetic production layout, actual PHP and shared helpers; local SSH/HTTP transports.
set -euo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
[[ -n ${PHR_SHARED_ACTION_DIR:-} ]] || { echo 'Verified shared action checkout required for diagnostic fixtures.' >&2; exit 2; }
scratch=$(mktemp -d)
trap 'rm -rf -- "$scratch"' EXIT
fixture_home="$scratch/home"
control="$fixture_home/.deployments/phr-laravel"
shared="$control/shared"
stable="$fixture_home/phr-laravel"
mkdir -p "$control"/{state,recovery,releases} "$shared/storage"/{framework/views,framework/sessions,framework/cache/data,logs,app/private/data} \
    "$shared/public/ohif" "$stable"/{vendor,bootstrap/cache,resources/views,public} "$scratch/bin"
ln -s "$shared/storage" "$stable/storage"
ln -s "$shared/public/ohif" "$stable/public/ohif"
touch "$shared/storage/app/database.sqlite" "$shared/storage/framework/down" "$stable/vendor/autoload.php"
export DIAG_SSH_TARGET=fixture-host DIAG_EXPECTED_RELEASE=fixture-release \
    DIAG_EXPECTED_COMMIT=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
DIAG_PHP_BINARY=$(command -v php)
export DIAG_PHP_BINARY FIXTURE_HOME="$fixture_home" FIXTURE_CURL_LOG="$scratch/curl-calls"
printf 'release=%s\ncommit=%s\n' "$DIAG_EXPECTED_RELEASE" "$DIAG_EXPECTED_COMMIT" >"$stable/.deploy-release"
printf 'synthetic private cron\n' >"$control/recovery/$DIAG_EXPECTED_RELEASE.cron"
cat >"$stable/bootstrap/app.php" <<'PHP'
<?php
namespace Illuminate\Contracts\Console { interface Kernel {} }
namespace {
function config($key) {
    return match ($key) {
        'queue.default'=>'sync', 'queue.connections.sync'=>['driver'=>'sync'],
        'queue.failed'=>['driver'=>null], 'database.default'=>'sqlite',
    };
}
class Fixture {
    public function getCachedConfigPath() { return getcwd().'/bootstrap/cache/config.php'; }
    public function make($key) { return $this; }
    public function bootstrap() {
        if (getenv('NOISY_DIAG_BOOTSTRAP')) {
            register_shutdown_function(static function (): void { fwrite(STDOUT, "\0PRIVATE_DIAGNOSTIC_SECRET"); });
        }
    }
    public function paths() { return []; }
    public function databasePath($path) { return '/nonexistent/'.$path; }
    public function getMigrationFiles($paths) { return []; }
    public function getRepository() { return $this; }
    public function repositoryExists() { return true; }
    public function getRan() { return []; }
    public function resolveConnection($name) { return $this; }
    public function getConfig() { return ['driver'=>'sqlite','database'=>'storage/app/database.sqlite']; }
    public function getName() { return 'sqlite'; }
    public function isDownForMaintenance() { return file_exists(getcwd().'/storage/framework/down'); }
}
return new Fixture;
}
PHP
"$DIAG_PHP_BINARY" /dev/stdin "$stable" >"$stable/bootstrap/cache/config.php" <<'PHP'
<?php
$stable = $argv[1];
$config = [
    'view'=>['paths'=>[$stable.'/resources/views'],'compiled'=>$stable.'/storage/framework/views'],
    'session'=>['files'=>$stable.'/storage/framework/sessions'],
    'cache'=>['default'=>'file','stores'=>['file'=>['driver'=>'file','path'=>$stable.'/storage/framework/cache/data','lock_path'=>null]]],
    'filesystems'=>['default'=>'local','disks'=>['local'=>['driver'=>'local','root'=>$stable.'/storage/app/private']]],
    'logging'=>['channels'=>['single'=>['driver'=>'single','path'=>$stable.'/storage/logs/laravel.log']]],
];
echo '<?php return '.var_export($config,true).';';
PHP
cat >"$scratch/bin/ssh" <<'SSH'
#!/usr/bin/env bash
set -euo pipefail
while [[ ${1:-} == -o ]]; do shift 2; done
[[ $1 == fixture-host ]] || exit 2
shift
if [[ ${FLOOD_DIAG_SSH:-} == 1 ]]; then head -c 2097152 /dev/zero; exit; fi
if [[ ${FAIL_DIAG_CLEANUP:-} == 1 && $1 == 'rm -f '* ]]; then exit 1; fi
env HOME="$FIXTURE_HOME" bash -c "$1"
SSH
cat >"$scratch/bin/curl" <<'CURL'
#!/usr/bin/env bash
set -euo pipefail
output=''
for ((number=1; number<=$#; number++)); do
    if [[ ${!number} == --output ]]; then next=$((number + 1)); output=${!next}; fi
done
url=${!#}
printf '%s\n' "$url" >>"$FIXTURE_CURL_LOG"
case "$url" in
    https://phr.bherila.net/up)
        if [[ ${RACE_DIAG_WRITER:-} == 1 ]]; then mkdir "$FIXTURE_HOME/.deployments/phr-laravel/deploy.lock"; fi
        printf '200' ;;
    https://phr.bherila.net/login) printf '503' ;;
    https://phr.bherila.net/_deploy-php-check-*.php)
        if [[ ${BAD_DIAG_WEB:-} == 1 ]]; then
            printf '<!DOCTYPE html>PRIVATE_WEB_SECRET' >"$output"
            printf '200|text/html'
        else
            printf '8.5|1024M|litespeed' >"$output"
            printf '200|text/plain'
        fi ;;
    *) exit 2 ;;
esac
CURL
chmod +x "$scratch/bin/ssh" "$scratch/bin/curl"
export PATH="$scratch/bin:$PATH"
diagnose() { bash "$script_dir/diagnose-phr-maintenance.sh"; }
reject() {
    if diagnose >"$scratch/output" 2>&1; then echo 'unexpected diagnostic pass' >&2; exit 1; fi
    if grep -aEq 'PRIVATE_|synthetic private cron' "$scratch/output"; then echo 'private diagnostic output disclosed' >&2; exit 1; fi
    [[ -z $(find "$stable/public" -maxdepth 1 -name '_deploy-php-check-*.php' -print) ]] || { echo 'web probe leaked' >&2; exit 1; }
}
before=$(find "$fixture_home" -type f -exec sha256sum {} + | sort)
diagnose >"$scratch/output"
grep -Fxq 'phr-maintenance identity=exact roots=canonical maintenance_file=present lock=absent transactions=0 recovery_cron=present' "$scratch/output"
grep -Fxq 'runtime-audit identity=exact paths=canonical writable=yes database=persistent phase=selected' "$scratch/output"
grep -Fxq 'phr-http endpoint=login status=503' "$scratch/output"
grep -Fxq 'phr-web-runtime validated=yes cleanup=confirmed' "$scratch/output"
[[ $(find "$fixture_home" -type f -exec sha256sum {} + | sort) == "$before" ]]
[[ -f "$shared/storage/framework/down" ]]
mkdir "$control/deploy.lock"
: >"$FIXTURE_CURL_LOG"
reject
[[ ! -s "$FIXTURE_CURL_LOG" ]]
rmdir "$control/deploy.lock"
mkdir "$control/state/interrupted"
reject
rmdir "$control/state/interrupted"
DIAG_EXPECTED_COMMIT=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb reject
DIAG_EXPECTED_RELEASE=other-release reject
NOISY_DIAG_BOOTSTRAP=1 reject
FLOOD_DIAG_SSH=1 reject
RACE_DIAG_WRITER=1 reject
rmdir "$control/deploy.lock"
mv "$control/state" "$scratch/state"
ln -s "$scratch/state" "$control/state"
reject
rm "$control/state"
mv "$scratch/state" "$control/state"
mv "$control/recovery/$DIAG_EXPECTED_RELEASE.cron" "$scratch/cron"
ln -s "$scratch/cron" "$control/recovery/$DIAG_EXPECTED_RELEASE.cron"
reject
rm "$control/recovery/$DIAG_EXPECTED_RELEASE.cron"
mv "$scratch/cron" "$control/recovery/$DIAG_EXPECTED_RELEASE.cron"
mv "$stable/bootstrap/cache/config.php" "$scratch/config.php"
reject
mv "$scratch/config.php" "$stable/bootstrap/cache/config.php"
cp "$stable/bootstrap/cache/config.php" "$scratch/config.php"
printf '<?php file_put_contents("%s/PRIVATE_CONFIG_EXECUTED", "PRIVATE_CONFIG_SECRET"); return [];' "$stable" >"$stable/bootstrap/cache/config.php"
reject
[[ ! -e "$stable/PRIVATE_CONFIG_EXECUTED" ]]
mv "$scratch/config.php" "$stable/bootstrap/cache/config.php"
BAD_DIAG_WEB=1 reject
if FAIL_DIAG_CLEANUP=1 diagnose >"$scratch/output" 2>&1; then echo 'failed cleanup was accepted' >&2; exit 1; fi
grep -Fxq 'phr-web-runtime validated=no cleanup=unconfirmed' "$scratch/output"
[[ -n $(find "$stable/public" -maxdepth 1 -name '_deploy-php-check-*.php' -print) ]]
# Remove the synthetic fixture's deliberately retained probe, never production files.
find "$stable/public" -maxdepth 1 -name '_deploy-php-check-*.php' -delete
[[ $(find "$fixture_home" -type f -exec sha256sum {} + | sort) == "$before" ]]
echo 'Read-only PHR maintenance diagnostics, active writer refusal, redaction and probe cleanup passed.'
