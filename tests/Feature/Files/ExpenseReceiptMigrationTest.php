<?php

namespace Tests\Feature\Files;

use App\Services\Files\AttachmentStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

// DDL implicitly commits on MariaDB; this test must not use RefreshDatabase's transaction.
class ExpenseReceiptMigrationTest extends TestCase
{
    use BuildsSyntheticExpenses, UsesAProbeDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('svc_files');
        config(['svc.filesystem_disk' => 'svc_files']);
    }

    public function test_enum_migration_preserves_existing_rows_indexes_and_foreign_keys_and_refuses_lossy_rollback(): void
    {
        $this->bootProbeDatabase('receipt_probe');
        Artisan::call('migrate', ['--database' => 'receipt_probe', '--force' => true]);
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('receipt_probe');
        Schema::clearResolvedInstance('db.schema');
        try {
            $this->assertMigrationPreservesReceipts();
        } finally {
            DB::setDefaultConnection($default);
            Schema::clearResolvedInstance('db.schema');
        }
    }

    private function assertMigrationPreservesReceipts(): void
    {
        $workspace = $this->syntheticWorkspace('migration');
        $manager = $this->syntheticMember($workspace, 'manager');
        $company = $this->syntheticCompany($workspace, 'migration');
        $attachment = app(AttachmentStorageService::class)->store($workspace, $company, UploadedFile::fake()->createWithContent('existing.txt', 'existing synthetic receipt'), $manager);
        $indexes = Schema::getIndexes('client_attachments');
        $keys = Schema::getForeignKeys('client_attachments');
        $migration = require database_path('migrations/2026_09_08_070000_allow_expense_attachments.php');
        $migration->down();
        $migration->up();
        $this->assertSame($indexes, Schema::getIndexes('client_attachments'));
        $this->assertSame($keys, Schema::getForeignKeys('client_attachments'));
        $this->assertSame('existing.txt', $attachment->fresh()->original_filename);
        Storage::disk('svc_files')->assertExists($attachment->object_key);
        $expense = $this->recordSyntheticExpense($workspace, $company);
        $receipt = app(AttachmentStorageService::class)->store($workspace, $expense, UploadedFile::fake()->createWithContent('retained.txt', 'retained'), $manager);
        try {
            $migration->down();
            $this->fail('Rollback must refuse to remove a retained receipt type.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cannot be rolled back', $exception->getMessage());
        }
        $this->assertSame('expense', $receipt->fresh()->record_type);
        Storage::disk('svc_files')->assertExists($receipt->object_key);
    }
}
