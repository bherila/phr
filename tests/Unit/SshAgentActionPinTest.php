<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Issue #124: webfactory/ssh-agent v0.9.1 declares the deprecated Node 20
 * runtime. The pinned commit is upstream PR #243, merged to its default branch,
 * whose only change on top of v0.9.1 is `runs.using: node24`; dist/ is unchanged.
 * Upstream has not tagged a release containing it yet.
 */
final class SshAgentActionPinTest extends TestCase
{
    private const PINNED = 'webfactory/ssh-agent@e83874834305fe9a4a2997156cb26c5de65a8555';

    public function test_every_ssh_agent_step_uses_the_node24_commit_and_only_the_bridge_deploy_key(): void
    {
        $root = dirname(__DIR__, 2).'/.github';
        $files = array_merge(
            glob($root.'/workflows/*.{yml,yaml}', GLOB_BRACE) ?: [],
            glob($root.'/actions/*/action.{yml,yaml}', GLOB_BRACE) ?: [],
        );
        $this->assertNotEmpty($files);

        $steps = [];
        foreach ($files as $path) {
            foreach ($this->sshAgentSteps((string) file_get_contents($path)) as $step) {
                $steps[] = [basename(dirname($path)).'/'.basename($path), ...$step];
            }
        }
        $this->assertNotEmpty($steps);

        foreach ($steps as [$file, $action, $key]) {
            $this->assertSame(self::PINNED, $action, $file.' must pin the Node 24 ssh-agent commit.');
            // Least privilege: the agent only ever holds the private-dependency
            // deploy key, never the production SSH key.
            $this->assertSame('${{ secrets.MCP_BRIDGE_DEPLOY_KEY }}', $key, $file.' loads an unexpected key into ssh-agent.');
        }
    }

    public function test_the_deploy_job_still_authorizes_the_private_bridge_before_composer(): void
    {
        $ci = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/ci.yml');
        $deploy = substr($ci, (int) strpos($ci, "\n  deploy:\n"));

        $agent = strpos($deploy, self::PINNED);
        $composer = strpos($deploy, 'composer install');
        $this->assertIsInt($agent);
        $this->assertIsInt($composer);
        $this->assertLessThan($composer, $agent);
    }

    /**
     * Each non-commented `uses: webfactory/ssh-agent@…` step, with the
     * `ssh-private-key` value found anywhere in that step (null when absent).
     *
     * @return list<array{string, ?string}>
     */
    private function sshAgentSteps(string $yaml): array
    {
        $lines = preg_split('/\R/', $yaml) ?: [];
        $steps = [];
        foreach ($lines as $index => $line) {
            if (preg_match('/^(\s*)(?:-\s+)?uses:\s*[\'"]?(webfactory\/ssh-agent@[^\s\'"]+)/', $line, $match) !== 1) {
                continue;
            }
            $indent = strlen($match[1]);
            $key = null;
            for ($next = $index + 1; $next < count($lines); $next++) {
                $candidate = $lines[$next];
                if (trim($candidate) === '' || str_starts_with(ltrim($candidate), '#')) {
                    continue;
                }
                if (strlen($candidate) - strlen(ltrim($candidate)) < $indent || preg_match('/^\s*-\s/', $candidate) === 1) {
                    break;
                }
                if (preg_match('/^\s*ssh-private-key:\s*(.+?)\s*$/', $candidate, $keyMatch) === 1) {
                    $key = $keyMatch[1];
                }
            }
            $steps[] = [$match[2], $key];
        }

        return $steps;
    }
}
