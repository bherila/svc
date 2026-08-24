<?php

namespace Tests\Feature\ExternalImport;

use App\Models\ClientAttachment;
use App\Models\ExternalImportItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ExternalImport\ExternalAttachmentImportService;
use App\Services\ExternalImport\ExternalImportService;
use App\Services\ExternalImport\Fingerprint;
use App\Services\ExternalImport\SyntheticExternalSource;
use App\Services\Files\AttachmentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class ExternalAttachmentImportTest extends TestCase
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
        $this->sourcePath = tempnam(sys_get_temp_dir(), 'svc-external-');
        $this->attachmentRoot = sys_get_temp_dir().'/svc-external-files-'.Str::random(12);
        mkdir($this->attachmentRoot, 0700, true);
        mkdir($this->attachmentRoot.'/synthetic', 0700, true);
        file_put_contents($this->attachmentRoot.'/synthetic/agreement.txt', 'synthetic attachment body');

        Config::set('external-import.sources.external', [
            'connection' => 'synthetic',
            'read_only' => true,
            'config' => ['driver' => 'sqlite', 'database' => $this->sourcePath, 'prefix' => ''],
        ]);
        Config::set('external-import.attachment_root', $this->attachmentRoot);
        app(SyntheticExternalSource::class)->create($this->sourcePath);
        $this->addAgreementAttachmentSource();

        $this->uploader = User::factory()->create();
        Config::set('external-import.user_bindings.7', $this->uploader->public_id);
        $this->workspace = Workspace::create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-workspace']);
        $this->workspace->users()->attach($this->uploader->getKey(), [
            'public_id' => (string) Str::uuid(),
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = app(ExternalImportService::class)->run(
            'external', $this->workspace->slug, true);
        $this->assertSame('completed', $migration['status']);
        $this->assertDatabaseHas('external_import_items', [
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
        $summary = app(ExternalAttachmentImportService::class)->run(
            'external',
            $this->workspace->public_id,
            $this->uploader->public_id,
        );

        $this->assertSame('dry-run', $summary['mode']);
        $this->assertSame(1, $summary['counts']['planned']);
        $this->assertTrue($summary['redacted']);
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertDatabaseCount('external_import_attachment_copies', 0);
        $this->assertSame([], Storage::disk('svc_files')->allFiles());
        $this->assertStringNotContainsString('agreement.txt', json_encode($summary));
        $this->assertStringNotContainsString('synthetic/', json_encode($summary));
    }

    public function test_apply_copies_verifies_and_is_idempotent_without_deleting_source(): void
    {
        $temporaryBefore = glob(sys_get_temp_dir().'/svc-external-attachment-*') ?: [];
        $service = app(ExternalAttachmentImportService::class);
        $first = $service->run(
            'external', $this->workspace->slug, $this->uploader->public_id, true);
        $second = $service->run(
            'external', $this->workspace->slug, $this->uploader->public_id, true);

        $this->assertSame(1, $first['counts']['copied']);
        $this->assertSame(1, $second['counts']['idempotent']);
        $this->assertDatabaseCount('client_attachments', 1);
        $this->assertDatabaseCount('external_import_attachment_copies', 1);
        $this->assertFileExists($this->attachmentRoot.'/synthetic/agreement.txt');

        $attachment = ClientAttachment::query()->firstOrFail();
        Storage::disk('svc_files')->assertExists($attachment->object_key);
        $this->assertSame('Synthetic Agreement.txt', $attachment->original_filename);
        $this->assertNotSame('Synthetic Agreement.txt', $attachment->getRawOriginal('original_filename'));
        $this->assertSame(hash_file('sha256', $this->attachmentRoot.'/synthetic/agreement.txt'), $attachment->sha256);
        $this->assertSame(filesize($this->attachmentRoot.'/synthetic/agreement.txt'), $attachment->bytes);
        $this->assertDatabaseHas('external_import_items', [
            'source_table' => 'files_for_agreements',
            'status' => 'imported',
            'target_public_id' => $attachment->public_id,
        ]);
        $temporaryAfter = glob(sys_get_temp_dir().'/svc-external-attachment-*') ?: [];
        sort($temporaryBefore);
        sort($temporaryAfter);
        $this->assertSame($temporaryBefore, $temporaryAfter);
    }

    public function test_apply_is_idempotent_even_if_the_public_id_derivation_seed_changes(): void
    {
        // public_id is derived from a namespace seed that names this feature (e.g.
        // 'external-attachment'). That seed is allowed to change later (a rename, for
        // example), which would make a fresh run compute a different UUID for the same
        // source row than the one already stored. The copy ledger's client_attachment_id
        // foreign key is the durable link, and must still resolve the existing attachment
        // in that case — not report a broken copy and not create a duplicate.
        $service = app(ExternalAttachmentImportService::class);
        $first = $service->run('external', $this->workspace->slug, $this->uploader->public_id, true);
        $this->assertSame(1, $first['counts']['copied']);

        // public_id is guarded as immutable at the model level, so simulate a row that
        // predates a seed change with a raw update rather than an Eloquent save.
        $attachment = ClientAttachment::query()->firstOrFail();
        DB::table('client_attachments')->where('id', $attachment->getKey())->update(['public_id' => (string) Str::uuid()]);

        $second = $service->run('external', $this->workspace->slug, $this->uploader->public_id, true);

        $this->assertSame(1, $second['counts']['idempotent']);
        $this->assertSame(0, $second['counts']['failed']);
        $this->assertDatabaseCount('client_attachments', 1);
        $this->assertDatabaseCount('external_import_attachment_copies', 1);
    }

    public function test_source_path_traversal_is_rejected_without_writes(): void
    {
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec("UPDATE files_for_agreements SET s3_path = '../outside.txt' WHERE id = 31");
        $row = (array) $pdo->query('SELECT * FROM files_for_agreements WHERE id = 31')->fetch(PDO::FETCH_ASSOC);
        ExternalImportItem::query()->where('source_table', 'files_for_agreements')->update([
            'source_fingerprint' => Fingerprint::row($row),
        ]);

        $summary = app(ExternalAttachmentImportService::class)->run(
            'external',
            $this->workspace->slug,
            $this->uploader->public_id,
            true,
        );

        $this->assertSame('completed_with_failures', $summary['status']);
        $this->assertSame(1, $summary['counts']['failure_reasons']['source_path_invalid']);
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertDatabaseCount('external_import_attachment_copies', 0);
    }

    public function test_source_object_symlinks_are_rejected_without_writes(): void
    {
        symlink('agreement.txt', $this->attachmentRoot.'/synthetic/agreement-link.txt');
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec("UPDATE files_for_agreements SET s3_path = 'synthetic/agreement-link.txt' WHERE id = 31");
        $row = (array) $pdo->query('SELECT * FROM files_for_agreements WHERE id = 31')->fetch(PDO::FETCH_ASSOC);
        ExternalImportItem::query()->where('source_table', 'files_for_agreements')->update([
            'source_fingerprint' => Fingerprint::row($row),
        ]);

        $summary = app(ExternalAttachmentImportService::class)->run(
            'external',
            $this->workspace->slug,
            $this->uploader->public_id,
            true,
        );

        $this->assertSame(1, $summary['counts']['failure_reasons']['source_path_invalid']);
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertDatabaseCount('external_import_attachment_copies', 0);
    }

    public function test_apply_repairs_a_completed_object_whose_provenance_transaction_was_interrupted(): void
    {
        $service = app(ExternalAttachmentImportService::class);
        $service->run(
            'external', $this->workspace->slug, $this->uploader->public_id, true);
        $attachment = ClientAttachment::query()->firstOrFail();
        $item = ExternalImportItem::query()->where('source_table', 'files_for_agreements')->firstOrFail();
        $this->assertDatabaseCount('external_import_attachment_copies', 1);

        $item->forceFill(['target_public_id' => null, 'status' => 'planned_copy', 'reason_code' => 'attachment_copy_deferred'])->save();
        $item->copy()->delete();

        $summary = $service->run(
            'external', $this->workspace->slug, $this->uploader->public_id, true);

        $this->assertSame(1, $summary['counts']['idempotent']);
        $this->assertDatabaseCount('client_attachments', 1);
        $this->assertDatabaseCount('external_import_attachment_copies', 1);
        $this->assertSame($attachment->public_id, $item->fresh()->target_public_id);
        $this->assertSame('imported', $item->fresh()->status);
    }

    public function test_copy_provenance_survives_later_attachment_deletion(): void
    {
        app(ExternalAttachmentImportService::class)->run(
            'external',
            $this->workspace->slug,
            $this->uploader->public_id,
            true,
        );

        ClientAttachment::query()->firstOrFail()->delete();

        $this->assertDatabaseCount('external_import_attachment_copies', 1);
        $this->assertDatabaseHas('external_import_attachment_copies', ['client_attachment_id' => null]);
    }

    public function test_failed_migration_copy_is_quarantined_before_cleanup(): void
    {
        app(ExternalAttachmentImportService::class)->run(
            'external',
            $this->workspace->slug,
            $this->uploader->public_id,
            true,
        );
        $attachment = ClientAttachment::query()->firstOrFail();
        $objectKey = $attachment->object_key;
        $stateAtDelete = null;
        ClientAttachment::deleting(function (ClientAttachment $deleting) use (&$stateAtDelete): void {
            $stateAtDelete = $deleting->lifecycle_state;
        });

        app(AttachmentStorageService::class)->discardMigrationCopy($attachment);

        $this->assertSame(ClientAttachment::STATE_CORRUPT, $stateAtDelete);
        $this->assertDatabaseMissing('client_attachments', ['id' => $attachment->getKey()]);
        Storage::disk('svc_files')->assertMissing($objectKey);
    }

    public function test_json_command_output_never_contains_source_values(): void
    {
        $exit = Artisan::call('svc:import:external:attachments', [
            '--source' => 'external',
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
