<?php

namespace Tests\Feature\Models;

use App\Models\ExternalImportAttachmentCopy;
use App\Models\ExternalImportFailure;
use App\Models\ExternalImportItem;
use App\Models\ExternalImportRun;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The import ledger outlives the importer that wrote it.
 *
 * The external importer was retired once its one-time job was done. These four
 * tables were kept, and nothing writes them any more - which is precisely why
 * they need a test. Unused tables behind deleted code read as debris to the next
 * person tidying up, and a migration dropping them would look like a cleanup
 * rather than the destruction of the only record of which destination row came
 * from which source row.
 *
 * That record is not reconstructible. A destination row cannot say whether the
 * source row behind it was current or a superseded revision, and that question
 * has already had to be answered here once: an early importer read the
 * predecessor's soft-deleted rows as live, and the repair was possible only
 * because these rows could still name the ones it had wrongly created. Losing
 * the ledger turns any repeat of that into a full re-import, which rewrites rows
 * that are correct and forces every one of them to be re-established.
 *
 * So this asserts the shape a future reader needs rather than the shape the
 * importer happened to write: enough to walk from a run, to the source row
 * behind a destination row, to the digest of the file a copy claims to
 * reproduce, and to a refusal that explains an absence.
 *
 * See `docs/external-data-import.md`.
 */
class ImportLedgerRetentionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The columns that make the ledger legible, named one at a time.
     *
     * Not `hasTable` alone: a surviving table stripped of `source_key` or
     * `target_public_id` still exists and answers nothing, so the mapping
     * columns are the real subject.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function ledgerTables(): array
    {
        return [
            'runs' => ['external_import_runs', [
                'workspace_id', 'source_connection', 'source_identity_hash', 'started_at', 'completed_at',
            ]],
            'items' => ['external_import_items', [
                'external_import_run_id', 'source_table', 'source_key', 'target_type', 'target_public_id',
            ]],
            'failures' => ['external_import_failures', [
                'external_import_run_id', 'source_table', 'reason_code',
            ]],
            'attachment copies' => ['external_import_attachment_copies', [
                'external_import_item_id', 'client_attachment_id', 'source_sha256', 'source_bytes',
            ]],
        ];
    }

    /** @param  list<string>  $columns */
    #[DataProvider('ledgerTables')]
    public function test_the_ledger_keeps_the_columns_that_make_it_readable(string $table, array $columns): void
    {
        $this->assertTrue(Schema::hasTable($table), "{$table} is the retained import ledger and must not be dropped.");

        foreach ($columns as $column) {
            $this->assertTrue(
                Schema::hasColumn($table, $column),
                "{$table}.{$column} is part of the provenance mapping and must not be dropped.",
            );
        }
    }

    /**
     * The chain still resolves, end to end.
     *
     * The column assertions prove the shape survives; this proves it is still
     * usable - that someone holding a destination row's public id can reach the
     * source key behind it, and the digest of the file it came with, through the
     * models rather than by rebuilding the joins from the schema.
     */
    public function test_a_destination_row_still_leads_back_to_the_source_row_behind_it(): void
    {
        $workspace = Workspace::create([
            'name' => 'Import Ledger Retention Fixture',
            'slug' => 'import-ledger-retention-fixture',
        ]);
        $run = $this->importRun($workspace);
        $targetPublicId = (string) Str::uuid();

        $item = ExternalImportItem::create([
            'external_import_run_id' => $run->getKey(),
            'source_connection' => 'retired-source',
            'source_identity_hash' => $run->source_identity_hash,
            'source_table' => 'client_invoices',
            'source_key' => '4821',
            'target_type' => 'invoice',
            'target_public_id' => $targetPublicId,
            'source_fingerprint' => hash('sha256', 'client_invoices:4821'),
            'status' => 'imported',
        ]);

        ExternalImportAttachmentCopy::create([
            'external_import_item_id' => $item->getKey(),
            'workspace_id' => $workspace->getKey(),
            'client_attachment_id' => $this->attachment($workspace),
            'source_path_hash' => hash('sha256', 'source/path'),
            'source_sha256' => hash('sha256', 'file contents'),
            'source_bytes' => 1024,
            'destination_object_key_hash' => hash('sha256', 'destination/key'),
            'copied_at' => now(),
        ]);

        $found = ExternalImportItem::query()->where('target_public_id', $targetPublicId)->firstOrFail();

        $this->assertSame('client_invoices', $found->source_table);
        $this->assertSame('4821', $found->source_key);
        $this->assertSame($workspace->getKey(), $found->workspaceId());
        $this->assertSame($run->getKey(), $found->run?->getKey());
        $this->assertSame(hash('sha256', 'file contents'), $found->copy?->source_sha256);
    }

    /**
     * A refused source row is still distinguishable from one never read.
     *
     * Without this half, a source row missing from the destination is
     * ambiguous - the importer might never have seen it, or might have seen it
     * and declined it for a stated reason. Only the second is recorded here.
     */
    public function test_a_refusal_still_names_its_run_and_its_reason(): void
    {
        $workspace = Workspace::create([
            'name' => 'Import Ledger Refusal Fixture',
            'slug' => 'import-ledger-refusal-fixture',
        ]);
        $run = $this->importRun($workspace);

        $failure = ExternalImportFailure::create([
            'external_import_run_id' => $run->getKey(),
            'source_connection' => 'retired-source',
            'source_table' => 'client_agreements',
            'source_key_hash' => hash('sha256', 'client_agreements:17'),
            'reason_code' => 'missing_start_date',
            'redacted_context' => ['column' => 'active_date'],
            'failure_fingerprint' => hash('sha256', 'missing_start_date'),
        ]);

        $this->assertSame($run->getKey(), $failure->run?->getKey());
        $this->assertSame('missing_start_date', $failure->reason_code);
        $this->assertSame(['column' => 'active_date'], $failure->redacted_context);
        $this->assertSame($workspace->getKey(), $failure->workspaceId());
    }

    private function importRun(Workspace $workspace): ExternalImportRun
    {
        return ExternalImportRun::create([
            'workspace_id' => $workspace->getKey(),
            'source_connection' => 'retired-source',
            'source_identity_hash' => hash('sha256', 'retired-source'),
            'mode' => 'apply',
            'status' => 'completed',
            'source_high_water_marks' => [],
            'counts' => [],
            'fingerprints' => [],
        ]);
    }

    /**
     * A minimal stored file for the copy to point at.
     *
     * Inserted through the query builder rather than the model: this fixture
     * only has to satisfy the foreign key, and going through the attachment
     * lifecycle would make this test depend on rules it is not about.
     */
    private function attachment(Workspace $workspace): int
    {
        return (int) DB::table('client_attachments')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'workspace_id' => $workspace->getKey(),
            'record_type' => 'invoice',
            'record_public_id' => (string) Str::uuid(),
            'object_key' => 'retention-fixture/'.Str::uuid().'.pdf',
            'original_filename' => 'invoice.pdf',
            'media_type' => 'application/pdf',
            'bytes' => 1024,
            'sha256' => hash('sha256', 'file contents'),
            'lifecycle_state' => 'available',
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
