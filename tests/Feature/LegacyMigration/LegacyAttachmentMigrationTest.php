<?php

namespace Tests\Feature\LegacyMigration;

use App\Models\ClientAttachment;
use App\Models\LegacyMigrationItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LegacyMigration\Fingerprint;
use App\Services\LegacyMigration\LegacyAttachmentMigrationService;
use App\Services\LegacyMigration\LegacyMigrationService;
use App\Services\LegacyMigration\SyntheticLegacySource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class LegacyAttachmentMigrationTest extends TestCase
{
    use RefreshDatabase;

    private string $sourcePath;

    private string $attachmentRoot;

    private Workspace $workspace;

    private User $uploader;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('svc_files');
        $this->sourcePath = tempnam(sys_get_temp_dir(), 'svc-legacy-');
        $this->attachmentRoot = sys_get_temp_dir().'/svc-legacy-files-'.Str::random(12);
        mkdir($this->attachmentRoot, 0700, true);
        mkdir($this->attachmentRoot.'/synthetic', 0700, true);
        file_put_contents($this->attachmentRoot.'/synthetic/agreement.txt', 'synthetic attachment body');

        Config::set('legacy-migration.sources.legacy', [
            'connection' => 'synthetic',
            'read_only' => true,
            'config' => ['driver' => 'sqlite', 'database' => $this->sourcePath, 'prefix' => ''],
        ]);
        Config::set('legacy-migration.attachment_root', $this->attachmentRoot);
        app(SyntheticLegacySource::class)->create($this->sourcePath);
        $this->addAgreementAttachmentSource();

        $this->uploader = User::factory()->create();
        Config::set('legacy-migration.user_bindings.7', $this->uploader->public_id);
        $this->workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $this->workspace->users()->attach($this->uploader->getKey(), [
            'public_id' => (string) Str::uuid(),
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = app(LegacyMigrationService::class)->run('legacy', $this->workspace->slug, true);
        $this->assertSame('completed', $migration['status']);
        $this->assertDatabaseHas('legacy_migration_items', [
            'source_table' => 'files_for_agreements',
            'status' => 'planned_copy',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->sourcePath)) {
            unlink($this->sourcePath);
        }
        $sourceLink = $this->attachmentRoot.'/synthetic/agreement-link.txt';
        if (is_link($sourceLink)) {
            unlink($sourceLink);
        }
        $sourceFile = $this->attachmentRoot.'/synthetic/agreement.txt';
        if (is_file($sourceFile)) {
            unlink($sourceFile);
        }
        if (is_dir($this->attachmentRoot.'/synthetic')) {
            rmdir($this->attachmentRoot.'/synthetic');
        }
        if (is_dir($this->attachmentRoot)) {
            rmdir($this->attachmentRoot);
        }

        parent::tearDown();
    }

    public function test_dry_run_is_redacted_and_has_no_destination_writes(): void
    {
        $summary = app(LegacyAttachmentMigrationService::class)->run(
            'legacy',
            $this->workspace->public_id,
            $this->uploader->public_id,
        );

        $this->assertSame('dry-run', $summary['mode']);
        $this->assertSame(1, $summary['counts']['planned']);
        $this->assertTrue($summary['redacted']);
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertDatabaseCount('legacy_attachment_copies', 0);
        $this->assertSame([], Storage::disk('svc_files')->allFiles());
        $this->assertStringNotContainsString('agreement.txt', json_encode($summary));
        $this->assertStringNotContainsString('synthetic/', json_encode($summary));
    }

    public function test_apply_copies_verifies_and_is_idempotent_without_deleting_source(): void
    {
        $service = app(LegacyAttachmentMigrationService::class);
        $first = $service->run('legacy', $this->workspace->slug, $this->uploader->public_id, true);
        $second = $service->run('legacy', $this->workspace->slug, $this->uploader->public_id, true);

        $this->assertSame(1, $first['counts']['copied']);
        $this->assertSame(1, $second['counts']['idempotent']);
        $this->assertDatabaseCount('client_attachments', 1);
        $this->assertDatabaseCount('legacy_attachment_copies', 1);
        $this->assertFileExists($this->attachmentRoot.'/synthetic/agreement.txt');

        $attachment = ClientAttachment::query()->firstOrFail();
        Storage::disk('svc_files')->assertExists($attachment->object_key);
        $this->assertSame('Synthetic Agreement.txt', $attachment->original_filename);
        $this->assertNotSame('Synthetic Agreement.txt', $attachment->getRawOriginal('original_filename'));
        $this->assertSame(hash_file('sha256', $this->attachmentRoot.'/synthetic/agreement.txt'), $attachment->sha256);
        $this->assertSame(filesize($this->attachmentRoot.'/synthetic/agreement.txt'), $attachment->bytes);
        $this->assertDatabaseHas('legacy_migration_items', [
            'source_table' => 'files_for_agreements',
            'status' => 'imported',
            'target_public_id' => $attachment->public_id,
        ]);
    }

    public function test_source_path_traversal_is_rejected_without_writes(): void
    {
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec("UPDATE files_for_agreements SET s3_path = '../outside.txt' WHERE id = 31");
        $row = (array) $pdo->query('SELECT * FROM files_for_agreements WHERE id = 31')->fetch(PDO::FETCH_ASSOC);
        LegacyMigrationItem::query()->where('source_table', 'files_for_agreements')->update([
            'source_fingerprint' => Fingerprint::row($row),
        ]);

        $summary = app(LegacyAttachmentMigrationService::class)->run(
            'legacy',
            $this->workspace->slug,
            $this->uploader->public_id,
            true,
        );

        $this->assertSame('completed_with_failures', $summary['status']);
        $this->assertSame(1, $summary['counts']['failure_reasons']['source_path_invalid']);
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertDatabaseCount('legacy_attachment_copies', 0);
    }

    public function test_source_object_symlinks_are_rejected_without_writes(): void
    {
        symlink('agreement.txt', $this->attachmentRoot.'/synthetic/agreement-link.txt');
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec("UPDATE files_for_agreements SET s3_path = 'synthetic/agreement-link.txt' WHERE id = 31");
        $row = (array) $pdo->query('SELECT * FROM files_for_agreements WHERE id = 31')->fetch(PDO::FETCH_ASSOC);
        LegacyMigrationItem::query()->where('source_table', 'files_for_agreements')->update([
            'source_fingerprint' => Fingerprint::row($row),
        ]);

        $summary = app(LegacyAttachmentMigrationService::class)->run(
            'legacy',
            $this->workspace->slug,
            $this->uploader->public_id,
            true,
        );

        $this->assertSame(1, $summary['counts']['failure_reasons']['source_path_invalid']);
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertDatabaseCount('legacy_attachment_copies', 0);
    }

    public function test_apply_repairs_a_completed_object_whose_provenance_transaction_was_interrupted(): void
    {
        $service = app(LegacyAttachmentMigrationService::class);
        $service->run('legacy', $this->workspace->slug, $this->uploader->public_id, true);
        $attachment = ClientAttachment::query()->firstOrFail();
        $item = LegacyMigrationItem::query()->where('source_table', 'files_for_agreements')->firstOrFail();
        $this->assertDatabaseCount('legacy_attachment_copies', 1);

        $item->forceFill(['target_public_id' => null, 'status' => 'planned_copy', 'reason_code' => 'attachment_copy_deferred'])->save();
        $item->copy()->delete();

        $summary = $service->run('legacy', $this->workspace->slug, $this->uploader->public_id, true);

        $this->assertSame(1, $summary['counts']['idempotent']);
        $this->assertDatabaseCount('client_attachments', 1);
        $this->assertDatabaseCount('legacy_attachment_copies', 1);
        $this->assertSame($attachment->public_id, $item->fresh()->target_public_id);
        $this->assertSame('imported', $item->fresh()->status);
    }

    public function test_copy_provenance_survives_later_attachment_deletion(): void
    {
        app(LegacyAttachmentMigrationService::class)->run(
            'legacy',
            $this->workspace->slug,
            $this->uploader->public_id,
            true,
        );

        ClientAttachment::query()->firstOrFail()->delete();

        $this->assertDatabaseCount('legacy_attachment_copies', 1);
        $this->assertDatabaseHas('legacy_attachment_copies', ['client_attachment_id' => null]);
    }

    public function test_json_command_output_never_contains_source_values(): void
    {
        $exit = Artisan::call('svc:migrate:legacy:attachments', [
            '--source' => 'legacy',
            '--workspace' => $this->workspace->slug,
            '--uploader' => $this->uploader->public_id,
            '--format' => 'json',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"redacted":true', $output);
        $this->assertStringNotContainsString('agreement.txt', $output);
        $this->assertStringNotContainsString('synthetic/', $output);
        $this->assertStringNotContainsString($this->uploader->email, $output);
    }

    private function addAgreementAttachmentSource(): void
    {
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE client_agreements (id INTEGER PRIMARY KEY, client_company_id INTEGER, proposal_id INTEGER, title TEXT, active_date TEXT, termination_date TEXT, agreement_text TEXT, is_visible_to_client INTEGER, currency TEXT, hourly_rate TEXT, monthly_retainer_fee TEXT, retainer_fee TEXT, monthly_retainer_hours TEXT, retainer_hours TEXT, billing_cadence TEXT, client_company_signed_date TEXT, client_company_signed_user_id INTEGER, client_company_signed_name TEXT, client_company_signed_title TEXT)');
        $pdo->exec("INSERT INTO client_agreements VALUES (21, 11, NULL, 'Synthetic Agreement', '2026-01-01', NULL, NULL, 1, 'USD', '125.00', NULL, NULL, NULL, NULL, 'monthly', NULL, NULL, NULL, NULL)");
        $pdo->exec('CREATE TABLE files_for_agreements (id INTEGER PRIMARY KEY, agreement_id INTEGER, original_filename TEXT, stored_filename TEXT, s3_path TEXT, mime_type TEXT, file_size_bytes INTEGER, uploaded_by_user_id INTEGER, deleted_at TEXT, created_at TEXT, updated_at TEXT)');
        $bytes = filesize($this->attachmentRoot.'/synthetic/agreement.txt');
        $statement = $pdo->prepare('INSERT INTO files_for_agreements VALUES (31, 21, :original, :stored, :path, :mime, :bytes, 7, NULL, :created, :updated)');
        $statement->execute([
            'original' => 'Synthetic Agreement.txt',
            'stored' => 'agreement.txt',
            'path' => 'synthetic/agreement.txt',
            'mime' => 'text/plain',
            'bytes' => $bytes,
            'created' => '2026-01-02',
            'updated' => '2026-01-02',
        ]);
    }
}
