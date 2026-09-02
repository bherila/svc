<?php

namespace Tests\Feature\ExternalImport;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\ExternalImport\SourceGuard;
use App\Services\ExternalImport\SupersededImportRepairer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Retiring the rows an earlier import took from deleted source rows.
 *
 * The defect these pin is not arithmetic. Every amount in the production import
 * matched the source to the minor unit - which is why a byte-for-byte money
 * comparison passed while 764 of 822 invoice lines should not have existed at
 * all. The question is row membership, so that is what is asserted: which rows
 * survive, and whether what survives adds up.
 *
 * Fixtures are synthetic: reserved-looking names, no real client data.
 */
class SupersededImportRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_retires_lines_whose_source_row_was_deleted_and_leaves_the_invoice_adding_up(): void
    {
        $workspace = $this->workspace();
        $invoice = $this->invoice($workspace, 'SYNTH-202601-001', 375000);

        $live = $this->line($workspace, $invoice, 375000, sourceKey: '1');
        $this->line($workspace, $invoice, 375000, sourceKey: '2');
        $this->line($workspace, $invoice, 375000, sourceKey: '3');

        $this->sourceRows(deletedLineKeys: ['2', '3']);

        $counts = $this->repairer()->repair($workspace, $this->source(), apply: true);

        $this->assertSame(2, $counts->retiredLines);
        $this->assertSame(0, $counts->retiredInvoices);
        $this->assertSame(0, $counts->survivorsNotReconciling);
        $this->assertTrue($counts->reconciled());
        $this->assertSame([$live], DB::table('client_invoice_lines')->pluck('id')->all());
    }

    /**
     * A superseded invoice takes its lines with it, whether or not the source
     * marked those lines deleted. Sparing them would leave lines pointing at an
     * invoice that no longer exists.
     */
    public function test_a_superseded_invoice_takes_its_own_lines_with_it(): void
    {
        $workspace = $this->workspace();
        $doomed = $this->invoice($workspace, 'SYNTH-202602-001', 60000, sourceKey: '9');
        $this->line($workspace, $doomed, 60000, sourceKey: '10');

        $this->sourceRows(deletedInvoiceKeys: ['9'], deletedLineKeys: []);

        $counts = $this->repairer()->repair($workspace, $this->source(), apply: true);

        $this->assertSame(1, $counts->retiredInvoices);
        $this->assertSame(1, $counts->retiredLines, 'The line was not itself deleted in the source');
        $this->assertSame(0, DB::table('client_invoice_lines')->count());
        $this->assertSame(0, DB::table('client_invoices')->count());
    }

    /**
     * Money stops this repair.
     *
     * A payment against a supposedly superseded invoice means either the
     * payment is real, so the invoice is not superseded, or the import went
     * wrong a second way. Both need a person, and deleting the invoice would
     * destroy the payment with it.
     */
    public function test_it_refuses_to_retire_an_invoice_that_carries_a_payment(): void
    {
        $workspace = $this->workspace();
        $paid = $this->invoice($workspace, 'SYNTH-202603-001', 60000, sourceKey: '11');
        $this->line($workspace, $paid, 60000, sourceKey: '12');
        DB::table('client_invoice_payments')->insert([
            'public_id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'client_invoice_id' => $paid->id,
            'amount' => 60000,
            'currency' => 'USD',
            'received_on' => '2026-01-15',
            'method' => 'bank_transfer',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->sourceRows(deletedInvoiceKeys: ['11'], deletedLineKeys: ['12']);

        $counts = $this->repairer()->repair($workspace, $this->source(), apply: true);

        $this->assertSame(1, $counts->skippedWithAPayment);
        $this->assertSame(0, $counts->retiredInvoices);
        $this->assertSame(0, $counts->retiredLines, 'Its lines are spared with it, or the invoice we kept is corrupted');
        $this->assertSame(1, DB::table('client_invoices')->count());
        $this->assertSame(1, DB::table('client_invoice_lines')->count());
    }

    /**
     * The preview has to describe the state the write would leave, or it is a
     * second implementation that merely agrees today. Asserted by running the
     * preview and the apply against the same fixture and comparing.
     */
    public function test_the_preview_reports_what_the_write_would_do(): void
    {
        $workspace = $this->workspace();
        $invoice = $this->invoice($workspace, 'SYNTH-202604-001', 375000);
        $this->line($workspace, $invoice, 375000, sourceKey: '20');
        $this->line($workspace, $invoice, 375000, sourceKey: '21');
        $this->sourceRows(deletedLineKeys: ['21']);

        $preview = $this->repairer()->repair($workspace, $this->source());

        $this->assertFalse($preview->applied);
        $this->assertSame(1, $preview->eligibleLines);
        $this->assertSame(0, $preview->retiredLines, 'A preview writes nothing');
        $this->assertSame(0, $preview->survivorsNotReconciling, 'Reported as the write would leave it, not as it stands');
        $this->assertSame(2, DB::table('client_invoice_lines')->count());

        $applied = $this->repairer()->repair($workspace, $this->source(), apply: true);

        $this->assertSame($preview->eligibleLines, $applied->retiredLines);
        $this->assertSame($preview->survivorsNotReconciling, $applied->survivorsNotReconciling);
    }

    /**
     * The repair deletes rows, so a workspace boundary crossed here is
     * unrecoverable. Asserted with an identical superseded row in a second
     * tenant, which must survive untouched.
     */
    public function test_it_never_reaches_another_workspaces_rows(): void
    {
        $mine = $this->workspace('synthetic-mine');
        $theirs = $this->workspace('synthetic-theirs');

        $myInvoice = $this->invoice($mine, 'SYNTH-202605-001', 0, sourceKey: '30');
        $theirInvoice = $this->invoice($theirs, 'SYNTH-202605-002', 0, sourceKey: '31');

        // Both source rows are deleted, so only the workspace scope separates them.
        $this->sourceRows(deletedInvoiceKeys: ['30', '31']);

        $counts = $this->repairer()->repair($mine, $this->source(), apply: true);

        $this->assertSame(1, $counts->retiredInvoices);
        $this->assertNull(ClientInvoice::query()->find($myInvoice->id));
        $this->assertNotNull(ClientInvoice::query()->find($theirInvoice->id), 'The other tenant keeps its row');
    }

    /** The command will not write without an explicit acknowledgement that a backup exists. */
    public function test_the_command_refuses_to_apply_without_a_snapshot(): void
    {
        $this->artisan('svc:import:repair-superseded --apply')
            ->expectsOutputToContain('Refusing to apply without --snapshot-taken')
            ->assertExitCode(1);
    }

    private function repairer(): SupersededImportRepairer
    {
        return app(SupersededImportRepairer::class);
    }

    /**
     * A real, separate source database.
     *
     * A file rather than `:memory:`, because {@see SourceGuard::connection()}
     * builds its own connection - and a second in-memory sqlite connection is a
     * second, empty database, not another handle on this one. Getting that
     * wrong makes the source look like it has no deleted rows at all, which is
     * exactly the answer that would make this repair silently do nothing.
     *
     * @return array{key: string, connection: string, config: array<string, mixed>, identity: array<string, string>, identity_hash: string, declared_restore_of: string|null}
     */
    private function source(): array
    {
        config()->set('external-import.sources.external', [
            'connection' => 'external_probe',
            'read_only' => true,
            'restore_of_database' => null,
            'config' => ['driver' => 'sqlite', 'database' => $this->sourceFile(), 'prefix' => ''],
        ]);

        return app(SourceGuard::class)->resolve('external');
    }

    private ?string $sourceFile = null;

    private function sourceFile(): string
    {
        if ($this->sourceFile === null) {
            $file = tempnam(sys_get_temp_dir(), 'svc-source-');
            $this->sourceFile = $file === false ? throw new \RuntimeException('No temp file for the source database.') : $file;
        }

        return $this->sourceFile;
    }

    protected function tearDown(): void
    {
        if ($this->sourceFile !== null && file_exists($this->sourceFile)) {
            unlink($this->sourceFile);
        }

        parent::tearDown();
    }

    /**
     * The predecessor's deletions, as the source records them.
     *
     * The source carries the predecessor's table and column names, which is the
     * whole point - the repairer reads `deleted_at` there because the
     * destination no longer knows which of its rows were superseded.
     *
     * @param  list<string>  $deletedInvoiceKeys
     * @param  list<string>  $deletedLineKeys
     */
    private function sourceRows(array $deletedInvoiceKeys = [], array $deletedLineKeys = []): void
    {
        $source = DB::build(['driver' => 'sqlite', 'database' => $this->sourceFile(), 'prefix' => '', 'name' => 'source_fixture']);

        foreach ([['client_invoices', 'client_invoice_id', $deletedInvoiceKeys], ['client_invoice_lines', 'client_invoice_line_id', $deletedLineKeys]] as [$table, $key, $keys]) {
            $source->statement("CREATE TABLE IF NOT EXISTS {$table} ({$key} INTEGER, deleted_at TEXT NULL)");
            $source->table($table)->delete();

            foreach ($keys as $k) {
                $source->table($table)->insert([$key => (int) $k, 'deleted_at' => '2026-01-01 00:00:00']);
            }
        }
    }

    private function workspace(string $slug = 'synthetic-repair'): Workspace
    {
        return Workspace::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
    }

    private function company(Workspace $workspace): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Repair Client',
            'slug' => 'synthetic-repair-client-'.$workspace->id,
        ]);
    }

    private function invoice(Workspace $workspace, string $number, int $subtotal, ?string $sourceKey = null): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $this->company($workspace)->id,
            'invoice_number' => $number,
            'status' => 'draft',
            'currency' => 'USD',
            'subtotal_amount' => $subtotal,
            'total_amount' => $subtotal,
        ]);

        if ($sourceKey !== null) {
            $this->ledger('client_invoices', $sourceKey, $invoice->public_id, (int) $workspace->id);
        }

        return $invoice;
    }

    private function line(Workspace $workspace, ClientInvoice $invoice, int $total, string $sourceKey): int
    {
        $publicId = (string) Str::uuid();
        $id = DB::table('client_invoice_lines')->insertGetId([
            'public_id' => $publicId,
            'workspace_id' => $workspace->id,
            'client_invoice_id' => $invoice->id,
            'type' => 'retainer',
            'description' => 'Synthetic retainer',
            'quantity' => 1,
            'unit_amount' => $total,
            'total_amount' => $total,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ledger('client_invoice_lines', $sourceKey, $publicId, (int) $workspace->id);

        return $id;
    }

    private function ledger(string $table, string $sourceKey, string $targetPublicId, int $workspaceId): void
    {
        DB::table('external_import_items')->insert([
            'external_import_run_id' => $this->importRun($workspaceId),
            'source_connection' => 'external_probe',
            'source_identity_hash' => str_repeat('a', 64),
            'source_table' => $table,
            'source_key' => $sourceKey,
            'target_type' => $table === 'client_invoices' ? 'invoice' : 'invoice_line',
            'target_public_id' => $targetPublicId,
            'source_fingerprint' => str_repeat('b', 64),
            'status' => 'imported',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @var array<int, int> */
    private array $runIds = [];

    private function importRun(int $workspaceId): int
    {
        return $this->runIds[$workspaceId] ??= DB::table('external_import_runs')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'source_connection' => 'external_probe',
            'source_identity_hash' => str_repeat('a', 64),
            'mode' => 'import',
            'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
