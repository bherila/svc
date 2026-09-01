<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
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
     * A skipped table is left entirely alone, and the rest still repair.
     *
     * Not a waiver, and the distinction is the whole point.
     * `--accept-drift` says "this difference is acceptable";
     * `--skip-table` says "do not read this table". Nothing about the skipped
     * rows is declared trustworthy - they are not consulted, nothing is written
     * from them, and whatever they were going to fill stays empty with the
     * source still holding it.
     *
     * Reached from a real run: the fingerprint guard refused two milestone
     * tasks whose source rows had moved since import, and that failed a run
     * which would otherwise have repaired 1364 rows across four other tables.
     * The tempting move there is to waive the guard. This leaves it intact
     * everywhere it still runs, and leaves a hole an operator can see rather
     * than a check they turned off.
     */
    public function test_a_skipped_table_is_left_alone_while_the_others_repair(): void
    {
        [$invoice, $line, $agreement, $task] = $this->buildDestination();

        $this->artisan('svc:billing:backfill-ledger', [
            '--workspace' => $this->workspacePublicId(),
            '--apply' => true,
            '--skip-table' => ['client_tasks'],
        ])->assertSuccessful();

        // The four tables that were read are repaired as usual.
        $this->assertSame('1.5000', (string) $invoice->refresh()->hours_billed_at_rate);
        $this->assertSame('1.7500', (string) $line->refresh()->hours);
        $this->assertSame(60, $agreement->refresh()->catch_up_threshold_minutes);

        // The skipped one is untouched - both the milestone price and, more to
        // the point, the financial link the guard exists to protect.
        $task->refresh();
        $this->assertNull($task->milestone_price_amount);
        $this->assertNull($task->client_invoice_line_id);

        // And it can still be repaired later, once its rows are reconciled.
        $this->artisan('svc:billing:backfill-ledger', [
            '--workspace' => $this->workspacePublicId(),
            '--apply' => true,
        ])->assertSuccessful();
        $this->assertSame(18750, $task->refresh()->milestone_price_amount);
    }

    /**
     * A preflight failure in a skipped table does not roll back the rest.
     *
     * The first version of `--skip-table` only narrowed the repair loop, so
     * restore verification and ledger resolution still walked every table and a
     * problem in the skipped one failed the whole run anyway - the option kept
     * half its promise. This is the half that was missing.
     *
     * A hard-deleted destination row is the same shape as the fingerprint
     * refusal that motivated the option: an unrepairable row in one table
     * stopping four other tables from being repaired.
     */
    public function test_a_preflight_failure_in_a_skipped_table_does_not_stop_the_rest(): void
    {
        [$invoice, , , $task] = $this->buildDestination();

        // Gone from the destination entirely, which is fatal for this table.
        DB::table('client_tasks')->where('id', $task->id)->delete();

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->assertFailed();
        $this->assertNull($invoice->refresh()->hours_billed_at_rate, 'The failed run must leave everything alone.');

        // Skipping that table takes it out of the preflight as well, so the
        // other four repair.
        $this->artisan('svc:billing:backfill-ledger', [
            '--workspace' => $this->workspacePublicId(),
            '--apply' => true,
            '--skip-table' => ['client_tasks'],
        ])->assertSuccessful();

        $this->assertSame('1.5000', (string) $invoice->refresh()->hours_billed_at_rate);
    }

    public function test_an_unknown_table_name_is_refused_rather_than_ignored(): void
    {
        $this->buildDestination();

        $this->artisan('svc:billing:backfill-ledger', [
            '--workspace' => $this->workspacePublicId(),
            '--apply' => true,
            '--skip-table' => ['client_taskz'],
        ])
            ->expectsOutputToContain('There is no source table named client_taskz')
            ->assertFailed();

        // A typo must not silently read everything, which is how a skip option
        // quietly stops skipping.
        $this->assertNull(ClientTask::query()->sole()->milestone_price_amount);
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

        // This used to succeed, on the reasoning that a row imported into
        // another tenant is not this repair's business. The ledger rows it
        // walks all belong to runs in this workspace, though, so one of them
        // naming somebody else's row is this workspace's ledger being wrong
        // rather than somebody else's data being irrelevant - and telling the
        // two apart would mean reading a row this command has no business
        // reading. It refuses, and the property this test exists for is
        // unchanged: no link is written into either.
        $this->artisan('svc:billing:backfill-ledger', [
            '--workspace' => $this->workspacePublicId(),
            '--apply' => true,
        ])->assertFailed();

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
     * A ledger row saying "imported" whose destination row is gone reaches the
     * backfill as an unmatched source key, indistinguishable from a source row
     * the ledger never recorded. Nothing in SVC deletes an imported invoice, so
     * one that no longer resolves is lost data, and repairing the rest would
     * report a clean run over a ledger that is not.
     */
    public function test_a_ledger_row_whose_destination_is_gone_stops_the_repair(): void
    {
        [$invoice] = $this->buildDestination();
        DB::table('client_invoices')->where('id', $invoice->id)->delete();

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->expectsOutputToContain('this workspace cannot follow to a destination')
            ->assertFailed();

        $this->assertNull(ClientAgreement::query()->sole()->rollover_months);
    }

    /**
     * Editing a draft invoice deletes every one of its lines and writes fresh
     * ones, so a ledgered line that no longer resolves is the system working -
     * as long as none of that invoice's other lines survived the edit.
     */
    public function test_an_invoice_whose_lines_were_all_rewritten_is_reported_and_not_fatal(): void
    {
        [$invoice] = $this->buildDestination();

        // A second invoice with one imported line, so removing it leaves no
        // surviving sibling: the shape an edit actually produces.
        DB::connection('synthetic')->table('client_invoices')->insert([
            'client_invoice_id' => 502, 'invoice_kind' => 'cadence_period',
            'cycle_start' => '2026-04-01', 'cycle_end' => '2026-04-30', 'paid_date' => null,
            'retainer_hours_included' => '10.00', 'hours_worked' => '0.00', 'rollover_hours_used' => '0.00',
            'unused_hours_balance' => '0.00', 'negative_hours_balance' => '0.00', 'hours_billed_at_rate' => '0.00',
        ]);
        DB::connection('synthetic')->table('client_invoice_lines')->insert([
            'client_invoice_line_id' => 902, 'client_invoice_id' => 502, 'line_type' => 'retainer', 'line_date' => '2026-04-15',
            'hours' => '0.0000', 'client_agreement_id' => 301, 'client_agreement_recurring_item_id' => null,
        ]);

        $second = ClientInvoice::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_company_id' => $invoice->client_company_id,
            'invoice_number' => 'SVC-00002', 'currency' => 'USD', 'status' => 'draft',
        ]);
        $edited = ClientInvoiceLine::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_invoice_id' => $second->id,
            'type' => 'adjustment', 'description' => 'Edited away', 'quantity' => '1.0000',
            'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'sort_order' => 0,
        ]);
        $this->ledger('client_invoices', '502', 'invoice', $second->public_id);
        $this->ledger('client_invoice_lines', '902', 'invoice_line', $edited->public_id);

        DB::table('client_invoice_lines')->where('id', $edited->id)->delete();

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->expectsOutputToContain('removable in ordinary use')
            ->assertSuccessful();

        $this->assertSame(1, ClientAgreement::query()->sole()->rollover_months);
    }

    /**
     * The waiver has to be earned. An edit takes an invoice's lines all
     * together, so one line gone while its siblings still resolve is not an
     * edit - it is a mapping that has stopped meaning anything, and the table
     * it is on does not excuse it.
     */
    public function test_a_lost_line_beside_a_surviving_sibling_is_not_excused_by_its_table(): void
    {
        [$invoice] = $this->buildDestination();

        // On the same invoice as line 901, which still resolves.
        DB::connection('synthetic')->table('client_invoice_lines')->insert([
            'client_invoice_line_id' => 903, 'client_invoice_id' => 501, 'line_type' => 'retainer', 'line_date' => '2026-03-16',
            'hours' => '0.0000', 'client_agreement_id' => 301, 'client_agreement_recurring_item_id' => null,
        ]);
        $sibling = ClientInvoiceLine::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_invoice_id' => $invoice->id,
            'type' => 'adjustment', 'description' => 'Sibling', 'quantity' => '1.0000',
            'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'sort_order' => 1,
        ]);
        $this->ledger('client_invoice_lines', '903', 'invoice_line', $sibling->public_id);

        DB::table('client_invoice_lines')->where('id', $sibling->id)->delete();

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->expectsOutputToContain('not accounted for by an ordinary edit')
            ->assertFailed();

        $this->assertNull(ClientAgreement::query()->sole()->rollover_months);
    }

    /**
     * Regeneration comes in two shapes. An edit rewrites every line, but
     * InvoiceLineComposer::resetSystemGeneratedLines() replaces only the
     * generated ones and leaves an operator's adjustment standing - so a
     * surviving adjustment disproves nothing about a generated line that went.
     */
    public function test_an_adjustment_left_standing_does_not_disprove_a_regenerated_line(): void
    {
        [$invoice] = $this->buildDestination();

        // Its own invoice, so nothing here touches the milestone link.
        DB::connection('synthetic')->table('client_invoices')->insert([
            'client_invoice_id' => 503, 'invoice_kind' => 'cadence_period',
            'cycle_start' => '2026-05-01', 'cycle_end' => '2026-05-31', 'paid_date' => null,
            'retainer_hours_included' => '10.00', 'hours_worked' => '0.00', 'rollover_hours_used' => '0.00',
            'unused_hours_balance' => '0.00', 'negative_hours_balance' => '0.00', 'hours_billed_at_rate' => '0.00',
        ]);
        foreach ([['906', 'retainer', '2026-05-10'], ['907', 'adjustment', '2026-05-11']] as [$key, $type, $date]) {
            DB::connection('synthetic')->table('client_invoice_lines')->insert([
                'client_invoice_line_id' => (int) $key, 'client_invoice_id' => 503, 'line_type' => $type,
                'line_date' => $date, 'hours' => '0.0000', 'client_agreement_id' => 301,
                'client_agreement_recurring_item_id' => null,
            ]);
        }

        $third = ClientInvoice::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_company_id' => $invoice->client_company_id,
            'invoice_number' => 'SVC-00003', 'currency' => 'USD', 'status' => 'draft',
        ]);
        $this->ledger('client_invoices', '503', 'invoice', $third->public_id);

        $lines = [];
        foreach ([['906', 'retainer', 'Regenerated away'], ['907', 'adjustment', 'Left standing']] as [$key, $type, $description]) {
            $lines[$key] = ClientInvoiceLine::query()->create([
                'workspace_id' => $invoice->workspace_id, 'client_invoice_id' => $third->id,
                'type' => $type, 'description' => $description, 'quantity' => '1.0000',
                'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'sort_order' => 0,
            ]);
            $this->ledger('client_invoice_lines', $key, 'invoice_line', $lines[$key]->public_id);
        }

        // Only the generated line goes; the adjustment stays, as it does.
        DB::table('client_invoice_lines')->where('id', $lines['906']->id)->delete();

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->expectsOutputToContain('removable in ordinary use')
            ->assertSuccessful();

        $this->assertSame(1, ClientAgreement::query()->sole()->rollover_months);
    }

    /**
     * If the source has no such line either, there is no parent to reason from.
     * No evidence is not evidence of an edit, and the table's waiver is not a
     * substitute for the evidence it stands on.
     */
    public function test_a_lost_line_the_source_does_not_have_is_not_excused(): void
    {
        [$invoice] = $this->buildDestination();

        $orphan = ClientInvoiceLine::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_invoice_id' => $invoice->id,
            'type' => 'retainer', 'description' => 'Named by the ledger alone', 'quantity' => '1.0000',
            'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'sort_order' => 4,
        ]);

        // Inserted directly: the ledger helper fingerprints the source row, and
        // the point of this case is that there is no source row.
        DB::table('external_import_items')->insert([
            'external_import_run_id' => $this->runId(),
            'source_connection' => 'synthetic',
            'source_identity_hash' => $this->identityHash(),
            'source_table' => 'client_invoice_lines',
            'source_key' => '999',
            'target_type' => 'invoice_line',
            'target_public_id' => $orphan->public_id,
            'source_fingerprint' => str_repeat('a', 64),
            'status' => 'imported',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('client_invoice_lines')->where('id', $orphan->id)->delete();

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->expectsOutputToContain('not accounted for by an ordinary edit')
            ->assertFailed();

        $this->assertNull(ClientAgreement::query()->sole()->rollover_months);
    }

    /**
     * A credit is replaced on its own by OverpaymentCreditService, which leaves
     * the retainer beside it standing - so that retainer says nothing about
     * whether the credit was ordinarily regenerated.
     */
    public function test_a_surviving_retainer_does_not_disprove_a_regenerated_credit(): void
    {
        [$invoice] = $this->buildDestination();

        DB::connection('synthetic')->table('client_invoices')->insert([
            'client_invoice_id' => 504, 'invoice_kind' => 'cadence_period',
            'cycle_start' => '2026-06-01', 'cycle_end' => '2026-06-30', 'paid_date' => null,
            'retainer_hours_included' => '10.00', 'hours_worked' => '0.00', 'rollover_hours_used' => '0.00',
            'unused_hours_balance' => '0.00', 'negative_hours_balance' => '0.00', 'hours_billed_at_rate' => '0.00',
        ]);
        foreach ([['908', 'credit'], ['909', 'retainer']] as [$key, $type]) {
            DB::connection('synthetic')->table('client_invoice_lines')->insert([
                'client_invoice_line_id' => (int) $key, 'client_invoice_id' => 504, 'line_type' => $type,
                'line_date' => '2026-06-10', 'hours' => '0.0000', 'client_agreement_id' => 301,
                'client_agreement_recurring_item_id' => null,
            ]);
        }

        $fourth = ClientInvoice::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_company_id' => $invoice->client_company_id,
            'invoice_number' => 'SVC-00004', 'currency' => 'USD', 'status' => 'draft',
        ]);
        $this->ledger('client_invoices', '504', 'invoice', $fourth->public_id);

        $lines = [];
        foreach ([['908', 'credit'], ['909', 'retainer']] as [$key, $type]) {
            $lines[$key] = ClientInvoiceLine::query()->create([
                'workspace_id' => $invoice->workspace_id, 'client_invoice_id' => $fourth->id,
                'type' => $type, 'description' => ucfirst($type), 'quantity' => '1.0000',
                'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'sort_order' => 0,
            ]);
            $this->ledger('client_invoice_lines', $key, 'invoice_line', $lines[$key]->public_id);
        }

        // Only the credit goes, as the credit pass does.
        DB::table('client_invoice_lines')->where('id', $lines['908']->id)->delete();

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->expectsOutputToContain('removable in ordinary use')
            ->assertSuccessful();
    }

    /**
     * A report is the default and commits nothing, so it has no business
     * holding every invoice and line in the workspace while it reads five
     * source tables. Nobody should have to stop billing to run one.
     */
    public function test_a_report_takes_no_locks_and_an_apply_does(): void
    {
        $this->buildDestination();

        $locking = [];
        DB::listen(function ($query) use (&$locking): void {
            if (str_contains(strtolower($query->sql), 'for update')) {
                $locking[] = $query->sql;
            }
        });

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId()])->assertSuccessful();
        $this->assertSame([], $locking, 'A report must not lock rows it will never write');

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])->assertSuccessful();

        // SQLite compiles the clause to nothing, so the other half of this only
        // bites on the MariaDB job - which is the engine the claim is about.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertNotSame([], $locking, 'A run that can write holds what the gate answered for');
        } else {
            $this->assertSame([], $locking);
        }
    }

    /**
     * Waiving by table is not enough. An ordinary deletion explains a row that
     * was named and has gone; nothing explains one that was never named, so
     * the waiver must not reach it even on a table SVC does remove rows from.
     */
    public function test_an_unnamed_target_is_fatal_even_on_a_table_removable_in_ordinary_use(): void
    {
        [$invoice] = $this->buildDestination();

        // A second imported line, claimed by nothing, so nulling its target
        // exercises this and not the milestone link gate.
        DB::connection('synthetic')->table('client_invoice_lines')->insert([
            'client_invoice_line_id' => 902, 'client_invoice_id' => 501, 'line_date' => '2026-03-15',
            'hours' => '0.0000', 'client_agreement_id' => 301, 'client_agreement_recurring_item_id' => null,
        ]);
        $second = ClientInvoiceLine::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_invoice_id' => $invoice->id,
            'type' => 'adjustment', 'description' => 'Second', 'quantity' => '1.0000',
            'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'sort_order' => 1,
        ]);
        $this->ledger('client_invoice_lines', '902', 'invoice_line', $second->public_id);
        DB::table('external_import_items')
            ->where('source_table', 'client_invoice_lines')
            ->where('source_key', '902')
            ->update(['target_public_id' => null]);

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->expectsOutputToContain('name no destination at all')
            ->assertFailed();

        $this->assertNull(ClientAgreement::query()->sole()->rollover_months);
    }

    /**
     * A ledger row this workspace cannot follow stops the repair, and it is
     * never established whether the target is missing or merely somebody
     * else's - asking would mean reading another tenant's row, which a run for
     * this tenant may not do.
     */
    public function test_a_destination_owned_by_another_workspace_stops_the_repair(): void
    {
        [$invoice] = $this->buildDestination();

        $other = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $other->id, 'name' => 'Their Business', 'slug' => 'their-business',
        ]);
        $theirs = ClientInvoice::query()->create([
            'workspace_id' => $other->id, 'client_company_id' => $otherCompany->id,
            'invoice_number' => 'THEIR-00001', 'currency' => 'USD', 'status' => 'paid',
        ]);
        DB::table('external_import_items')
            ->where('source_table', 'client_invoices')
            ->update(['target_public_id' => $theirs->public_id]);

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->expectsOutputToContain('name a destination this workspace does not have')
            ->assertFailed();

        // Neither invoice was touched: not this workspace's row to repair, and
        // certainly not the other tenant's.
        $this->assertNull($invoice->refresh()->invoice_kind);
        $this->assertNull($theirs->refresh()->invoice_kind);
    }

    /**
     * Editing a draft and then issuing it is one ordinary sequence, and it
     * leaves an issued invoice whose lines were legitimately replaced while it
     * was still a draft. Asking what the invoice is now would call every line
     * that edit removed unexplained and abort the repair; what decides it is
     * the state the invoice arrived in.
     */
    public function test_lines_removed_by_an_edit_before_issuing_are_still_waived(): void
    {
        [$invoice] = $this->buildDestination();

        // Imported as a draft - no settlement date - so an edit could have
        // taken its line before anyone issued it.
        DB::connection('synthetic')->table('client_invoices')->insert([
            'client_invoice_id' => 502, 'invoice_kind' => 'cadence_period',
            'cycle_start' => '2026-04-01', 'cycle_end' => '2026-04-30', 'paid_date' => null,
            'retainer_hours_included' => '10.00', 'hours_worked' => '0.00', 'rollover_hours_used' => '0.00',
            'unused_hours_balance' => '0.00', 'negative_hours_balance' => '0.00', 'hours_billed_at_rate' => '0.00',
        ]);
        DB::connection('synthetic')->table('client_invoice_lines')->insert([
            'client_invoice_line_id' => 902, 'client_invoice_id' => 502, 'line_type' => 'retainer', 'line_date' => '2026-04-15',
            'hours' => '0.0000', 'client_agreement_id' => 301, 'client_agreement_recurring_item_id' => null,
        ]);

        $second = ClientInvoice::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_company_id' => $invoice->client_company_id,
            'invoice_number' => 'SVC-00002', 'currency' => 'USD', 'status' => 'draft',
        ]);
        $edited = ClientInvoiceLine::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_invoice_id' => $second->id,
            'type' => 'retainer', 'description' => 'Edited away', 'quantity' => '1.0000',
            'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'sort_order' => 0,
        ]);
        $this->ledger('client_invoices', '502', 'invoice', $second->public_id);
        $this->ledger('client_invoice_lines', '902', 'invoice_line', $edited->public_id);

        // Edited, then issued. Both happened after the import, in that order.
        DB::table('client_invoice_lines')->where('id', $edited->id)->delete();
        $second->update(['status' => 'issued']);

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->expectsOutputToContain('removable in ordinary use')
            ->assertSuccessful();

        $this->assertSame(1, ClientAgreement::query()->sole()->rollover_months);
    }

    /**
     * The same shape as the edited invoice above, on an invoice that is no
     * longer a draft. The waiver a removable table earns is for a line a
     * rewrite took, and it used to be granted whenever no sibling survived to
     * say otherwise - which is every single-line invoice, however the line
     * went. Nothing rewrites an invoice past draft, so on a settled one there
     * is no rewrite to waive and the loss is lost financial data.
     */
    public function test_a_lost_line_on_a_settled_invoice_is_not_waived_as_an_edit(): void
    {
        [$invoice] = $this->buildDestination();

        DB::connection('synthetic')->table('client_invoices')->insert([
            'client_invoice_id' => 502, 'invoice_kind' => 'cadence_period',
            'cycle_start' => '2026-04-01', 'cycle_end' => '2026-04-30', 'paid_date' => '2026-05-02',
            'retainer_hours_included' => '10.00', 'hours_worked' => '0.00', 'rollover_hours_used' => '0.00',
            'unused_hours_balance' => '0.00', 'negative_hours_balance' => '0.00', 'hours_billed_at_rate' => '0.00',
        ]);
        DB::connection('synthetic')->table('client_invoice_lines')->insert([
            'client_invoice_line_id' => 902, 'client_invoice_id' => 502, 'line_type' => 'retainer', 'line_date' => '2026-04-15',
            'hours' => '0.0000', 'client_agreement_id' => 301, 'client_agreement_recurring_item_id' => null,
        ]);

        $settled = ClientInvoice::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_company_id' => $invoice->client_company_id,
            'invoice_number' => 'SVC-00002', 'currency' => 'USD', 'status' => 'paid',
        ]);
        $lost = ClientInvoiceLine::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_invoice_id' => $settled->id,
            'type' => 'adjustment', 'description' => 'Not edited away - gone', 'quantity' => '1.0000',
            'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'sort_order' => 0,
        ]);
        $this->ledger('client_invoices', '502', 'invoice', $settled->public_id);
        $this->ledger('client_invoice_lines', '902', 'invoice_line', $lost->public_id);

        DB::table('client_invoice_lines')->where('id', $lost->id)->delete();

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->expectsOutputToContain('not accounted for by an ordinary edit')
            ->assertFailed();

        $this->assertNull(ClientAgreement::query()->sole()->rollover_months);
    }

    /**
     * A lost line the source still has, but with no invoice to belong to. The
     * evidence for a waiver is entirely about the parent - which of its
     * siblings survived, whether it is still a draft - so a line without one
     * has none of it and keeps none of it.
     *
     * Two things refuse this, and the test pins the outcome rather than which:
     * the parent is skipped rather than cast to an invoice named '', and no
     * such invoice resolves to a draft here in any case. Removing either alone
     * leaves this passing.
     */
    public function test_a_lost_line_whose_source_parent_is_missing_is_not_waived(): void
    {
        [$invoice] = $this->buildDestination();

        DB::connection('synthetic')->table('client_invoice_lines')->insert([
            'client_invoice_line_id' => 902, 'client_invoice_id' => null, 'line_type' => 'retainer', 'line_date' => '2026-04-15',
            'hours' => '0.0000', 'client_agreement_id' => 301, 'client_agreement_recurring_item_id' => null,
        ]);
        $orphan = ClientInvoiceLine::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_invoice_id' => $invoice->id,
            'type' => 'adjustment', 'description' => 'No parent at the source', 'quantity' => '1.0000',
            'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'sort_order' => 3,
        ]);
        $this->ledger('client_invoice_lines', '902', 'invoice_line', $orphan->public_id);

        DB::table('client_invoice_lines')->where('id', $orphan->id)->delete();

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->expectsOutputToContain('not accounted for by an ordinary edit')
            ->assertFailed();

        $this->assertNull(ClientAgreement::query()->sole()->rollover_months);
    }

    /**
     * Not every line that leaves an invoice leaves a draft. Issuing calls
     * capOverpaymentCreditAtIssue(), which deletes the credit line when the
     * pool can no longer cover it and then sets the status in the same
     * transaction - so the invoice a credit legitimately vanished from is
     * never a draft afterwards, and may well be paid by the time anyone runs
     * this. Requiring a draft there would reject a healthy ledger for good.
     */
    public function test_a_credit_removed_while_issuing_is_still_waived(): void
    {
        [$invoice] = $this->buildDestination();

        DB::connection('synthetic')->table('client_invoices')->insert([
            'client_invoice_id' => 502, 'invoice_kind' => 'cadence_period',
            'cycle_start' => '2026-04-01', 'cycle_end' => '2026-04-30', 'paid_date' => '2026-05-02',
            'retainer_hours_included' => '10.00', 'hours_worked' => '0.00', 'rollover_hours_used' => '0.00',
            'unused_hours_balance' => '0.00', 'negative_hours_balance' => '0.00', 'hours_billed_at_rate' => '0.00',
        ]);
        DB::connection('synthetic')->table('client_invoice_lines')->insert([
            'client_invoice_line_id' => 902, 'client_invoice_id' => 502, 'line_type' => 'credit', 'line_date' => '2026-04-15',
            'hours' => '0.0000', 'client_agreement_id' => 301, 'client_agreement_recurring_item_id' => null,
        ]);

        $issued = ClientInvoice::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_company_id' => $invoice->client_company_id,
            'invoice_number' => 'SVC-00002', 'currency' => 'USD', 'status' => 'paid',
        ]);
        $credit = ClientInvoiceLine::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_invoice_id' => $issued->id,
            'type' => 'credit', 'description' => 'Overpayment applied', 'quantity' => '1.0000',
            'unit_amount' => -500, 'tax_amount' => 0, 'total_amount' => -500, 'sort_order' => 0,
        ]);
        $this->ledger('client_invoices', '502', 'invoice', $issued->public_id);
        $this->ledger('client_invoice_lines', '902', 'invoice_line', $credit->public_id);

        DB::table('client_invoice_lines')->where('id', $credit->id)->delete();

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->expectsOutputToContain('removable in ordinary use')
            ->assertSuccessful();

        $this->assertSame(1, ClientAgreement::query()->sole()->rollover_months);
    }

    /**
     * The waiver reads two fields off the lost line's source row - its parent
     * and its type - and reasons about the invoice they name. A source row
     * that has moved since the import describes a line the import never saw,
     * so those fields are somebody else's evidence: moving the row under a
     * draft with no matching sibling would buy a waiver for a loss that has
     * nothing to do with that draft.
     */
    public function test_a_lost_line_whose_source_row_has_moved_is_not_waived(): void
    {
        [$invoice] = $this->buildDestination();

        DB::connection('synthetic')->table('client_invoices')->insert([
            'client_invoice_id' => 502, 'invoice_kind' => 'cadence_period',
            'cycle_start' => '2026-04-01', 'cycle_end' => '2026-04-30', 'paid_date' => null,
            'retainer_hours_included' => '10.00', 'hours_worked' => '0.00', 'rollover_hours_used' => '0.00',
            'unused_hours_balance' => '0.00', 'negative_hours_balance' => '0.00', 'hours_billed_at_rate' => '0.00',
        ]);
        DB::connection('synthetic')->table('client_invoice_lines')->insert([
            'client_invoice_line_id' => 902, 'client_invoice_id' => 502, 'line_type' => 'retainer', 'line_date' => '2026-04-15',
            'hours' => '0.0000', 'client_agreement_id' => 301, 'client_agreement_recurring_item_id' => null,
        ]);

        $second = ClientInvoice::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_company_id' => $invoice->client_company_id,
            'invoice_number' => 'SVC-00002', 'currency' => 'USD', 'status' => 'draft',
        ]);
        $edited = ClientInvoiceLine::query()->create([
            'workspace_id' => $invoice->workspace_id, 'client_invoice_id' => $second->id,
            'type' => 'adjustment', 'description' => 'Edited away', 'quantity' => '1.0000',
            'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0, 'sort_order' => 0,
        ]);
        $this->ledger('client_invoices', '502', 'invoice', $second->public_id);
        $this->ledger('client_invoice_lines', '902', 'invoice_line', $edited->public_id);

        DB::table('client_invoice_lines')->where('id', $edited->id)->delete();

        // Ledgered, then moved. Whatever this row says about its parent now,
        // it is not what was imported.
        DB::connection('synthetic')->table('client_invoice_lines')
            ->where('client_invoice_line_id', 902)
            ->update(['line_date' => '2026-06-30']);

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->expectsOutputToContain('not accounted for by an ordinary edit')
            ->assertFailed();

        $this->assertNull(ClientAgreement::query()->sole()->rollover_months);
    }

    /**
     * Time entries soft delete, but the lookup goes through the query builder
     * rather than the model, so no global scope applies and a soft-deleted row
     * resolves like any other. It never needs a waiver - and one granted anyway
     * would cover the case it is not for: an entry that is genuinely gone.
     */
    public function test_a_time_entry_that_is_genuinely_gone_stops_the_repair(): void
    {
        [$invoice] = $this->buildDestination();

        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $invoice->workspace_id,
            'client_company_id' => $invoice->client_company_id,
            'client_project_id' => ClientProject::query()->sole()->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-03-14',
            'minutes' => 60,
            'description' => 'Imported work',
            'status' => 'approved',
        ]);
        $this->ledger('client_time_entries', '601', 'time_entry', $entry->public_id);

        // Soft-deleted first: this must not be enough to stop anything.
        $entry->delete();
        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->assertSuccessful();

        DB::table('client_time_entries')->where('id', $entry->id)->delete();

        $this->artisan('svc:billing:backfill-ledger', ['--workspace' => $this->workspacePublicId(), '--apply' => true])
            ->expectsOutputToContain('imported into client_time_entries that this workspace cannot follow to a destination')
            ->assertFailed();
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
        $source->statement('CREATE TABLE client_invoice_lines (client_invoice_line_id INTEGER PRIMARY KEY, client_invoice_id INTEGER, line_type TEXT, line_date TEXT, hours TEXT, client_agreement_id INTEGER, client_agreement_recurring_item_id INTEGER)');
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
            'client_invoice_line_id' => 901, 'client_invoice_id' => 501, 'line_type' => 'milestone', 'line_date' => '2026-03-14',
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
