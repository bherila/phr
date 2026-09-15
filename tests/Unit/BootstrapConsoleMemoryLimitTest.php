<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class BootstrapConsoleMemoryLimitTest extends TestCase
{
    public function test_cli_bootstrap_applies_the_inherited_cron_memory_limit(): void
    {
        $script = <<<'PHP'
require 'vendor/autoload.php';
require 'bootstrap/app.php';
echo ini_get('memory_limit');
PHP;

        $process = new Process(
            [PHP_BINARY, '-d', 'memory_limit=128M', '-r', $script],
            dirname(__DIR__, 2),
            ['PHR_CRON_MEMORY_LIMIT' => '384M'],
        );

        $process->mustRun();

        self::assertSame('384M', $process->getOutput());
    }
}
