<?php

namespace Tests\Unit\Files;

use App\Services\Files\AttachmentPrivacyLatch;
use App\Services\Files\AttachmentUploadPolicy;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

#[CoversClass(AttachmentUploadPolicy::class)]
#[CoversClass(AttachmentPrivacyLatch::class)]
final class AttachmentUploadPolicyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/svc-policy-privacy-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->directory, 0700);
        $this->app->instance(AttachmentPrivacyLatch::class, new AttachmentPrivacyLatch($this->directory));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_initial_default_inherits_but_a_retained_marker_overrides_old_config_and_agent_cutovers(): void
    {
        $this->assertFalse(config('svc.attachments.private_uploads_enabled'));
        $this->assertTrue(AttachmentUploadPolicy::defaultVisibility());
        config(['svc.attachments' => [], 'agent_api.writes_enabled' => false]);
        $this->assertTrue(AttachmentUploadPolicy::defaultVisibility());
        File::put($this->directory.'/'.AttachmentPrivacyLatch::MARKER, "enabled\n");
        config(['svc.attachments.private_uploads_enabled' => true]);
        $this->assertFalse(AttachmentUploadPolicy::defaultVisibility());
        // Automatic rollback can restore the former config cache. The durable
        // latch must still win, even with every Agent write cutover disabled.
        config(['svc.attachments.private_uploads_enabled' => false]);
        $this->assertFalse(AttachmentUploadPolicy::defaultVisibility());
        config(['agent_api.writes_enabled' => true]);
        $this->assertFalse(AttachmentUploadPolicy::defaultVisibility());
    }

    public function test_the_environment_check_cannot_enable_private_defaults_without_the_durable_latch(): void
    {
        config(['svc.attachments.private_uploads_enabled' => true]);
        try {
            AttachmentUploadPolicy::defaultVisibility();
            $this->fail('The requested private default needs a durable marker first.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertStringContainsString('durable privacy latch', $exception->getMessage());
        }
    }

    public function test_missing_local_policy_directory_refuses_upload_instead_of_guessing_public(): void
    {
        $this->app->instance(AttachmentPrivacyLatch::class, new AttachmentPrivacyLatch($this->directory.'/missing'));
        try {
            AttachmentUploadPolicy::defaultVisibility();
            $this->fail('A missing policy directory cannot establish the public default.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertStringContainsString('durable upload policy', $exception->getMessage());
        }
    }

    public function test_a_regular_file_cannot_substitute_for_the_policy_directory(): void
    {
        $path = $this->directory.'/synthetic-file';
        File::put($path, 'Synthetic non-directory');
        try {
            (new AttachmentPrivacyLatch($path))->enabled();
            $this->fail('A readable file is not a durable policy directory.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
    }

    public function test_an_unreadable_policy_directory_fails_closed(): void
    {
        chmod($this->directory, 0000);
        try {
            clearstatcache(true, $this->directory);
            if (is_readable($this->directory)) {
                $this->markTestSkipped('The current privileged user bypasses directory read permissions.');
            }
            try {
                (new AttachmentPrivacyLatch($this->directory))->enabled();
                $this->fail('An unreadable directory cannot establish the public default.');
            } catch (HttpException $exception) {
                $this->assertSame(503, $exception->getStatusCode());
            }
        } finally {
            chmod($this->directory, 0700);
        }
    }

    public function test_a_retained_broken_marker_alias_still_forces_privacy(): void
    {
        symlink($this->directory.'/synthetic-missing-target', $this->directory.'/'.AttachmentPrivacyLatch::MARKER);
        $this->assertFalse(AttachmentUploadPolicy::defaultVisibility());
    }

    public function test_a_readable_but_unsearchable_directory_cannot_hide_the_retained_marker(): void
    {
        File::put($this->directory.'/'.AttachmentPrivacyLatch::MARKER, "enabled\n");
        config(['svc.attachments.private_uploads_enabled' => false]);
        chmod($this->directory, 0400);
        try {
            clearstatcache();
            if (is_executable($this->directory)) {
                $this->markTestSkipped('The current privileged user bypasses directory search permissions.');
            }
            $this->assertTrue(is_readable($this->directory));
            $this->assertFalse(file_exists($this->directory.'/'.AttachmentPrivacyLatch::MARKER));
            try {
                AttachmentUploadPolicy::defaultVisibility();
                $this->fail('An unsearchable directory cannot establish the public default.');
            } catch (HttpException $exception) {
                $this->assertSame(503, $exception->getStatusCode());
            }
        } finally {
            chmod($this->directory, 0700);
        }
    }

    public function test_production_default_path_is_fixed_under_local_persistent_storage(): void
    {
        $original = $this->app->storagePath();
        try {
            $this->app->useStoragePath($this->directory);
            File::makeDirectory($this->directory.'/app/private', 0700, true);
            $latch = new AttachmentPrivacyLatch;
            $this->assertFalse($latch->enabled());
            File::put($this->directory.'/app/private/'.AttachmentPrivacyLatch::MARKER, "enabled\n");
            $this->assertTrue($latch->enabled());
        } finally {
            $this->app->useStoragePath($original);
        }
    }

    public function test_the_product_default_never_admits_caller_controlled_visibility_fields(): void
    {
        foreach ([false, true] as $latched) {
            if ($latched) {
                File::put($this->directory.'/'.AttachmentPrivacyLatch::MARKER, "enabled\n");
            }
            config(['svc.attachments.private_uploads_enabled' => $latched]);
            AttachmentUploadPolicy::validateLegacyPayload([]);
            foreach ([false, true, null] as $value) {
                try {
                    AttachmentUploadPolicy::validateLegacyPayload(['is_visible_to_client' => $value]);
                    $this->fail('The reader floor does not expose a sharing control.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('is_visible_to_client', $exception->errors());
                }
            }
        }
    }
}
