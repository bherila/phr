<?php

namespace Tests\Unit;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PilotDeploymentTest extends TestCase
{
    private const PILOT_ENV = "PILOT_DEPLOYMENT=1\nAPP_DEBUG=false\nMAIL_MAILER=log\nDB_CONNECTION=mariadb\nDB_HOST=127.0.0.1\n";

    /** Selector keys whose discovery the tests pin, so a regex regression cannot hide one. */
    private const KNOWN_CONNECTION_SELECTORS = ['DB_QUEUE_CONNECTION', 'DB_CACHE_CONNECTION', 'DB_CACHE_LOCK_CONNECTION',
        'SESSION_CONNECTION', 'PASSPORT_CONNECTION', 'PHR_DELEGATED_ACCESS_NONCE_CONNECTION',
        'PHR_DELEGATED_ACCESS_RECEIPT_CONNECTION', 'BHERILA_AUTH_IDENTITY_TOMBSTONE_CONNECTION'];

    /** Keys config/database.php reads that cannot move the pinned mariadb connection off the local server. */
    private const NON_DESTINATION_KEYS = ['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_CHARSET', 'DB_COLLATION', 'DB_FOREIGN_KEYS',
        'DB_SSLMODE', 'DB_ENCRYPT', 'DB_TRUST_SERVER_CERTIFICATE', 'MYSQL_ATTR_SSL_CA', 'APP_NAME', 'REDIS_CLIENT', 'REDIS_CLUSTER', 'REDIS_PREFIX', 'REDIS_PERSISTENT',
        'REDIS_USERNAME', 'REDIS_PASSWORD', 'REDIS_DB', 'REDIS_CACHE_DB', 'REDIS_MAX_RETRIES', 'REDIS_BACKOFF_ALGORITHM',
        'REDIS_BACKOFF_BASE', 'REDIS_BACKOFF_CAP'];

    private string $root;

    private string $temporary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        $this->temporary = sys_get_temp_dir().'/pilot-deploy-'.bin2hex(random_bytes(8));
        mkdir($this->temporary, 0700);
        $this->temporary = realpath($this->temporary);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', '--', $this->temporary]))->mustRun();
        parent::tearDown();
    }

    /** @param array<string, mixed> $fixture */
    private function gate(array $fixture = [], bool $identity = false): Process
    {
        $source = <<<'JS'
import {pathToFileURL} from 'node:url';
const modulePath = process.argv[1]; process.argv[1] = 'fixture-runner';
const gate = await import(pathToFileURL(modulePath));
const fixture = JSON.parse(process.argv[2]);
const env = {GITHUB_REPOSITORY:'example/application', GITHUB_SHA:'a'.repeat(40), GITHUB_REF:'refs/heads/main', GITHUB_EVENT_NAME:'workflow_dispatch', PILOT_DEPLOY:'true', PILOT_OPERATOR_SHA:'a'.repeat(40), PILOT_IDENTITY_REPOSITORY:'example/identity', PILOT_APPLICATION_SHA:'b'.repeat(40), ...fixture.env};
const identity = process.argv[3] === 'identity';
const sha = identity ? env.PILOT_APPLICATION_SHA : env.PILOT_OPERATOR_SHA;
const repository = identity ? env.PILOT_IDENTITY_REPOSITORY : env.GITHUB_REPOSITORY;
const get = async (path) => {
    if (path.endsWith('/git/ref/heads/main')) return {object:{sha: fixture.main ?? env.PILOT_OPERATOR_SHA}};
    if (path.includes('/runs?')) return {total_count:fixture.runCount ?? 1, workflow_runs:[{id:42, head_sha:sha, head_branch:'main', event:'push', status:'completed', conclusion:'failure', head_repository:{full_name:repository}, ...fixture.run}]};
    return {total_count:fixture.jobCount ?? 1, jobs:fixture.jobs ?? [{id:21, name:identity ? 'ci' : (env.PILOT_CI_GATE ?? 'Run Tests'), status:'completed', conclusion:'success', head_sha:sha}]};
};
try {await (identity ? gate.verifyIdentity : gate.verifyOperator)(env, get); process.stdout.write('verified');}
catch (error) {process.stderr.write(error.message); process.exitCode = 1;}
JS;
        $process = new Process(['node', '--input-type=module', '-e', $source,
            $this->root.'/scripts/deploy/pilot/gate.mjs', json_encode($fixture, JSON_THROW_ON_ERROR),
            $identity ? 'identity' : 'operator']);
        $process->run();

        return $process;
    }

    public function test_exact_main_aggregate_success_does_not_depend_on_a_production_deployment(): void
    {
        $result = $this->gate();
        $this->assertTrue($result->isSuccessful(), $result->getErrorOutput());
        $this->assertTrue($this->gate([], true)->isSuccessful());
        $this->assertTrue($this->gate(['run' => ['status' => 'in_progress']])->isSuccessful());
        $this->assertTrue($this->gate(['run' => ['status' => 'waiting']])->isSuccessful());
        $this->assertTrue($this->gate(['env' => ['PILOT_CI_WORKFLOW' => 'tests.yml', 'PILOT_CI_GATE' => 'ci']])->isSuccessful());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function rejectedRequests(): array
    {
        return [
            'unconfirmed' => [['env' => ['PILOT_DEPLOY' => 'false']]],
            'pull request trigger' => [['env' => ['GITHUB_EVENT_NAME' => 'pull_request']]],
            'branch dispatch' => [['env' => ['GITHUB_REF' => 'refs/heads/feature']]],
            'wrong dispatch SHA' => [['env' => ['GITHUB_SHA' => str_repeat('c', 40)]]],
            'short SHA' => [['env' => ['PILOT_OPERATOR_SHA' => 'aaaaaaa']]],
            'main moved' => [['main' => str_repeat('c', 40)]],
            'wrong source event' => [['run' => ['event' => 'pull_request']]],
            'wrong source branch' => [['run' => ['head_branch' => 'feature']]],
            'fork source' => [['run' => ['head_repository' => ['full_name' => 'another/fork']]]],
            'wrong source SHA' => [['run' => ['head_sha' => str_repeat('c', 40)]]],
            'unbounded runs' => [['runCount' => 101]],
            'incomplete runs' => [['runCount' => 2]],
            'unbounded jobs' => [['jobCount' => 501]],
            'incomplete jobs' => [['jobCount' => 2]],
            'missing aggregate' => [['jobs' => [['id' => 21, 'name' => 'Deploy to Production', 'status' => 'completed', 'conclusion' => 'success', 'head_sha' => str_repeat('a', 40)]]]],
            'failed aggregate' => [['jobs' => [['id' => 21, 'name' => 'Run Tests', 'status' => 'completed', 'conclusion' => 'failure', 'head_sha' => str_repeat('a', 40)]]]],
            'wrong aggregate SHA' => [['jobs' => [['id' => 21, 'name' => 'Run Tests', 'status' => 'completed', 'conclusion' => 'success', 'head_sha' => str_repeat('b', 40)]]]],
        ];
    }

    #[DataProvider('rejectedRequests')]
    public function test_untrusted_or_unverified_requests_fail_before_deployment(array $fixture): void
    {
        $this->assertFalse($this->gate($fixture)->isSuccessful());
    }

    private function scaffold(): string
    {
        $home = $this->temporary.'/home';
        foreach (['.config/sample', '.deployments/sample-app/shared/storage', 'sample-app/public', 'sample-app/bootstrap/cache'] as $path) {
            mkdir($home.'/'.$path, 0700, true);
        }
        $env = self::PILOT_ENV;
        file_put_contents($home.'/.config/sample/deployment.env', $env);
        file_put_contents($home.'/sample-app/.env', $env);
        symlink($home.'/.deployments/sample-app/shared/storage', $home.'/sample-app/storage');

        return $home;
    }

    private function prepare(string $home): Process
    {
        $process = new Process(['bash', $this->root.'/scripts/deploy/pilot/prepare-host.sh',
            'sample-app', '.config/sample/deployment.env', PHP_BINARY], null, ['HOME' => $home]);
        $process->run();

        return $process;
    }

    public function test_first_install_preserves_only_the_known_scaffold_without_deleting_runtime_data(): void
    {
        $home = $this->scaffold();
        file_put_contents($home.'/.deployments/sample-app/shared/storage/preserved.txt', 'retained');
        $result = $this->prepare($home);
        $this->assertTrue($result->isSuccessful(), $result->getErrorOutput());
        $this->assertDirectoryDoesNotExist($home.'/sample-app');
        $this->assertFileExists($home.'/.deployments/sample-app/prepared-root/.env');
        $this->assertSame('retained', file_get_contents($home.'/.deployments/sample-app/shared/storage/preserved.txt'));
    }

    public function test_first_install_refuses_unknown_files_and_keeps_the_entire_root_in_place(): void
    {
        $home = $this->scaffold();
        file_put_contents($home.'/sample-app/public/unexpected.txt', 'preserve me');
        $this->assertFalse($this->prepare($home)->isSuccessful());
        $this->assertSame('preserve me', file_get_contents($home.'/sample-app/public/unexpected.txt'));
        $this->assertDirectoryDoesNotExist($home.'/.deployments/sample-app/prepared-root');
    }

    /** @return list<string> */
    private static function otherConnections(): array
    {
        preg_match_all("/^        '([a-z]+)' => \[\R            'driver' =>/m", (string) file_get_contents(dirname(__DIR__, 2).'/config/database.php'), $matches);

        return array_values(array_diff($matches[1], ['mariadb']));
    }

    /**
     * Every environment key feeding a database-connection selector, in the published configuration
     * or in a package default that providers merge at registration. REDIS_* keys name Redis
     * connections, which are checked by destination instead.
     *
     * @return list<string>
     */
    private static function connectionSelectorKeys(): array
    {
        $root = dirname(__DIR__, 2);
        $pattern = "/'([a-z_]*connection|database)' => env\('([A-Z0-9_]+)'(?:, env\('([A-Z0-9_]+)'\))?/";
        $keys = [];
        $published = [];
        foreach (glob($root.'/config/*.php') as $file) {
            $published[basename($file)] = (string) file_get_contents($file);
            preg_match_all($pattern, $published[basename($file)], $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                array_push($keys, ...array_slice($match, 2));
            }
        }
        foreach (glob($root.'/vendor/*/*/config/*.php') as $file) {
            preg_match_all($pattern, (string) file_get_contents($file), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                // A setting the published file also defines replaces the package default.
                if (! str_contains($published[basename($file)] ?? '', "'{$match[1]}' =>")) {
                    array_push($keys, ...array_slice($match, 2));
                }
            }
        }
        $keys = array_filter(array_unique($keys), fn (string $key): bool => $key !== ''
            && ! str_starts_with($key, 'REDIS_') && ! in_array($key, ['DB_CONNECTION', 'DB_DATABASE'], true));
        sort($keys);

        return array_values($keys);
    }

    /**
     * Every setting that can move the effective database destination off the local server.
     * A leading "-" removes the key instead of assigning it.
     *
     * @return array<string, array{string}>
     */
    public static function databaseOverrides(): array
    {
        $cases = [
            'remote URL overrides localhost' => ['DB_URL=mysql://synthetic:synthetic@database.example.test/app'],
            'local URL overrides isolated credentials' => ['DB_URL=mysql://synthetic:synthetic@127.0.0.1/another_app'],
            'socket bypasses the host' => ['DB_SOCKET=/tmp/synthetic-mysql.sock'],
            'remote host' => ['DB_HOST=database.example.test'],
            'forwarded local port' => ['DB_PORT=13306'],
            'missing connection falls back to sqlite' => ['-DB_CONNECTION'],
            'empty connection' => ['DB_CONNECTION='],
            'remote redis URL' => ['REDIS_URL=redis://cache.example.test:6379'],
            'remote redis host' => ['REDIS_HOST=cache.example.test'],
            'forwarded redis port' => ['REDIS_PORT=16379'],
            'other redis address' => ['REDIS_HOST=192.0.2.10'],
            'relative redis socket' => ["REDIS_HOST=run/redis-sample/redis.sock\nREDIS_PORT=0"],
            'redis socket path escapes with dot-dot' => ["REDIS_HOST=/run/../tmp/redis.sock\nREDIS_PORT=0"],
            'redis socket with a TCP port' => ["REDIS_HOST=/run/redis-sample/redis.sock\nREDIS_PORT=6379"],
            'redis socket with the default port' => ['REDIS_HOST=/run/redis-sample/redis.sock'],
        ];
        foreach (self::otherConnections() as $connection) {
            $cases["default connection {$connection}"] = ["DB_CONNECTION={$connection}"];
        }
        foreach (self::connectionSelectorKeys() as $selector) {
            $cases["{$selector} selects sqlite"] = ["{$selector}=sqlite"];
            $cases["{$selector} selects sqlite for writes"] = ["{$selector}=sqlite::write"];
        }
        // Laravel splits a suffixed name at its first "::", so only the leading segment names the connection.
        $cases['selector picks sqlite for reads'] = ['PHR_DELEGATED_ACCESS_RECEIPT_CONNECTION=sqlite::read'];
        $cases['selector hides sqlite behind a nested suffix'] = ['PHR_DELEGATED_ACCESS_RECEIPT_CONNECTION=sqlite::other::write'];

        return $cases;
    }

    /** Apply one assignment per line; a line starting with "-" removes that key instead. */
    private static function override(string $env, string $override): string
    {
        foreach (explode("\n", $override) as $line) {
            $key = ltrim(explode('=', $line, 2)[0], '-');
            $env = (string) preg_replace('/^'.preg_quote($key, '/').'=.*\R/m', '', $env);
            $env = str_starts_with($line, '-') ? $env : $env.$line."\n";
        }

        return $env;
    }

    #[DataProvider('databaseOverrides')]
    public function test_private_target_settings_cannot_move_the_database_off_the_local_server(string $override): void
    {
        $home = $this->scaffold();
        file_put_contents($home.'/.config/sample/deployment.env', self::override(self::PILOT_ENV, $override));
        $this->assertFalse($this->prepare($home)->isSuccessful());
        $this->assertDirectoryExists($home.'/sample-app');
    }

    #[DataProvider('databaseOverrides')]
    public function test_resolved_candidate_configuration_refuses_each_database_override(string $override): void
    {
        $result = $this->assertLocalDatabase(self::override(self::PILOT_ENV, $override));
        $this->assertFalse($result->isSuccessful(), $result->getOutput());
        $this->assertStringContainsString('refusing to migrate', $result->getErrorOutput());
        $this->assertStringNotContainsString('example.test', $result->getOutput().$result->getErrorOutput());
    }

    /** @return array<string, array{string}> */
    public static function processEnvironmentOverrides(): array
    {
        return array_filter(self::databaseOverrides(), fn (array $case): bool => ! str_starts_with($case[0], '-'));
    }

    #[DataProvider('processEnvironmentOverrides')]
    public function test_process_environment_cannot_override_the_resolved_candidate_database(string $override): void
    {
        $processEnv = [];
        foreach (explode("\n", $override) as $line) {
            [$key, $value] = explode('=', $line, 2);
            $processEnv[$key] = $value;
        }
        $result = $this->assertLocalDatabase(self::PILOT_ENV, $processEnv);
        $this->assertFalse($result->isSuccessful(), $result->getOutput());
        $this->assertStringContainsString('refusing to migrate', $result->getErrorOutput());
    }

    public function test_local_pilot_configuration_is_accepted_by_every_database_guard(): void
    {
        $env = self::PILOT_ENV."DB_URL=\nDB_SOCKET=\nDB_PORT=3306\nREDIS_HOST=127.0.0.1\nDB_DATABASE=synthetic_pilot\n";
        foreach (self::connectionSelectorKeys() as $selector) {
            $env .= "{$selector}=mariadb\n";
        }
        $home = $this->scaffold();
        file_put_contents($home.'/.config/sample/deployment.env', $env);
        $prepare = $this->prepare($home);
        $this->assertTrue($prepare->isSuccessful(), $prepare->getErrorOutput());
        foreach ([self::PILOT_ENV, $env] as $candidate) {
            $result = $this->assertLocalDatabase($candidate);
            $this->assertTrue($result->isSuccessful(), $result->getErrorOutput());
        }
    }

    public function test_read_write_suffix_on_the_pilot_connection_is_still_accepted(): void
    {
        $env = self::PILOT_ENV."PHR_DELEGATED_ACCESS_RECEIPT_CONNECTION=mariadb::write\nPHR_DELEGATED_ACCESS_NONCE_CONNECTION=mariadb::read\n";
        $home = $this->scaffold();
        file_put_contents($home.'/.config/sample/deployment.env', $env);
        $prepare = $this->prepare($home);
        $this->assertTrue($prepare->isSuccessful(), $prepare->getErrorOutput());
        $result = $this->assertLocalDatabase($env);
        $this->assertTrue($result->isSuccessful(), $result->getErrorOutput());
    }

    public function test_pilot_shape_with_a_per_application_redis_socket_is_accepted(): void
    {
        $redis = ['REDIS_CLIENT' => 'phpredis', 'REDIS_HOST' => '/run/redis-phr/redis.sock', 'REDIS_PORT' => '0'];
        $env = self::PILOT_ENV."DB_PORT=3306\n";
        foreach ($redis as $key => $value) {
            $env .= "{$key}={$value}\n";
        }
        $home = $this->scaffold();
        file_put_contents($home.'/.config/sample/deployment.env', $env);
        $prepare = $this->prepare($home);
        $this->assertTrue($prepare->isSuccessful(), $prepare->getErrorOutput());
        $installed = $this->assertLocalDatabase($env);
        $this->assertTrue($installed->isSuccessful(), $installed->getErrorOutput());
        $process = $this->assertLocalDatabase(self::PILOT_ENV, $redis);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    public function test_database_override_cases_enumerate_every_destination_setting(): void
    {
        $covered = [];
        foreach (self::databaseOverrides() as [$override]) {
            foreach (explode("\n", $override) as $line) {
                $covered[] = ltrim(explode('=', $line, 2)[0], '-');
            }
        }
        preg_match_all("/env\('([A-Z0-9_]+)'/", (string) file_get_contents($this->root.'/config/database.php'), $reads);
        $unreviewed = array_diff(array_unique($reads[1]), $covered, self::NON_DESTINATION_KEYS);
        $this->assertSame([], array_values($unreviewed), 'Classify each new config/database.php setting.');
        $this->assertContains('sqlite', self::otherConnections());
        $this->assertContains('mysql', self::otherConnections());
        $this->assertContains('pgsql', self::otherConnections());
        $this->assertContains('sqlsrv', self::otherConnections());
        $selectors = self::connectionSelectorKeys();
        foreach (self::KNOWN_CONNECTION_SELECTORS as $selector) {
            $this->assertContains($selector, $selectors);
        }
        // Shadowed package defaults are not read, so they are not override sites.
        $this->assertNotContains('DELEGATED_ACCESS_NONCE_CONNECTION', $selectors);
    }

    /** @param array<string, string> $processEnv */
    private function assertLocalDatabase(string $env, array $processEnv = [], ?string $script = null): Process
    {
        $home = $this->temporary.'/candidate-home';
        $relative = '.deployments/sample-app/releases/release';
        $candidate = $home.'/'.$relative;
        if (! is_dir($candidate)) {
            mkdir($candidate.'/bootstrap/cache', 0700, true);
            foreach (['vendor', 'config', 'scripts'] as $path) {
                symlink($this->root.'/'.$path, $candidate.'/'.$path);
            }
            // Real copies: the application base path derives from bootstrap/app.php's own directory.
            foreach (['app.php', 'providers.php'] as $file) {
                copy($this->root.'/bootstrap/'.$file, $candidate.'/bootstrap/'.$file);
            }
        }
        file_put_contents($candidate.'/.env', $env);
        $process = new Process(['bash', $script ?? $this->root.'/scripts/deploy/pilot/assert-local-database.sh',
            $relative, PHP_BINARY, 'sample-app'], null, $this->isolatedEnvironment(['HOME' => $home] + $processEnv));
        $process->run();

        return $process;
    }

    /**
     * The test runner forces its own database settings; hosts do not.
     *
     * @param  array<string, string>  $env
     * @return array<string, string|false>
     */
    private function isolatedEnvironment(array $env): array
    {
        $inherited = array_filter(array_keys(getenv() + $_ENV + $_SERVER), 'is_string');
        $cleared = array_fill_keys(array_diff($inherited, ['PATH', 'TMPDIR']), false);

        return array_replace($cleared, $env);
    }

    public function test_pilot_opt_in_cannot_be_written_by_the_bootstrap_to_admit_a_wrong_target(): void
    {
        $home = $this->scaffold();
        file_put_contents($home.'/.config/sample/deployment.env', "APP_DEBUG=false\nMAIL_MAILER=log\nDB_HOST=127.0.0.1\n");
        $this->assertFalse($this->prepare($home)->isSuccessful());
        $this->assertDirectoryExists($home.'/sample-app');
    }

    public function test_real_existing_application_is_not_moved_and_activated_cron_is_not_silently_ignored(): void
    {
        $home = $this->scaffold();
        file_put_contents($home.'/sample-app/artisan', 'existing application');
        $this->assertTrue($this->prepare($home)->isSuccessful());
        $this->assertFileExists($home.'/sample-app/artisan');
        touch($home.'/.config/sample/cron-enabled');
        $this->assertFalse($this->prepare($home)->isSuccessful());
    }

    /** @return array<string, array{string}> */
    public static function phpExecutableNames(): array
    {
        return ['unversioned' => ['php'], 'versioned native' => ['php8.5'], 'CGI' => ['php-cgi8.5'], 'LSAPI' => ['lsphp85'], 'packaged' => ['ea-php85']];
    }

    #[DataProvider('phpExecutableNames')]
    public function test_quiescence_waits_for_own_artisan_but_ignores_other_accounts(string $binaryName): void
    {
        $proc = $this->temporary.'/proc/123';
        mkdir($proc, 0700, true);
        $binary = $this->temporary.'/'.$binaryName;
        touch($binary);
        symlink($binary, $proc.'/exe');
        file_put_contents($proc.'/cmdline', "php\0artisan\0queue:work\0");
        file_put_contents($proc.'/status', "Uid:\t1234\t1234\t1234\t1234\n");
        $command = ['bash', $this->root.'/scripts/deploy/pilot/quiesce.sh',
            '.deployments/sample-app/releases/release', PHP_BINARY, 'sample-app'];
        $env = ['PILOT_QUIESCE_PROC_ROOT' => dirname($proc), 'PILOT_QUIESCE_UID' => '1234', 'PILOT_QUIESCE_TIMEOUT_SECONDS' => '0'];
        $own = new Process($command, null, $env);
        $own->run();
        $this->assertFalse($own->isSuccessful(), $own->getErrorOutput());
        $other = new Process($command, null, array_replace($env, ['PILOT_QUIESCE_UID' => '5678']));
        $other->run();
        $this->assertTrue($other->isSuccessful(), $other->getErrorOutput());
    }

    public function test_public_acl_restoration_grants_only_public_reads_and_refuses_private_symlinks(): void
    {
        $home = $this->scaffold();
        $bin = $this->temporary.'/bin';
        mkdir($bin);
        $log = $this->temporary.'/acl.log';
        file_put_contents($bin.'/setfacl', "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> \"\$PILOT_ACL_LOG\"\n");
        chmod($bin.'/setfacl', 0700);
        $env = ['HOME' => $home, 'PATH' => $bin.':'.getenv('PATH'), 'PILOT_ACL_LOG' => $log];
        $command = ['bash', $this->root.'/scripts/deploy/pilot/post-activate.sh',
            'sample-app', PHP_BINARY, '.deployments/sample-app/releases/release'];
        $private = $home.'/.deployments/sample-app/shared/storage/app/private';
        mkdir($private, 0700, true);
        file_put_contents($private.'/protected.key', 'synthetic key');
        $public = $home.'/.deployments/sample-app/shared/public/viewer';
        mkdir($public, 0700, true);
        file_put_contents($public.'/asset.js', 'synthetic asset');
        symlink($public, $home.'/sample-app/public/viewer');
        $process = new Process($command, null, $env);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $calls = file_get_contents($log);
        $this->assertStringContainsString('u:nginx:r-- '.$public.'/asset.js', $calls);
        $this->assertStringNotContainsString('/storage/app/private', $calls);
        $this->assertStringNotContainsString('sample-app/.env', $calls);
        symlink($private, $home.'/sample-app/public/unsafe');
        $process = new Process($command, null, $env);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringNotContainsString('/storage/app/private', file_get_contents($log));
    }

    public function test_release_manifest_binds_operator_source_tooling_and_individual_run(): void
    {
        mkdir($this->temporary.'/public');
        $env = ['PILOT_OPERATOR_SHA' => str_repeat('a', 40), 'PILOT_APPLICATION_SHA' => str_repeat('b', 40),
            'PILOT_TOOLING_SHA' => str_repeat('c', 40), 'GITHUB_RUN_ID' => '42', 'GITHUB_RUN_ATTEMPT' => '2'];
        $process = new Process(['node', $this->root.'/scripts/deploy/pilot/manifest.mjs'], $this->temporary, $env);
        $process->mustRun();
        $manifest = json_decode(file_get_contents($this->temporary.'/public/pilot-source.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(str_repeat('b', 40), $manifest['application_revision']);
        $this->assertSame('aaaaaaaaaaaa-42-2', $manifest['deployment_id']);
        $source = <<<'JS'
import {pathToFileURL} from 'node:url';
const manifest = JSON.parse(process.argv[2]);
globalThis.fetch = async () => ({ok:true,json:async()=>manifest});
try {await import(pathToFileURL(process.argv[1]));} catch {process.exitCode=1;}
JS;
        $env += ['DEPLOY_SITE_URL' => 'https://pilot.example.test', 'DEPLOY_RELEASE_ID' => $manifest['deployment_id']];
        $verify = fn (array $data): Process => new Process(['node', '--input-type=module', '-e', $source,
            $this->root.'/scripts/deploy/pilot/verify-live.mjs', json_encode($data, JSON_THROW_ON_ERROR)], null, $env);
        $good = $verify($manifest);
        $good->run();
        $this->assertTrue($good->isSuccessful(), $good->getErrorOutput());
        $wrong = $verify(array_replace($manifest, ['application_revision' => str_repeat('d', 40)]));
        $wrong->run();
        $this->assertFalse($wrong->isSuccessful());
    }

    public function test_each_pilot_workflow_is_manual_only_and_uses_separate_native_target_settings(): void
    {
        foreach (['pilot-deploy.yml' => 'pilot'] as $file => $environment) {
            $text = file_get_contents($this->root.'/.github/workflows/'.$file);
            $this->assertSame(1, preg_match('/^on:\R((?:[ \t].*(?:\R|$)|\R)+)/m', $text, $trigger));
            preg_match_all('/^  ([a-z_]+):/m', $trigger[1], $events);
            $this->assertSame(['workflow_dispatch'], $events[1]);
            $this->assertStringContainsString('        default: false', $trigger[1]);
            $this->assertMatchesRegularExpression('/^    environment: '.preg_quote($environment, '/').'$/m', $text);
            $this->assertMatchesRegularExpression('/^    runs-on: ubuntu-24\.04-arm$/m', $text);
            $this->assertMatchesRegularExpression('/^    timeout-minutes: (?:[1-9]|[12][0-9]|30)$/m', $text);
            $this->assertStringNotContainsString('secrets.SSH_', $text);
            $this->assertStringNotContainsString('environment: prod', $text);
            $this->assertStringContainsString('set-php-handler: false', $text);
            $this->assertStringContainsString('install-cron: false', $text);
            $this->assertStringContainsString('php-binary: /usr/bin/php', $text);
        }
    }

    public function test_pilot_keeps_the_shared_ohif_publication_contract_on_its_own_transport(): void
    {
        $text = file_get_contents($this->root.'/.github/workflows/pilot-deploy.yml');
        $this->assertMatchesRegularExpression('/^      OHIF_SSH_TARGET: pilot-bootstrap$/m', $text);
        $this->assertMatchesRegularExpression('/^      PILOT_CI_WORKFLOW: ci\.yml$/m', $text);
        $this->assertMatchesRegularExpression('/^      PILOT_CI_GATE: Run Tests$/m', $text);
        $this->assertSame(1, preg_match('/^      - name: Stage managed OHIF assets for the first installation\R(.*?)(?=^      - |\z)/ms', $text, $staging));
        $this->assertStringContainsString("steps.prepare.outputs.bootstrap == 'true'", $staging[1]);
        $this->assertMatchesRegularExpression('/^          persistent-paths: \|\R            storage\R            public\/ohif$/m', $text);
        $verify = file_get_contents($this->root.'/scripts/deploy/pilot/verify-live.sh');
        $this->assertStringContainsString('OHIF_LOCK_OWNER="$DEPLOY_RELEASE_ID"', $verify);
        $this->assertStringContainsString('verify-phr-ohif-artifact.sh', $verify);
        $this->assertStringContainsString('post-activate.sh', $verify);
        $this->assertStringNotContainsString('verify-phr-finalized.sh', $verify);
    }

    /** @return list<string> */
    private static function block(string $text, string $input): array
    {
        if (preg_match('/^          '.preg_quote($input, '/').': ([^|\r\n]+)$/m', $text, $inline) === 1) {
            return [trim($inline[1])];
        }
        Assert::assertSame(1, preg_match('/^          '.preg_quote($input, '/').': \|\R((?:            [^\r\n]+(?:\R|$))+)/m', $text, $matches));

        return array_map('trim', explode("\n", trim($matches[1])));
    }

    /** @return list<string> */
    private function composedPreMigrateSources(string $text): array
    {
        $this->assertSame(1, preg_match('/^      - name: Compose the pilot pre-migrate hook\R(.*?)(?=^      - |\z)/ms', $text, $step));
        $this->assertSame(1, preg_match('/^          cat ((?:[A-Za-z0-9._\/-]+ )+)> "\$RUNNER_TEMP\/pilot-pre-migrate\.sh"$/m', $step[1], $cat));
        $this->assertStringContainsString('set -euo pipefail', $step[1]);

        return explode(' ', trim($cat[1]));
    }

    public function test_workflow_clears_and_asserts_every_database_destination_key_before_migration(): void
    {
        $text = file_get_contents($this->root.'/.github/workflows/pilot-deploy.yml');
        $this->assertSame(['DB_URL=', 'DB_SOCKET='], self::block($text, 'env-values'));
        $assertions = self::block($text, 'env-assert');
        foreach (['DB_URL=', 'DB_SOCKET=', 'DB_CONNECTION=mariadb', 'DB_HOST=127.0.0.1', 'PILOT_DEPLOYMENT=1', 'APP_DEBUG=false', 'MAIL_MAILER=log'] as $assertion) {
            $this->assertContains($assertion, $assertions);
        }
        // The shared action installs and asserts .env, enters the risk boundary, then runs this
        // hook immediately before config:clear and migrate; the resolved check must come first.
        $this->assertSame(['${{ runner.temp }}/pilot-pre-migrate.sh'], self::block($text, 'pre-migrate-script'));
        $this->assertSame(['scripts/deploy/pilot/assert-local-database.sh', '.github/scripts/provision-phr-candidate-secrets.sh'],
            $this->composedPreMigrateSources($text));
        $compose = strpos($text, '      - name: Compose the pilot pre-migrate hook');
        $publish = strpos($text, '      - name: Publish the isolated native PHP release');
        $this->assertNotFalse($compose);
        $this->assertNotFalse($publish);
        $this->assertLessThan($publish, $compose);
        $this->assertContains('scripts', self::block($text, 'paths'), 'The resolved check boots the uploaded candidate.');
        foreach (['prepare-host.sh', 'assert-local-database.sh'] as $script) {
            $this->assertStringContainsString('$pilot = "mariadb";', file_get_contents($this->root.'/scripts/deploy/pilot/'.$script));
        }
    }

    public function test_composed_pre_migrate_hook_provisions_secrets_only_after_the_database_check_passes(): void
    {
        $text = file_get_contents($this->root.'/.github/workflows/pilot-deploy.yml');
        $hook = $this->temporary.'/pilot-pre-migrate.sh';
        file_put_contents($hook, implode('', array_map(fn (string $path): string => file_get_contents($this->root.'/'.$path),
            $this->composedPreMigrateSources($text))));
        $candidateEnv = $this->temporary.'/candidate-home/.deployments/sample-app/releases/release/.env';

        $refused = $this->assertLocalDatabase(self::PILOT_ENV."DB_SOCKET=/tmp/synthetic-mysql.sock\n", [], $hook);
        $this->assertFalse($refused->isSuccessful());
        $this->assertStringNotContainsString('AGENT_API_MUTATION_DIGEST_KEY', file_get_contents($candidateEnv));

        $accepted = $this->assertLocalDatabase(self::PILOT_ENV, [], $hook);
        $this->assertTrue($accepted->isSuccessful(), $accepted->getErrorOutput());
        $this->assertStringContainsString('Candidate-local agent mutation digest key is configured.', $accepted->getOutput());
        $this->assertMatchesRegularExpression('/^AGENT_API_MUTATION_DIGEST_KEY=base64:/m', file_get_contents($candidateEnv));
    }

    public function test_candidate_upload_preserves_standard_paths_and_the_secret_installer(): void
    {
        $text = file_get_contents($this->root.'/.github/workflows/pilot-deploy.yml');
        $this->assertSame(1, preg_match('/^          paths: \|\R((?:            [^\r\n]+\R)+)/m', $text, $matches));
        $paths = array_map('trim', explode("\n", trim($matches[1])));
        foreach (['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'scripts', 'storage', 'vendor', 'artisan', 'composer.json', 'composer.lock'] as $path) {
            $this->assertContains($path, $paths, "Candidate upload requires {$path}.");
        }
        $installer = 'scripts/configure-agent-mutation-digest-key.php';
        $this->assertFileExists($this->root.'/'.$installer);
        $this->assertStringContainsString('$candidate_root/'.$installer, file_get_contents($this->root.'/.github/scripts/provision-phr-candidate-secrets.sh'));
    }
}
