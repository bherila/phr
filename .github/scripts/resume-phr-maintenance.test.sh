#!/usr/bin/env bash
# Actual PHP, shared audit/web/cron helpers, synthetic Laravel and SSH/HTTP/crontab.
set -euo pipefail
scripts=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
[[ -n ${PHR_SHARED_ACTION_DIR:-} ]] || exit 2
scratch=$(mktemp -d)
trap 'rm -rf -- "$scratch"' EXIT
export RESUME_SSH_TARGET=fixture-host RESUME_EXPECTED_RELEASE=aaaaaaaaaaaa-123456-1 \
    RESUME_EXPECTED_COMMIT=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa GITHUB_REPOSITORY=bherila/phr
RESUME_PHP_BINARY=$(command -v php)
export RESUME_PHP_BINARY FIXTURE_HOME="$scratch/home" FIXTURE_CRON="$scratch/cron" FIXTURE_CALLS="$scratch/calls" FIXTURE_TIMEOUT_PID="$scratch/timeout-pid"
mkdir "$scratch/bin"
php /dev/stdin "$scratch" <<'PHP'
<?php
$key = openssl_pkey_new(['private_key_bits'=>2048, 'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $pem);
file_put_contents($argv[1].'/private.key', $pem);
file_put_contents($argv[1].'/public.key', openssl_pkey_get_details($key)['key']);
PHP
cat >"$scratch/bin/gh" <<'GH'
#!/usr/bin/env bash
set -euo pipefail
[[ $1 == api ]] || exit 2
if [[ $2 == *'/jobs?'* ]]; then
    printf '{"total_count":1,"jobs":[{"name":"Deploy to Production","run_id":123456,"status":"completed","conclusion":"%s","started_at":"2026-10-04T22:19:50Z","completed_at":"2026-10-04T22:20:43Z"}]}' "${FIXTURE_DEPLOY_CONCLUSION:-failure}"
else
    printf '{"id":123456,"run_attempt":1,"status":"%s","conclusion":"%s","head_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","head_branch":"main","path":".github/workflows/ci.yml","event":"push","repository":{"full_name":"bherila/phr"}}' "${FIXTURE_RUN_STATUS:-completed}" "${FIXTURE_RUN_CONCLUSION:-failure}"
fi
GH
cat >"$scratch/bin/ssh" <<'SSH'
#!/usr/bin/env bash
set -euo pipefail
while [[ ${1:-} == -o ]]; do shift 2; done
[[ $1 == fixture-host ]] || exit 2
shift
printf 'ssh\n' >>"$FIXTURE_CALLS"
env HOME="$FIXTURE_HOME" bash -c "$1"
SSH
cat >"$scratch/bin/curl" <<'CURL'
#!/usr/bin/env bash
set -euo pipefail
output=''
for ((number=1; number<=$#; number++)); do
    if [[ ${!number} == --output ]]; then next=$((number + 1)); output=${!next}; fi
done
case ${!#} in
    https://phr.bherila.net/up) printf '200' ;;
    https://phr.bherila.net/login)
        if [[ -f $FIXTURE_HOME/phr-laravel/storage/framework/down ]]; then printf 503
        elif [[ ${FIXTURE_HTTP_FAIL:-} == 1 ]]; then printf 500
        else printf 302; fi ;;
    https://phr.bherila.net/_deploy-php-check-*.php)
        if [[ ${FIXTURE_WEB_FAIL:-} == 1 && -d $FIXTURE_HOME/.deployments/phr-laravel/deploy.lock ]]; then
            printf '<!DOCTYPE html>PRIVATE_RESPONSE_SECRET' >"$output"; printf '200|text/html'
        else printf '8.5|1024M|litespeed' >"$output"; printf '200|text/plain'; fi ;;
    *) exit 2 ;;
esac
CURL
cat >"$scratch/bin/crontab" <<'CRON'
#!/usr/bin/env bash
set -euo pipefail
if [[ $1 == -l ]]; then
    if [[ ${FIXTURE_CRON_MISSING:-} == 1 && ! -s $FIXTURE_CRON ]]; then echo 'no crontab for fixture-user' >&2; exit 1; fi
    if [[ ${FIXTURE_CRON_UNKNOWN:-} == 1 ]]; then printf 'permission denied\nPRIVATE_CRON_READ_SECRET' >&2; exit 1; fi
    cat "$FIXTURE_CRON"; exit
fi
cp -- "$1" "$FIXTURE_CRON"
printf 'cron-write\n' >>"$FIXTURE_CALLS"
if [[ ${FIXTURE_CRON_FAIL:-} == 1 ]] && grep -q '# JOB:phr-laravel-scheduler$' "$FIXTURE_CRON"; then
    printf 'PRIVATE_CRON_SECRET' >&2; exit 1
fi
CRON
cat >"$scratch/bin/rmdir" <<'RMDIR'
#!/usr/bin/env bash
set -euo pipefail
if [[ ${FIXTURE_RELEASE_FAIL:-} == 1 && ${!#} == */deploy.lock ]]; then exit 1; fi
exec /usr/bin/rmdir "$@"
RMDIR
chmod +x "$scratch/bin/"*
export PATH="$scratch/bin:$PATH"
reset_fixture() {
    rm -rf -- "$FIXTURE_HOME"
    control="$FIXTURE_HOME/.deployments/phr-laravel"
    shared="$control/shared"
    stable="$FIXTURE_HOME/phr-laravel"
    mkdir -p "$control"/{state,releases,recovery} "$shared/storage"/{framework/views,framework/sessions,framework/cache/data,logs,app/private/oauth} \
        "$shared/public/ohif" "$stable"/{vendor,bootstrap/cache,resources/views,public}
    ln -s "$shared/storage" "$stable/storage"
    ln -s "$shared/public/ohif" "$stable/public/ohif"
    touch "$shared/storage/app/database.sqlite" "$shared/storage/framework/down"
    touch -d @1791152420 "$shared/storage/framework/down"
    cp "$scratch/private.key" "$shared/storage/app/private/oauth/oauth-private.key"
    cp "$scratch/public.key" "$shared/storage/app/private/oauth/oauth-public.key"
    printf 'release=%s\ncommit=%s\n' "$RESUME_EXPECTED_RELEASE" "$RESUME_EXPECTED_COMMIT" >"$stable/.deploy-release"
    printf 'PRIVATE_SAVED_RECOVERY_CRON\n' >"$control/recovery/$RESUME_EXPECTED_RELEASE.cron"
    # shellcheck disable=SC2016 # Cron expands the literal home on the host.
    printf '# unrelated account entries\n*/7 * * * * cd "$HOME/other-app" && true # JOB:phr-laravel-two-scheduler\n' >"$FIXTURE_CRON"
    : >"$FIXTURE_CALLS"
    rm -f -- "$FIXTURE_TIMEOUT_PID"
    cat >"$stable/vendor/autoload.php" <<'PHP'
<?php
namespace Illuminate\Foundation {
class AliasLoader {
    protected $aliases = [];
    protected static $facadeNamespace = 'Facades\\';
    private static $instance;
    public static function getInstance() { return self::$instance ??= new self; }
    public static function setInstance($instance) { self::$instance = $instance; }
    public function getAliases() { return []; }
    protected function ensureFacadeExists($alias) { return ''; }
}}
namespace Illuminate\Contracts\Console { interface Kernel {} }
namespace Illuminate\Encryption { class Encrypter { public static function supported($key,$cipher) { return strlen($key) === 32 && $cipher === 'AES-256-CBC'; } } }
PHP
    cat >"$stable/bootstrap/app.php" <<'PHP'
<?php
function config($key) {
    return match ($key) {
        'queue.default'=>'sync', 'queue.connections.sync'=>['driver'=>'sync'],
        'queue.failed'=>['driver'=>null], 'database.default'=>'sqlite',
    };
}
class Fixture implements ArrayAccess {
    public function getCachedConfigPath() { return getcwd().'/bootstrap/cache/config.php'; }
    public function make($key) { return $this; }
    public function bootstrap() {
        if (getenv('FIXTURE_REPLACED_MARKER') && is_dir(getenv('HOME').'/.deployments/phr-laravel/deploy.lock')) {
            file_put_contents(getcwd().'/storage/framework/down', 'PRIVATE_OPERATOR_MARKER');
            touch(getcwd().'/storage/framework/down', 1791152420);
        }
    }
    public function paths() { return []; }
    public function databasePath($path) { return '/nonexistent/'.$path; }
    public function getMigrationFiles($paths) {
        return getenv('FIXTURE_SCHEMA_FAIL') && is_dir(getenv('HOME').'/.deployments/phr-laravel/deploy.lock') ? ['pending'=>'/nonexistent/pending.php'] : [];
    }
    public function getRepository() { return $this; }
    public function repositoryExists() { return true; }
    public function getRan() { return []; }
    public function resolveConnection($name) { return $this; }
    public function getConfig() { return ['driver'=>'sqlite','database'=>'storage/app/database.sqlite']; }
    public function getName() { return 'sqlite'; }
    public function isDownForMaintenance() { return file_exists(getcwd().'/storage/framework/down'); }
    public function get($key, $default=null) {
        return match ($key) { 'app.key'=>'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'app.cipher'=>'AES-256-CBC', default=>$default };
    }
    public function offsetGet($key): mixed { return $this; }
    public function offsetSet($key,$value): void {}
    public function offsetExists($key): bool { return true; }
    public function offsetUnset($key): void {}
    public function call($mode,$args) {
        if ($mode === 'phr:agent-api:verify-oauth-keys') {
            $private = openssl_pkey_get_private(file_get_contents(getcwd().'/storage/app/private/oauth/oauth-private.key'));
            $public = openssl_pkey_get_public(file_get_contents(getcwd().'/storage/app/private/oauth/oauth-public.key'));
            return $private && $public && openssl_pkey_get_details($private)['key'] === openssl_pkey_get_details($public)['key'] ? 0 : 1;
        }
        file_put_contents(getenv('FIXTURE_CALLS'), $mode."\n", FILE_APPEND);
        if ($mode === 'up') {
            unlink(getcwd().'/storage/framework/down');
            if (getenv('FIXTURE_HANG_UP')) {
                file_put_contents(getenv('FIXTURE_TIMEOUT_PID'), (string) getmypid());
                pcntl_async_signals(true);
                pcntl_signal(SIGTERM, SIG_IGN);
                while (true) { usleep(100000); }
            }
            if (getenv('FIXTURE_FOREIGN_OWNER')) {
                file_put_contents(getenv('HOME').'/.deployments/phr-laravel/deploy.lock/owner', "foreign-owner\n");
            }
            if (getenv('FIXTURE_IDENTITY_FAIL')) {
                file_put_contents(getcwd().'/.deploy-release', "release=other\ncommit=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\n");
            }
            if (getenv('FIXTURE_NOISY_UP')) {
                register_shutdown_function(static function (): void { fwrite(STDOUT, "\0PRIVATE_SHUTDOWN_SECRET"); });
            }
            if (getenv('FIXTURE_UP_FAIL')) { return 1; }
        } else {
            if (is_file(getenv('FIXTURE_TIMEOUT_PID')) && posix_kill((int) file_get_contents(getenv('FIXTURE_TIMEOUT_PID')), 0)) {
                file_put_contents(getenv('FIXTURE_CALLS'), "unsafe-live-up-process\n", FILE_APPEND);
                return 1;
            }
            if (getenv('FIXTURE_DOWN_FAIL')) { return 1; }
            file_put_contents(getcwd().'/storage/framework/down', 'synthetic down');
        }
        return 0;
    }
}
return new Fixture;
PHP
    "$RESUME_PHP_BINARY" /dev/stdin "$stable" >"$stable/bootstrap/cache/config.php" <<'PHP'
<?php
$stable = $argv[1];
$config = [
    'app'=>['maintenance'=>['driver'=>'file']],
    'view'=>['paths'=>[$stable.'/resources/views'],'compiled'=>$stable.'/storage/framework/views'],
    'session'=>['files'=>$stable.'/storage/framework/sessions'],
    'cache'=>['default'=>'file','stores'=>['file'=>['driver'=>'file','path'=>$stable.'/storage/framework/cache/data','lock_path'=>null]]],
    'filesystems'=>['default'=>'local','disks'=>['local'=>['driver'=>'local','root'=>$stable.'/storage/app/private']]],
    'logging'=>['channels'=>['single'=>['driver'=>'single','path'=>$stable.'/storage/logs/laravel.log']]],
];
echo '<?php return '.var_export($config,true).';';
PHP
    recovery_before=$(sha256sum "$control/recovery/$RESUME_EXPECTED_RELEASE.cron")
    config_before=$(sha256sum "$stable/bootstrap/cache/config.php")
    cp "$FIXTURE_CRON" "$scratch/foreign-cron"
}
run_resume() { bash "$scripts/resume-phr-maintenance.sh" >"$scratch/output" 2>&1; }
assert_private() {
    if grep -aEq 'PRIVATE_|other-app|synthetic down|foreign-owner' "$scratch/output"; then cat "$scratch/output" >&2; exit 1; fi
    [[ $(sha256sum "$control/recovery/$RESUME_EXPECTED_RELEASE.cron") == "$recovery_before" ]]
    [[ $(sha256sum "$stable/bootstrap/cache/config.php") == "$config_before" ]]
    [[ -z $(find "$stable/public" -maxdepth 1 -name '_deploy-php-check-*.php' -print) ]]
}
reject() { if run_resume; then echo 'Unexpected resume pass.' >&2; exit 1; fi; assert_private; }
reset_fixture
run_resume || { cat "$scratch/output" >&2; exit 1; }
assert_private
[[ ! -e $shared/storage/framework/down && ! -e $control/deploy.lock ]]
[[ $(grep -c '# JOB:phr-laravel-scheduler$' "$FIXTURE_CRON") == 1 ]]
[[ $(grep -c '# JOB:phr-laravel-queue-worker$' "$FIXTURE_CRON") == 1 ]]
head -n 2 "$FIXTURE_CRON" | cmp -s - "$scratch/foreign-cron"
grep -Fq 'result=serving' "$scratch/output"
reset_fixture
: >"$FIXTURE_CRON"
FIXTURE_CRON_MISSING=1 run_resume
assert_private
[[ ! -f $shared/storage/framework/down && ! -e $control/deploy.lock ]]
[[ $(wc -l <"$FIXTURE_CRON") == 2 ]]
reset_fixture
FIXTURE_CRON_UNKNOWN=1 reject
[[ -f $shared/storage/framework/down && ! -e $control/deploy.lock ]]
if grep -q '^up$' "$FIXTURE_CALLS"; then exit 1; fi
cmp -s "$FIXTURE_CRON" "$scratch/foreign-cron"
reset_fixture
FIXTURE_RUN_STATUS=in_progress reject
[[ ! -s $FIXTURE_CALLS ]]
FIXTURE_RUN_CONCLUSION=cancelled reject
[[ ! -s $FIXTURE_CALLS ]]
FIXTURE_DEPLOY_CONCLUSION=success reject
[[ ! -s $FIXTURE_CALLS ]]
touch -d @1791156000 "$shared/storage/framework/down"
reject
[[ ! -e $control/deploy.lock ]]
touch -d @1791152420 "$shared/storage/framework/down"
mkdir "$control/deploy.lock"
reject
[[ -f $shared/storage/framework/down ]]
if grep -q '^up$' "$FIXTURE_CALLS"; then exit 1; fi
rmdir "$control/deploy.lock"
mkdir "$control/state/interrupted"
reject
rmdir "$control/state/interrupted"
printf 'release=wrong\ncommit=%s\n' "$RESUME_EXPECTED_COMMIT" >"$stable/.deploy-release"
reject
for setting in FIXTURE_SCHEMA_FAIL FIXTURE_WEB_FAIL; do
    reset_fixture
    export "$setting=1"
    reject
    unset "$setting"
    [[ -f $shared/storage/framework/down && ! -e $control/deploy.lock ]]
    if grep -q '^up$' "$FIXTURE_CALLS"; then exit 1; fi
    cmp -s "$FIXTURE_CRON" "$scratch/foreign-cron"
done
reset_fixture
FIXTURE_REPLACED_MARKER=1 reject
[[ -d $control/deploy.lock && -f $shared/storage/framework/down ]]
if grep -Eq '^(up|down)$' "$FIXTURE_CALLS"; then exit 1; fi
reset_fixture
rm "$shared/storage/app/private/oauth/oauth-private.key"
reject
[[ ! -e $control/deploy.lock ]]
if grep -q '^up$' "$FIXTURE_CALLS"; then exit 1; fi
reset_fixture
printf 'PRIVATE_INVALID_OAUTH_KEY' >"$shared/storage/app/private/oauth/oauth-public.key"
reject
[[ ! -e $control/deploy.lock ]]
if grep -q '^up$' "$FIXTURE_CALLS"; then exit 1; fi
for setting in FIXTURE_UP_FAIL FIXTURE_NOISY_UP FIXTURE_HTTP_FAIL FIXTURE_CRON_FAIL; do
    reset_fixture
    export "$setting=1"
    reject
    unset "$setting"
    [[ -f $shared/storage/framework/down && ! -e $control/deploy.lock ]]
    grep -q '^down$' "$FIXTURE_CALLS"
    cmp -s "$FIXTURE_CRON" "$scratch/foreign-cron"
    grep -Fq 'rollback=maintenance cron=paused lock=released' "$scratch/output"
done
reset_fixture
FIXTURE_UP_FAIL=1 FIXTURE_DOWN_FAIL=1 reject
[[ -d $control/deploy.lock ]]
grep -Fq 'rollback=unconfirmed cron=unconfirmed lock=retained' "$scratch/output"
reset_fixture
FIXTURE_FOREIGN_OWNER=1 reject
[[ $(cat "$control/deploy.lock/owner") == foreign-owner ]]
if grep -q '^down$' "$FIXTURE_CALLS"; then exit 1; fi
reset_fixture
FIXTURE_IDENTITY_FAIL=1 reject
[[ -d $control/deploy.lock ]]
if grep -q '^down$' "$FIXTURE_CALLS"; then exit 1; fi
reset_fixture
FIXTURE_RELEASE_FAIL=1 reject
[[ -d $control/deploy.lock && -f $shared/storage/framework/down ]]
grep -Fq 'rollback=maintenance cron=paused lock=retained' "$scratch/output"
reset_fixture
FIXTURE_HANG_UP=1 reject
[[ -f $shared/storage/framework/down && ! -e $control/deploy.lock ]]
grep -q '^down$' "$FIXTURE_CALLS"
if grep -q '^unsafe-live-up-process$' "$FIXTURE_CALLS"; then exit 1; fi
grep -Fq 'rollback=maintenance cron=paused lock=released' "$scratch/output"
echo 'Guarded PHR resume: exact CI/identity, busy refusal, schema/web gates, rollback, redaction and foreign cron preservation passed.'
