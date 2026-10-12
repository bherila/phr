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
        $phpStep = strrpos($script, "\n  install_php_dependencies\n");
        $pnpmStep = strrpos($script, "\n  ensure_pnpm\n");

        self::assertIsInt($platformCheck);
        self::assertIsInt($phpStep);
        self::assertIsInt($pnpmStep);
        self::assertTrue($phpStep < $pnpmStep);
        self::assertStringNotContainsString('phpenv install', $script);
        self::assertStringNotContainsString('--ignore-platform-req', $script);
    }

    public function test_pnpm_is_installed_at_the_pinned_version_through_npm_without_a_self_download(): void
    {
        $script = $this->setupScript();
        $start = (int) strpos($script, 'ensure_pnpm() {');
        $function = substr($script, $start, (int) strpos($script, "\n}\n", $start) - $start);

        // The pinned version is installed through npm, which honours the sandbox proxy.
        self::assertStringContainsString('npm install --global --prefix "$HOME/.local"', $function);
        self::assertStringContainsString('"pnpm@$wanted"', $function);
        // Checking the version must never make a mismatched pnpm download its pinned release.
        foreach (explode("\n", $function) as $line) {
            if (str_starts_with(ltrim($line), '#') || ! str_contains($line, 'pnpm --version')) {
                continue;
            }
            self::assertTrue(
                str_contains($line, 'npm_config_manage_package_manager_versions=false pnpm --version') || str_contains($line, 'log "pnpm $(pnpm --version)"'),
                "A version check without the switch guard: {$line}",
            );
        }
        // A wrong version is an error, not a silent fallback.
        self::assertStringContainsString('could not install pnpm $wanted', $function);
        self::assertStringContainsString('export NODE_USE_ENV_PROXY=1', $script);
    }
}
