<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\ExternalImport\SourceGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a declared restore has to prove before it may repair anything.
 *
 * The whole-row fingerprint taken at import cannot tell "someone renumbered the
 * invoices" from "the money is different" - it rejects both identically, so
 * against a source that kept being used it rejects everything and says nothing
 * about why. Against the real restore it refused 1052 of 1372 rows, and the
 * actual difference turned out to be an invoice renumbering and some
 * soft-deletes, with every money column and every date intact.
 *
 * So a declared restore is verified by comparing it against what the importer
 * wrote, column by column. Differences are named and counted, and the operator
 * has to accept each one by name. Accepting a renumbering must not quietly
 * accept a changed total, which is the property the last test pins.
 */
final class RestoreAgreementTest extends TestCase
{
    use RefreshDatabase;

    private string $importedPath;

    private string $restoredPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importedPath = tempnam(sys_get_temp_dir(), 'svc-imported-');
        $this->restoredPath = tempnam(sys_get_temp_dir(), 'svc-restored-');
    }

    protected function tearDown(): void
    {
        foreach ([$this->importedPath, $this->restoredPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_a_restore_that_still_agrees_is_accepted(): void
    {
        $this->scenario();

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->expectsOutputToContain('matches what was imported')
            ->assertSuccessful();
    }

    public function test_a_changed_column_is_named_and_refused(): void
    {
        $this->scenario(['invoice_number' => 'RENUMBERED-1']);

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->expectsOutputToContain('invoice_number')
            ->assertFailed();
    }

    public function test_a_named_difference_is_accepted_and_the_repair_proceeds(): void
    {
        $this->scenario(['invoice_number' => 'RENUMBERED-1']);

        $this->artisan('svc:billing:backfill-ledger', [
            '--workspace' => $this->workspacePublicId(),
            '--apply' => true,
            '--accept-drift' => 'client_invoices.invoice_number',
        ])->assertSuccessful();

        $this->assertSame('cadence_period', ClientInvoice::query()->sole()->invoice_kind);
    }

    /**
     * The one that matters. Accepting the renumbering must not carry the money
     * along with it.
     */
    public function test_accepting_one_column_does_not_accept_a_changed_total(): void
    {
        $this->scenario(['invoice_number' => 'RENUMBERED-1', 'invoice_total' => '9999.00']);

        $this->artisan('svc:billing:backfill-ledger', [
            '--workspace' => $this->workspacePublicId(),
            '--apply' => true,
            '--accept-drift' => 'client_invoices.invoice_number',
        ])->assertFailed();

        $this->assertNull(
            ClientInvoice::query()->sole()->invoice_kind,
            'Nothing may be written while an unexplained difference stands',
        );
    }

    /**
     * The source soft-deletes, and the importer never ledgers a row the source
     * has thrown away - so a faithful restore always holds rows the ledger does
     * not name. Reading that as a broken declaration failed every healthy run:
     * against the migrated data it refused 997 rows that were exactly the
     * deleted ones.
     */
    public function test_a_source_row_the_ledger_never_recorded_does_not_fail_the_restore(): void
    {
        $this->scenario(unledgeredSourceInvoices: [[
            'client_invoice_id' => 502,
            'invoice_number' => 'SVC-00002',
            'status' => 'draft',
            'invoice_total' => '250.00',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'invoice_kind' => 'cadence_period',
            'cycle_start' => '2026-04-01',
            'cycle_end' => '2026-04-30',
        ]]);

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->expectsOutputToContain('1 source rows had no ledger mapping')
            ->assertSuccessful();

        // Reported, and the repair still ran.
        $this->assertSame('cadence_period', ClientInvoice::query()->where('invoice_number', 'SVC-00001')->sole()->invoice_kind);
    }

    /**
     * The direction that does matter, on a table the column comparison has
     * nothing to say about. An agreement the restore has lost is a ledger row
     * this command would otherwise quietly decline to repair.
     */
    public function test_a_ledger_row_absent_from_an_uncompared_table_is_refused(): void
    {
        $this->scenario(ledgerAnAgreementTheSourceLacks: true);

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->expectsOutputToContain('client_agreements')
            ->expectsOutputToContain('missing rows the ledger says were imported')
            ->assertFailed();

        $this->assertNull(
            ClientInvoice::query()->sole()->invoice_kind,
            'Nothing may be written from a restore that has lost rows the ledger recorded',
        );
    }

    /**
     * A skipped table is outside restore verification too, not only the repair.
     *
     * The sibling test above proves a ledger row missing from an uncompared
     * table refuses the whole run. `--skip-table` has to reach that stage as
     * well: its promise is that a skipped table can neither be repaired nor
     * fail the run, and verification is one of the two places a table fails it.
     *
     * Narrowing only the repair loop was the first version of the option, and
     * it kept half the promise - the repairs skipped the table, and this stage
     * still rolled everything back because of it.
     */
    public function test_a_skipped_table_is_outside_restore_verification(): void
    {
        $this->scenario(ledgerAnAgreementTheSourceLacks: true);

        $this->artisan('svc:billing:backfill-ledger', [
            '--workspace' => $this->workspacePublicId(),
            '--apply' => true,
            '--skip-table' => ['client_agreements'],
        ])->assertSuccessful();

        $this->assertSame(
            'cadence_period',
            ClientInvoice::query()->sole()->invoice_kind,
            'The tables that verified should still repair.',
        );
    }

    /**
     * @param  array<string, string>  $driftInRestore
     * @param  list<array<string, mixed>>  $unledgeredSourceInvoices
     */
    private function scenario(
        array $driftInRestore = [],
        array $unledgeredSourceInvoices = [],
        bool $ledgerAnAgreementTheSourceLacks = false,
    ): void {
        $imported = [
            'client_invoice_id' => 501,
            'invoice_number' => 'SVC-00001',
            'status' => 'paid',
            'invoice_total' => '1000.00',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'invoice_kind' => 'cadence_period',
            'cycle_start' => '2026-03-01',
            'cycle_end' => '2026-03-31',
        ];

        $this->writeSource($this->restoredPath, array_replace($imported, $driftInRestore), $unledgeredSourceInvoices);

        // The source now lives at a new path and says so.
        Config::set('external-import.sources.external', [
            'connection' => 'synthetic',
            'read_only' => true,
            'restore_of_database' => $this->importedPath,
            'config' => ['driver' => 'sqlite', 'database' => $this->restoredPath, 'prefix' => ''],
        ]);
        Config::set('database.connections.synthetic', [
            'driver' => 'sqlite', 'database' => $this->restoredPath, 'prefix' => '',
        ]);

        $workspace = Workspace::query()->create(['name' => 'Restore', 'slug' => 'restore']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => 'Restore Client', 'slug' => 'restore-client',
        ]);
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'invoice_number' => $imported['invoice_number'],
            'status' => $imported['status'],
            'currency' => 'USD',
            'service_period_start' => $imported['period_start'],
            'service_period_end' => $imported['period_end'],
            'subtotal_amount' => 100000,
            'total_amount' => 100000,
        ]);

        $runId = $this->runId($workspace->id);

        DB::table('external_import_items')->insert([
            'external_import_run_id' => $runId,
            'source_connection' => 'synthetic',
            'source_identity_hash' => app(SourceGuard::class)->resolve('external')['identity_hash'],
            'source_table' => 'client_invoices',
            'source_key' => '501',
            'target_type' => 'invoice',
            'target_public_id' => $invoice->public_id,
            'source_fingerprint' => 'irrelevant-for-a-declared-restore',
            'status' => 'imported',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $ledgerAnAgreementTheSourceLacks) {
            return;
        }

        // An agreement the ledger says was imported and the restore does not
        // hold. Nothing about it is compared column by column, so its absence
        // is only ever visible as absence.
        $agreementPublicId = (string) Str::uuid();
        DB::table('client_agreements')->insert([
            'public_id' => $agreementPublicId,
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Restore Agreement',
            'status' => 'active',
            'currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('external_import_items')->insert([
            'external_import_run_id' => $runId,
            'source_connection' => 'synthetic',
            'source_identity_hash' => app(SourceGuard::class)->resolve('external')['identity_hash'],
            'source_table' => 'client_agreements',
            'source_key' => '601',
            'target_type' => 'agreement',
            'target_public_id' => $agreementPublicId,
            'source_fingerprint' => 'irrelevant-for-a-declared-restore',
            'status' => 'imported',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $unledgered
     */
    private function writeSource(string $path, array $row, array $unledgered = []): void
    {
        Config::set('database.connections.builder', ['driver' => 'sqlite', 'database' => $path, 'prefix' => '']);
        $c = DB::connection('builder');
        $c->statement(
            'CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, invoice_number TEXT, status TEXT, '.
            'invoice_total TEXT, period_start TEXT, period_end TEXT, issue_date TEXT, due_date TEXT, notes TEXT, '.
            'invoice_kind TEXT, cycle_start TEXT, cycle_end TEXT, paid_date TEXT, retainer_hours_included TEXT, '.
            'hours_worked TEXT, rollover_hours_used TEXT, unused_hours_balance TEXT, negative_hours_balance TEXT, '.
            'hours_billed_at_rate TEXT, starting_unused_hours TEXT, starting_negative_hours TEXT)'
        );
        $c->table('client_invoices')->insert($row);

        foreach ($unledgered as $extra) {
            $c->table('client_invoices')->insert($extra);
        }

        // The backfill walks every table it repairs; the ones this scenario does
        // not exercise still have to exist to be read as empty.
        $c->statement('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, description TEXT, line_type TEXT, unit_price TEXT, line_total TEXT, sort_order INTEGER, line_date TEXT, hours TEXT, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER)');
        $c->statement('CREATE TABLE client_agreements (id INTEGER PRIMARY KEY, catch_up_threshold_hours TEXT, rollover_months INTEGER, initial_rollover_hours TEXT, bill_overage_interim INTEGER, first_cycle_proration TEXT, agreement_link TEXT)');
        $c->statement('CREATE TABLE client_tasks (id INTEGER PRIMARY KEY, milestone_price TEXT)');
        $c->statement('CREATE TABLE client_time_entries (id INTEGER PRIMARY KEY, job_type TEXT, date_worked TEXT, minutes_worked INTEGER, name TEXT, is_billable INTEGER, is_deferred_billing INTEGER)');

        DB::purge('builder');
    }

    private function runId(int $workspaceId): int
    {
        return (int) DB::table('external_import_runs')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'source_connection' => 'synthetic',
            'source_identity_hash' => app(SourceGuard::class)->resolve('external')['identity_hash'],
            'mode' => 'apply',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function workspacePublicId(): string
    {
        return (string) Workspace::query()->where('slug', 'restore')->value('public_id');
    }
}
