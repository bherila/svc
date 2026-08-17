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
        $this->assertStringContainsString('sha256sum {} +', $script);
        $this->assertStringContainsString('shasum -a 256 {} +', $script);
        $this->assertStringContainsString('cmp -s "$manifest_directory/web1.sha256" "$manifest_directory/x-data.sha256"', $script);
        $this->assertStringContainsString('[[ ! -L "$LOCAL_PATH" ]]', $script);
        $this->assertStringContainsString('if [ "$APPLY" -eq 1 ]; then', $script);
        $this->assertStringNotContainsString('rsync "${RSYNC_OPTS[@]}" --delete "${LOCAL_PATH}/" "${REMOTE}/"', $script);
    }

    #[Test]
    public function database_snapshot_is_pull_only_verified_and_scoped_to_x_data(): void
    {
        $script = file_get_contents(__DIR__.'/../../scripts/db-snapshot.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('PROJECT="svc"', $script);
        $this->assertStringContainsString('REMOTE_HOST="ssh-bwh-php"', $script);
        $this->assertStringContainsString('REMOTE_PROJECT_PATH="svc-laravel"', $script);
        $this->assertStringContainsString('LOCAL_DIRECTORY="${DB_SNAPSHOT_DIR:-$X_DATA/${PROJECT}-database}"', $script);
        $this->assertStringNotContainsString('$X_DATA/$PROJECT/database', $script);
        $this->assertStringContainsString('database snapshots must stay outside the rsync-managed', $script);
        $this->assertStringContainsString('--single-transaction', $script);
        $this->assertStringContainsString('--quick', $script);
        $this->assertStringContainsString('--no-tablespaces', $script);
        $this->assertStringContainsString('gzip -t', $script);
        $this->assertStringContainsString('sha256_file', $script);
        $this->assertStringContainsString('[[ "$candidate" =~ ^${PROJECT}-[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{6}Z\\.sql\\.gz$ ]] || continue', $script);
        $this->assertStringContainsString('no restore or push mode exists', $script);
        $this->assertStringNotContainsString('case "$MODE" in\n    push)', $script);
    }
}
