<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class MaintenanceResumeWorkflowTest extends TestCase
{
    public function test_manual_resume_uses_the_new_credential_and_the_production_writer_queue(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/maintenance-resume.yml');
        $this->assertStringContainsString("github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main'", $workflow);
        $this->assertStringContainsString('environment: prod', $workflow);
        $this->assertMatchesRegularExpression('/group: phr-production-files\R\s+cancel-in-progress: false\R\s+queue: max/', $workflow);
        $this->assertStringContainsString('secrets.PHR_PRODUCTION_SSH_KEY', $workflow);
        $this->assertStringNotContainsString('secrets.SSH_PRIVATE_KEY', $workflow);
        $this->assertSame(2, substr_count($workflow, '76e914604ae1e7d07d045f5412371b6634178e47'));
        $this->assertStringContainsString('actions: read', $workflow);
        foreach (['diagnose-phr-maintenance*.sh', 'inspect-phr-maintenance.sh', 'verify-phr-cron.sh'] as $consumed) {
            $this->assertStringContainsString('.github/scripts/'.$consumed, $workflow);
        }
        $diagnostic = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/maintenance-diagnostic.yml');
        $this->assertStringNotContainsString('resume-phr-maintenance.sh', $diagnostic);
        $this->assertStringContainsString('secrets.PHR_PRODUCTION_SSH_KEY', $diagnostic);
        $this->assertStringNotContainsString('secrets.SSH_PRIVATE_KEY', $diagnostic);
    }

    public function test_real_laravel_up_and_down_use_private_bootstrap_caches(): void
    {
        $root = sys_get_temp_dir().'/phr-resume-laravel-'.bin2hex(random_bytes(12));
        $files = new Filesystem;
        foreach (['vendor', 'bootstrap/cache', 'storage/framework', 'storage/logs', 'storage/app/private/oauth', 'private'] as $path) {
            $files->makeDirectory($root.'/'.$path, 0700, true);
        }
        $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
        file_put_contents($root.'/vendor/autoload.php', '<?php require '.var_export($autoload, true).';');
        file_put_contents($root.'/composer.json', '{"extra":{"laravel":{"dont-discover":["*"]}}}');
        file_put_contents($root.'/bootstrap/app.php', '<?php $app = Illuminate\\Foundation\\Application::configure(basePath: dirname(__DIR__))->withCommands([App\\Console\\Commands\\Phr\\AgentApiVerifyOAuthKeysCommand::class])->create(); Laravel\\Passport\\Passport::loadKeysFrom($app->storagePath("app/private/oauth")); return $app;');
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($pair, $pem);
        file_put_contents($root.'/storage/app/private/oauth/oauth-private.key', $pem);
        file_put_contents($root.'/storage/app/private/oauth/oauth-public.key', openssl_pkey_get_details($pair)['key']);
        $config = ['app' => ['name' => 'Synthetic resume', 'env' => 'testing', 'debug' => false,
            'key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'cipher' => 'AES-256-CBC',
            'url' => 'https://synthetic.invalid', 'timezone' => 'UTC', 'maintenance' => ['driver' => 'file'],
            'providers' => ServiceProvider::defaultProviders()->toArray(), 'aliases' => []],
            'logging' => ['default' => 'stderr', 'channels' => ['stderr' => ['driver' => 'errorlog']]],
        ];
        file_put_contents($root.'/private/config.php', '<?php return '.var_export($config, true).';');
        file_put_contents($root.'/bootstrap/cache/config.php', '<?php return ["original" => "unchanged"];');
        file_put_contents($root.'/storage/framework/down', '{"status":503}');
        $before = hash_file('sha256', $root.'/bootstrap/cache/config.php');
        try {
            foreach (['prove-down' => 'maintenance', 'up' => 'serving', 'prove-up' => 'serving', 'down' => 'maintenance'] as $mode => $expect) {
                $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G',
                    dirname(__DIR__, 2).'/.github/scripts/resume-phr-framework.php', $mode, $root.'/private'],
                    $root, ['APP_ENV' => 'testing', 'DB_URL' => '', 'DATABASE_URL' => '', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:']);
                $process->setTimeout(30);
                $process->run();
                $this->assertTrue($process->isSuccessful(), 'Real Laravel maintenance command failed: '.$process->getErrorOutput());
                $this->assertSame('phr-resume framework='.$expect."\n", $process->getOutput());
                $this->assertSame($before, hash_file('sha256', $root.'/bootstrap/cache/config.php'));
                $this->assertSame(['config.php'], array_map(fn ($file) => $file->getFilename(), $files->files($root.'/bootstrap/cache')));
            }
            $this->assertFileExists($root.'/storage/framework/down');
            $this->assertFileExists($root.'/storage/framework/maintenance.php');
            $config['app']['maintenance']['driver'] = 'cache';
            file_put_contents($root.'/private/config.php', '<?php return '.var_export($config, true).';');
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G',
                dirname(__DIR__, 2).'/.github/scripts/resume-phr-framework.php', 'up', $root.'/private'], $root);
            $process->setTimeout(30);
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $this->assertSame('', $process->getOutput());
            $this->assertFileExists($root.'/storage/framework/down');
            $this->assertSame($before, hash_file('sha256', $root.'/bootstrap/cache/config.php'));
            $config['app']['maintenance']['driver'] = 'file';
            file_put_contents($root.'/private/config.php', '<?php return '.var_export($config, true).';');
            file_put_contents($root.'/storage/app/private/oauth/oauth-public.key', 'synthetic mismatched public key');
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G',
                dirname(__DIR__, 2).'/.github/scripts/resume-phr-framework.php', 'prove-down', $root.'/private'], $root);
            $process->setTimeout(30);
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $this->assertSame('', $process->getOutput());
            $this->assertFileExists($root.'/storage/framework/down');
        } finally {
            $files->deleteDirectory($root);
        }
    }
}
