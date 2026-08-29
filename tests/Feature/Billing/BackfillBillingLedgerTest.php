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

    /**
     * A task already carrying its link has no hole to fill, so a source line
     * this workspace never imported is nothing to refuse over - and failing
     * would roll back every other row the repair had to offer.
     */
    public function test_an_unresolvable_source_link_is_ignored_when_the_task_is_already_linked(): void
    {
        [$invoice, $line, , $task] = $this->buildDestination();

        // Already decided here, by an earlier repair or by an operator.
        $task->forceFill(['client_invoice_line_id' => $line->id])->save();

        // And the source names a line this workspace has no mapping for.
        DB::table('external_import_items')
            ->where('source_table', 'client_invoice_lines')
            ->where('source_key', '901')
            ->delete();

        $this->artisan('svc:billing:backfill-ledger', [
            '--workspace' => $this->workspacePublicId(),
            '--apply' => true,
        ])->assertSuccessful();

        $task->refresh();
        $invoice->refresh();
        $this->assertSame($line->id, $task->client_invoice_line_id);
        // The rest of the repair still happened rather than being rolled back.
        $this->assertSame('10.0000', (string) $invoice->retainer_hours_included);
    }

    /**
     * A milestone line bills one deliverable and the schema now says so, so two
     * source tasks naming one line cannot both be applied. Deciding between
     * them is not a repair's business - but neither is failing over it, which
     * would roll back every other row.
     */
    public function test_two_source_tasks_claiming_one_line_are_reported_not_fatal(): void
    {
        [$invoice, , , $task] = $this->buildDestination();

        $other = ClientTask::query()->create([
            'workspace_id' => $invoice->workspace_id,
            'client_project_id' => $task->client_project_id,
            'title' => 'The other deliverable',
        ]);
        DB::connection('synthetic')->table('client_tasks')->insert(['id' => 702, 'milestone_price' => '250.00']);
        DB::connection('synthetic')->table('client_tasks')->where('id', 702)->update(['client_invoice_line_id' => 901]);
        DB::connection('synthetic')->table('client_tasks')->where('id', 701)->update(['client_invoice_line_id' => 901]);
        $this->ledger('client_tasks', '702', 'task', $other->public_id);

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->assertFailed();

        // Reported as unresolved rather than thrown, and neither task took it.
        $this->assertNull($task->refresh()->client_invoice_line_id);
        $this->assertNull($other->refresh()->client_invoice_line_id);
    }

    /**
     * A line already held here is not free just because the source says
     * nothing about it. An operator can have reconciled it by hand.
     */
    public function test_a_line_another_task_already_holds_here_is_reported_not_taken(): void
    {
        [$invoice, $line, , $task] = $this->buildDestination();

        $holder = ClientTask::query()->create([
            'workspace_id' => $invoice->workspace_id,
            'client_project_id' => $task->client_project_id,
            'title' => 'Reconciled by hand',
            'client_invoice_line_id' => $line->id,
        ]);

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->assertFailed();

        $this->assertNull($task->refresh()->client_invoice_line_id);
        $this->assertSame((int) $line->id, (int) $holder->refresh()->client_invoice_line_id);
    }

    /**
     * Two tasks from another tenant's onboarding arguing over a line this
     * ledger never mapped is not this repair's business, and counting them
     * unresolved rolls back a workspace that is fine. What makes an argument
     * ours is the line being ours, not the claimants being mapped.
     */
    public function test_contested_claims_outside_this_workspace_do_not_stop_the_repair(): void
    {
        $this->buildDestination();

        // Two source tasks the ledger never mapped, arguing over a line it
        // never mapped either. Both belong to somebody else's onboarding.
        foreach ([801, 802] as $key) {
            DB::connection('synthetic')->table('client_tasks')->insert(['id' => $key, 'milestone_price' => '100.00']);
            DB::connection('synthetic')->table('client_tasks')->where('id', $key)->update(['client_invoice_line_id' => 999]);
        }

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->assertSuccessful();

        $this->assertSame(1, ClientAgreement::query()->sole()->rollover_months);
    }

    /**
     * task_invoice_line_once is global, so a holder in another workspace
     * collides just the same - and the repair must still stop. It is the index
     * that says so here, not the conflict check: that check reads only this
     * workspace's tasks, and the write it guards fails on the constraint,
     * which applyRow() records as the same unresolved.
     */
    public function test_a_holder_in_another_workspace_is_seen_by_the_conflict_check(): void
    {
        [$invoice, $line, , $task] = $this->buildDestination();

        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $theirCompany = ClientCompany::query()->create([
            'workspace_id' => $elsewhere->id, 'name' => 'Their Business', 'slug' => 'their-business',
        ]);
        $theirProject = ClientProject::query()->create([
            'workspace_id' => $elsewhere->id, 'client_company_id' => $theirCompany->id, 'name' => 'Their Project',
        ]);
        $theirs = ClientTask::query()->create([
            'workspace_id' => $elsewhere->id, 'client_project_id' => $theirProject->id,
            'title' => 'Malformed holder', 'client_invoice_line_id' => $line->id,
        ]);

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->assertFailed();

        $this->assertNull($task->refresh()->client_invoice_line_id);
        $this->assertSame((int) $line->id, (int) $theirs->refresh()->client_invoice_line_id);
        $this->assertSame($invoice->workspace_id, $task->workspace_id);
    }

    /**
     * A task that already has a link has no hole to fill, so there is no
     * conflicting write to head off - and refusing would cost the milestone
     * price the repair could still restore.
     */
    public function test_a_task_already_linked_here_is_repaired_despite_a_contested_source_line(): void
    {
        [$invoice, $line, , $task] = $this->buildDestination();

        $other = ClientInvoiceLine::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_invoice_id' => $invoice->id,
            'type' => 'adjustment', 'description' => 'Corrected by hand', 'quantity' => '1.0000',
            'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'sort_order' => 7,
        ]);
        $task->forceFill(['client_invoice_line_id' => $other->id])->save();

        // Somebody else holds the line the source names.
        ClientTask::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_project_id' => $task->client_project_id,
            'title' => 'Holds the source line', 'client_invoice_line_id' => $line->id,
        ]);

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->assertSuccessful();

        // The correction stands and the price was still restored.
        $this->assertSame((int) $other->id, (int) $task->refresh()->client_invoice_line_id);
        $this->assertSame(18750, $task->milestone_price_amount);
    }

    /**
     * A task deleted before the original import has no ledger mapping and
     * still argues over the line. Scoping the prepass by which claimants were
     * mapped missed exactly that, and the repair walked into the constraint.
     */
    public function test_an_unmapped_task_claiming_our_line_still_contests_it(): void
    {
        [, , , $task] = $this->buildDestination();

        // Never imported - deleted before the original run - but it still
        // names the line the ledger did map.
        DB::connection('synthetic')->table('client_tasks')->insert(['id' => 803, 'milestone_price' => '100.00']);
        DB::connection('synthetic')->table('client_tasks')->where('id', 803)->update(['client_invoice_line_id' => 901]);

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->assertFailed();

        $this->assertNull($task->refresh()->client_invoice_line_id);
    }

    /**
     * A task already carrying an operator's correction is not competing for
     * anything, so a contested source line must not cost it the milestone
     * price the repair could still restore.
     */
    public function test_a_corrected_task_is_repaired_despite_a_contested_source_line(): void
    {
        [$invoice, , , $task] = $this->buildDestination();

        $corrected = ClientInvoiceLine::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_invoice_id' => $invoice->id,
            'type' => 'adjustment', 'description' => 'Corrected by hand', 'quantity' => '1.0000',
            'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'sort_order' => 8,
        ]);
        $task->forceFill(['client_invoice_line_id' => $corrected->id])->save();

        // Two source tasks argue over the line the source names for this one.
        DB::connection('synthetic')->table('client_tasks')->insert(['id' => 804, 'milestone_price' => '100.00']);
        DB::connection('synthetic')->table('client_tasks')->where('id', 804)->update(['client_invoice_line_id' => 901]);

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->assertSuccessful();

        $this->assertSame((int) $corrected->id, (int) $task->refresh()->client_invoice_line_id);
        $this->assertSame(18750, $task->milestone_price_amount);
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
