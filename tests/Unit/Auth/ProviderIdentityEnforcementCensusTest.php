<?php

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Whether provider identity enforcement is on is answered by the auth package's
 * ProviderIdentityEnforcement, which a recorded cutover keeps on whatever the setting
 * says. Application code that read bherila-auth.provider_identity.enabled itself would
 * fall outside that lock, so app/ must not name the key at all, comments included.
 */
final class ProviderIdentityEnforcementCensusTest extends TestCase
{
    public function test_application_code_never_reads_the_enforcement_setting_directly(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];
        $scanned = 0;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $scanned++;
            if (str_contains((string) file_get_contents($file->getPathname()), 'provider_identity.enabled')) {
                $offenders[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        $this->assertGreaterThan(0, $scanned, 'The census found no application files to read.');
        $this->assertSame([], $offenders, 'Ask app(BWH\Auth\OAuth\Session\ProviderIdentityEnforcement::class)->enabled() instead.');
    }
}
