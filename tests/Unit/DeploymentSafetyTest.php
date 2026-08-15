<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DeploymentSafetyTest extends TestCase
{
    #[Test]
    public function deployment_is_hard_scoped_and_preserves_private_files(): void
    {
        $workflow = file_get_contents(__DIR__.'/../../.github/workflows/tests.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString('environment: web1', $workflow);
        $this->assertStringContainsString(':~/svc-laravel/', $workflow);
        $this->assertStringContainsString("--exclude='.env'", $workflow);
        $this->assertStringContainsString("--exclude='svc-blobs'", $workflow);
        $this->assertStringNotContainsString(':~/', str_replace(':~/svc-laravel/', '', $workflow));
        $this->assertStringNotContainsString(':~/bwh-php/', $workflow);
        $this->assertStringNotContainsString(':~/phr-laravel/', $workflow);
        $this->assertStringNotContainsString(':~/games-laravel/', $workflow);
    }

    #[Test]
    public function blob_mirror_has_fixed_authoritative_and_local_roots(): void
    {
        $script = file_get_contents(__DIR__.'/../../scripts/blobs.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('PROJECT="svc"', $script);
        $this->assertStringContainsString('REMOTE_HOST="ssh-bwh-php"', $script);
        $this->assertStringContainsString('REMOTE_PATH="svc-laravel/storage/app/private"', $script);
        $this->assertStringContainsString('rsync "${RSYNC_OPTS[@]}" --delete --chmod=Du=rwx,Dgo=,Fu=rw,Fgo= "${REMOTE}/" "${LOCAL_PATH}/"', $script);
        $this->assertStringContainsString('find "$LOCAL_PATH" -type d -exec chmod 700 {} +', $script);
        $this->assertStringContainsString('find "$LOCAL_PATH" -type f -exec chmod 600 {} +', $script);
        $this->assertStringNotContainsString('rsync "${RSYNC_OPTS[@]}" --delete "${LOCAL_PATH}/" "${REMOTE}/"', $script);
    }
}
