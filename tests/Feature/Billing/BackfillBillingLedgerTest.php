<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\Workspace;
use App\Services\ExternalImport\Fingerprint;
use App\Services\ExternalImport\SourceGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BackfillBillingLedgerTest extends TestCase
{
    use RefreshDatabase;

    private string $sourcePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourcePath = tempnam(sys_get_temp_dir(), 'svc-ledger-');
        Config::set('external-import.sources.external', [
            'connection' => 'synthetic',
            'read_only' => true,
            'config' => ['driver' => 'sqlite', 'database' => $this->sourcePath, 'prefix' => ''],
        ]);
        Config::set('database.connections.synthetic', [
            'driver' => 'sqlite', 'database' => $this->sourcePath, 'prefix' => '',
        ]);
        $this->buildSource();
    }

    protected function tearDown(): void
    {
        if (is_file($this->sourcePath)) {
            unlink($this->sourcePath);
        }
        parent::tearDown();
    }

    public function test_it_restores_dropped_columns_and_is_safe_to_repeat(): void
    {
        [$invoice, $line, $agreement, $task] = $this->buildDestination();

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])->assertSuccessful();

        $invoice->refresh();
        $this->assertSame('cadence_period', $invoice->invoice_kind);
        $this->assertSame('2026-03-01', $invoice->cycle_start?->toDateString());
        $this->assertSame('2026-03-31', $invoice->cycle_end?->toDateString());
        $this->assertSame('2026-04-09', $invoice->paid_on?->toDateString());
        $this->assertSame('10.0000', (string) $invoice->retainer_hours_included);
        $this->assertSame('12.5000', (string) $invoice->hours_worked);
        $this->assertSame('1.5000', (string) $invoice->hours_billed_at_rate);

        $line->refresh();
        // 1.7500h is not a whole number of minutes; it must survive exactly.
        $this->assertSame('1.7500', (string) $line->hours);
        $this->assertSame('2026-03-14', $line->line_date?->toDateString());
        $this->assertSame($agreement->id, $line->client_agreement_id);

        $agreement->refresh();
        $this->assertSame(60, $agreement->catch_up_threshold_minutes);
        $this->assertSame(1, $agreement->rollover_months);
        $this->assertSame('prorate_hours', $agreement->first_cycle_proration);
        $this->assertTrue($agreement->bill_overage_interim);

        $task->refresh();
        // Source decimal currency becomes integer minor units.
        $this->assertSame(18750, $task->milestone_price_amount);
        // The line that billed this milestone. A task repaired without it reads
        // as unbilled, and the next generation run charges for it again.
        $this->assertSame($line->id, $task->client_invoice_line_id);

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])->assertSuccessful();
        $invoice->refresh();
        $this->assertSame('10.0000', (string) $invoice->retainer_hours_included);
    }

    /**
     * The repair now writes a link between two tenant-owned financial rows, so
     * the mapping it resolves has to name a row this workspace owns. A source
     * key is the same integer in every tenant imported from one predecessor,
     * and the foreign key here is not workspace-composite.
     */
    public function test_a_backfilled_milestone_link_never_resolves_another_workspaces_line(): void
    {
        [, , , $task] = $this->buildDestination();

        // An invoice line that genuinely belongs to somebody else, with source
        // line 901 mapped to it under this workspace's own run - so only the
        // destination-side ownership check stands between the task and it.
        $other = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $other->id, 'name' => 'Their Business', 'slug' => 'their-business',
        ]);
        $otherInvoice = ClientInvoice::query()->create([
            'workspace_id' => $other->id, 'client_company_id' => $otherCompany->id,
            'invoice_number' => 'THEIRS-1', 'status' => 'draft', 'currency' => 'USD',
            'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        $otherLine = ClientInvoiceLine::query()->create([
            'workspace_id' => $other->id, 'client_invoice_id' => $otherInvoice->id,
            'type' => 'milestone', 'description' => 'Theirs', 'quantity' => '1.0000',
            'unit_amount' => 100000, 'tax_amount' => 0, 'total_amount' => 100000, 'sort_order' => 0,
        ]);

        DB::table('external_import_items')
            ->where('source_table', 'client_invoice_lines')
            ->where('source_key', '901')
            ->update(['target_public_id' => $otherLine->public_id]);

        // Refused, and loudly. The source says this milestone was billed and
        // the only candidate belongs to somebody else, so there is no answer
        // here - and leaving it null quietly would let the next generation run
        // charge for the milestone again.
        $this->artisan('svc:billing:backfill-ledger', [
            '--workspace' => $this->workspacePublicId(),
            '--apply' => true,
        ])->assertFailed();

        $task->refresh();
        $this->assertNull($task->client_invoice_line_id);
    }

    /**
     * The inverse split. The resolved invoice line belongs to this workspace
     * and only the task is foreign, so the task-side boundary is the only thing
     * that can refuse the link.
     */
    public function test_a_backfilled_milestone_link_never_resolves_another_workspaces_task(): void
    {
        [, , , $task] = $this->buildDestination();

        $other = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $other->id, 'name' => 'Their Business', 'slug' => 'their-business',
        ]);
        $otherProject = ClientProject::query()->create([
            'workspace_id' => $other->id, 'client_company_id' => $otherCompany->id, 'name' => 'Their Project',
        ]);
        $otherTask = ClientTask::query()->create([
            'workspace_id' => $other->id, 'client_project_id' => $otherProject->id, 'title' => 'Their Milestone',
        ]);

        // Source task 701 now names a task owned by somebody else, while source
        // line 901 still resolves to this workspace's own line.
        DB::table('external_import_items')
            ->where('source_table', 'client_tasks')
            ->where('source_key', '701')
            ->update(['target_public_id' => $otherTask->public_id]);

        // Succeeds rather than fails, and that is right: a source row imported
        // into another tenant is genuinely not this repair's business. What
        // must not happen is the link being written into it.
        $this->artisan('svc:billing:backfill-ledger', [
            '--workspace' => $this->workspacePublicId(),
            '--apply' => true,
        ])->assertSuccessful();

        $task->refresh();
        $otherTask->refresh();
        $this->assertNull($task->client_invoice_line_id);
        $this->assertNull($otherTask->client_invoice_line_id);
    }

    public function test_it_writes_nothing_without_apply(): void
    {
        [$invoice] = $this->buildDestination();

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId()])->assertSuccessful();

        $this->assertNull($invoice->refresh()->invoice_kind);
    }

    public function test_it_never_overwrites_a_value_svc_already_holds(): void
    {
        [$invoice] = $this->buildDestination();
        $invoice->forceFill(['invoice_kind' => 'corrected_by_hand'])->save();

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])->assertSuccessful();

        $this->assertSame('corrected_by_hand', $invoice->refresh()->invoice_kind);
    }

    public function test_it_refuses_rows_that_changed_since_they_were_imported(): void
    {
        [$invoice] = $this->buildDestination();

        // A delta pass moved the source row on; splicing its current values onto a
        // record built from the old snapshot would mix two snapshots together.
        DB::connection('synthetic')->table('client_invoices')
            ->where('client_invoice_id', 501)
            ->update(['hours_worked' => '99.00']);

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])->assertFailed();

        $this->assertNull($invoice->refresh()->invoice_kind);
    }

    public function test_it_ignores_ledger_rows_belonging_to_another_source(): void
    {
        [$invoice] = $this->buildDestination();

        DB::table('external_import_items')
            ->where('source_table', 'client_invoices')
            ->update(['source_identity_hash' => str_repeat('f', 64)]);

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])->assertSuccessful();

        $this->assertNull($invoice->refresh()->invoice_kind);
    }

    public function test_it_refuses_a_source_that_is_not_declared_read_only(): void
    {
        Config::set('external-import.sources.external.read_only', false);

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])->assertFailed();
    }

    private function buildSource(): void
    {
        $source = DB::connection('synthetic');
        $source->statement('CREATE TABLE client_invoices (client_invoice_id INTEGER PRIMARY KEY, invoice_kind TEXT, cycle_start TEXT, cycle_end TEXT, paid_date TEXT, retainer_hours_included TEXT, hours_worked TEXT, rollover_hours_used TEXT, unused_hours_balance TEXT, negative_hours_balance TEXT, hours_billed_at_rate TEXT)');
        $source->statement('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, line_date TEXT, hours TEXT, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER)');
        $source->statement('CREATE TABLE client_agreements (id INTEGER PRIMARY KEY, catch_up_threshold_hours TEXT, rollover_months INTEGER, initial_rollover_hours TEXT, bill_overage_interim INTEGER, first_cycle_proration TEXT, agreement_link TEXT)');
        $source->statement('CREATE TABLE client_tasks (id INTEGER PRIMARY KEY, milestone_price TEXT, client_invoice_line_id INTEGER)');
        $source->statement('CREATE TABLE client_time_entries (id INTEGER PRIMARY KEY, job_type TEXT)');

        $source->table('client_invoices')->insert([
            'client_invoice_id' => 501, 'invoice_kind' => 'cadence_period',
            'cycle_start' => '2026-03-01', 'cycle_end' => '2026-03-31', 'paid_date' => '2026-04-09 12:00:00',
            'retainer_hours_included' => '10.00', 'hours_worked' => '12.50', 'rollover_hours_used' => '0.00',
            'unused_hours_balance' => '0.00', 'negative_hours_balance' => '0.00', 'hours_billed_at_rate' => '1.50',
        ]);
        $source->table('client_invoice_lines')->insert([
            'client_invoice_line_id' => 901, 'line_date' => '2026-03-14',
            'hours' => '1.7500', 'client_agreement_id' => 301, 'client_agreement_recurring_item_id' => null,
        ]);
        $source->table('client_agreements')->insert([
            'id' => 301, 'catch_up_threshold_hours' => '1.00', 'rollover_months' => 1,
            'initial_rollover_hours' => '0.0000', 'bill_overage_interim' => 1,
            'first_cycle_proration' => 'prorate_hours', 'agreement_link' => 'https://example.com/agreement',
        ]);
        $source->table('client_tasks')->insert(['id' => 701, 'milestone_price' => '187.50', 'client_invoice_line_id' => 901]);
        $source->table('client_time_entries')->insert(['id' => 601, 'job_type' => 'Support']);
    }

    /**
     * The command writes billing data, so it must name the tenant it is
     * repairing. Without this it silently walked every workspace imported from
     * the same source.
     */
    public function test_it_refuses_to_run_without_a_workspace(): void
    {
        $this->buildDestination();

        $this->artisan('svc:billing:backfill-ledger')->assertFailed();
    }

    public function test_it_refuses_an_unknown_workspace(): void
    {
        $this->buildDestination();

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => 'no-such-workspace', '--apply' => true])->assertFailed();
    }

    private function workspacePublicId(): string
    {
        return (string) Workspace::query()->where('slug', 'ledger')->value('public_id');
    }

    /** @return array{ClientInvoice, ClientInvoiceLine, ClientAgreement, ClientTask} */
    private function buildDestination(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Ledger', 'slug' => 'ledger']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => 'Ledger Client', 'slug' => 'ledger-client',
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Ledger Project',
        ]);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'title' => 'Retainer', 'status' => 'active', 'currency' => 'USD',
        ]);
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'invoice_number' => 'SVC-00001', 'currency' => 'USD', 'status' => 'paid',
        ]);
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id,
            'type' => 'retainer', 'description' => 'Retainer', 'quantity' => '1.0000',
            'unit_amount' => 100000, 'tax_amount' => 0, 'total_amount' => 100000, 'sort_order' => 0,
        ]);
        $task = ClientTask::query()->create([
            'workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Milestone',
        ]);

        $this->ledger('client_invoices', '501', 'invoice', $invoice->public_id);
        $this->ledger('client_invoice_lines', '901', 'invoice_line', $line->public_id);
        $this->ledger('client_agreements', '301', 'agreement', $agreement->public_id);
        $this->ledger('client_tasks', '701', 'task', $task->public_id);

        return [$invoice, $line, $agreement, $task];
    }

    private function ledger(string $sourceTable, string $sourceKey, string $targetType, string $publicId): void
    {
        DB::table('external_import_items')->insert([
            'external_import_run_id' => $this->runId(),
            'source_connection' => 'synthetic',
            'source_identity_hash' => $this->identityHash(),
            'source_table' => $sourceTable,
            'source_key' => $sourceKey,
            'target_type' => $targetType,
            'target_public_id' => $publicId,
            'source_fingerprint' => $this->sourceFingerprint($sourceTable, $sourceKey),
            'status' => 'imported',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The identity the guard derives for the configured source. */
    private function identityHash(): string
    {
        return (string) app(SourceGuard::class)->resolve('external')['identity_hash'];
    }

    /** The fingerprint the importer would have recorded for this source row. */
    private function sourceFingerprint(string $sourceTable, string $sourceKey): string
    {
        $key = match ($sourceTable) {
            'client_invoices' => 'client_invoice_id',
            'client_invoice_lines' => 'client_invoice_line_id',
            default => 'id',
        };

        $row = DB::connection('synthetic')->table($sourceTable)->where($key, $sourceKey)->firstOrFail();

        return Fingerprint::row((array) $row);
    }

    private ?int $runId = null;

    private function runId(): int
    {
        if ($this->runId === null) {
            $this->runId = (int) DB::table('external_import_runs')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'workspace_id' => Workspace::query()->value('id'),
                'source_connection' => 'synthetic',
                'source_identity_hash' => str_repeat('a', 64),
                'mode' => 'apply',
                'status' => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->runId;
    }
}
