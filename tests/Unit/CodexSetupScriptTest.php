<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class CodexSetupScriptTest extends TestCase
{
    private function setupScript(): string
    {
        $path = dirname(__DIR__, 2).'/codex/setup.sh';
        $contents = file_get_contents($path);

        self::assertIsString($contents);

        return $contents;
    }

    public function test_setup_script_has_valid_bash_syntax(): void
    {
        $process = new Process(['bash', '-n', dirname(__DIR__, 2).'/codex/setup.sh']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function test_setup_provides_pdf_text_extraction_and_private_composer_auth(): void
    {
        $script = $this->setupScript();

        self::assertStringContainsString('poppler-utils', $script);
        self::assertStringContainsString('command -v pdftotext', $script);
        self::assertStringContainsString('COMPOSER_AUTH', $script);
        self::assertStringContainsString('GITHUB_TOKEN', $script);
    }

    public function test_composer_platform_validation_runs_before_pnpm(): void
    {
        $script = $this->setupScript();
        $platformCheck = strpos($script, 'composer check-platform-reqs --lock');
        $phpStep = strrpos($script, "\ninstall_php_dependencies\n");
        $pnpmStep = strrpos($script, "\nensure_pnpm\n");

        self::assertIsInt($platformCheck);
        self::assertIsInt($phpStep);
        self::assertIsInt($pnpmStep);
        self::assertTrue($phpStep < $pnpmStep);
        self::assertStringNotContainsString('phpenv install', $script);
        self::assertStringNotContainsString('--ignore-platform-req', $script);
    }
}
