<?php

namespace Tests\Feature\Files;

use App\Services\Files\AttachmentStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/** Real DDL commits on MariaDB: use a disposable probe instead of RefreshDatabase. */
final class AttachmentReaderMigrationTest extends TestCase
{
    use BuildsSyntheticExpenses, UsesAProbeDatabase;

    public function test_reader_default_preserves_old_uploaders_and_every_lifecycle_and_refuses_unsafe_rollback(): void
    {
        $this->bootProbeDatabase('file_reader_probe');
        Artisan::call('migrate', ['--database' => 'file_reader_probe', '--force' => true]);
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('file_reader_probe');
        Schema::clearResolvedInstance('db.schema');
        Storage::fake('svc_files');
        config(['svc.filesystem_disk' => 'svc_files']);
        try {
            $workspace = $this->syntheticWorkspace('reader-migration');
            $owner = $this->syntheticMember($workspace, 'reader-migration');
            $company = $this->syntheticCompany($workspace, 'reader-migration');
            $file = app(AttachmentStorageService::class)->store($workspace, $company,
                UploadedFile::fake()->createWithContent('synthetic-legacy.txt', 'Synthetic legacy bytes'), $owner);
            $row = (array) DB::table('client_attachments')->where('workspace_id', $workspace->id)->where('id', $file->id)->first();
            unset($row['id'], $row['is_visible_to_client']);
            $migration = require database_path('migrations/2026_10_22_180200_add_attachment_reader_visibility.php');
            $migration->down();
            foreach (['staged', 'available', 'deleting', 'deleted', 'corrupt'] as $state) {
                DB::table('client_attachments')->insert([...$row, 'public_id' => (string) Str::uuid(), 'object_key' => 'synthetic/'.Str::uuid(), 'lifecycle_state' => $state]);
            }
            $migration->up();
            $this->assertSame(6, DB::table('client_attachments')->where('workspace_id', $workspace->id)->where('is_visible_to_client', true)->count());
            // An R0 binary knows neither the column nor an application flag.
            $legacyId = (string) Str::uuid();
            DB::table('client_attachments')->insert([...$row, 'public_id' => $legacyId, 'object_key' => 'synthetic/'.Str::uuid()]);
            $this->assertSame(1, (int) DB::table('client_attachments')->where('workspace_id', $workspace->id)->where('public_id', $legacyId)->value('is_visible_to_client'));
            $indexes = Schema::getIndexes('client_attachments');
            $keys = Schema::getForeignKeys('client_attachments');
            foreach (['private', 'topic', 'comment'] as $incompatible) {
                DB::table('client_attachments')->where('workspace_id', $workspace->id)->where('public_id', $legacyId)
                    ->update(['is_visible_to_client' => $incompatible !== 'private', 'record_type' => $incompatible === 'private' ? 'company' : $incompatible]);
                try {
                    $migration->down();
                    $this->fail('Rollback must not remove a reader required by retained rows.');
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString('rollback would disclose or orphan', $e->getMessage());
                }
                $this->assertTrue(Schema::hasColumn('client_attachments', 'is_visible_to_client'));
                $this->assertSame($indexes, Schema::getIndexes('client_attachments'));
                $this->assertSame($keys, Schema::getForeignKeys('client_attachments'));
            }
            Storage::disk('svc_files')->assertExists($file->object_key);
        } finally {
            DB::setDefaultConnection($default);
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
