<?php

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Whether provider identity enforcement is on is answered by the package's
 * ProviderIdentityEnforcement, which a recorded cutover keeps on whatever the
 * setting says. Reading bherila-auth.provider_identity.enabled directly would leave
 * that path outside the lock.
 */
final class ProviderIdentityEnforcementCensusTest extends TestCase
{
    public function test_application_code_never_reads_the_enforcement_setting_directly(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, 'provider_identity.enabled')) {
                $offenders[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        $this->assertSame([], $offenders, 'Ask app(BWH\Auth\OAuth\Session\ProviderIdentityEnforcement::class)->enabled() instead.');
    }
}
