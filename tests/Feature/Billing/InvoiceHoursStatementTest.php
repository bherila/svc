<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceDocumentService;
use App\Services\Billing\InvoiceLedgerBuilder;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoiceHoursStatement;
use App\Support\Billing\InvoiceLineDetail;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\SubcontractorBillingMode;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\TestCase;

/**
 * The hours statement a cadence invoice carries, and the document it prints on.
 *
 * Three properties, each of which a plausible implementation gets wrong:
 * the statement agrees with the lines it explains, because both come from one
 * computation; an issued invoice's statement does not move when the ledger
 * behind it later does; and a client's copy never prints an internal
 * description, even for billed work that has no client wording.
 */
final class InvoiceHoursStatementTest extends TestCase
{
    use BuildsSyntheticExpenses;
    use RefreshDatabase;

    private const INTERNAL = 'Synthetic internal note: chased the vendor again';

    private const CLIENT_WORDING = 'Synthetic client-facing summary of the work';

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientAgreement $agreement;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-02-20 12:00:00'));
        $this->workspace = $this->syntheticWorkspace('Statement');
        $this->company = $this->syntheticCompany($this->workspace, 'Statement');
        $this->project = $this->syntheticProject($this->company, 'Statement');
        $this->member = $this->syntheticMember($this->workspace, 'Statement worker');
        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'title' => 'Retainer',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 150000, 'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 15000, 'billing_cadence' => 'monthly', 'rollover_months' => 1,
        ]);
    }

    public function test_an_ordinary_month_rolls_its_unused_hours_forward(): void
    {
        $this->entry('2026-01-12', 360);

        $statement = $this->generate('2026-01')->hoursStatement();

        $this->assertInstanceOf(InvoiceHoursStatement::class, $statement);
        $this->assertSame('2026-01-01', $statement->workStart);
        $this->assertSame('2026-02-01', $statement->retainerStart);
        $this->assertSame(10.0, $statement->openingRetainerHours);
        $this->assertSame(0.0, $statement->openingDeficitHours);
        $this->assertSame(6.0, $statement->ordinaryHours);
        $this->assertSame(6.0, $statement->ordinaryAppliedToWorkPool);
        $this->assertSame(0.0, $statement->catchUpBilledHours);
        $this->assertSame(4.0, $statement->rolledForwardHours);
        $this->assertSame(0.0, $statement->expiringHours);
        $this->assertSame(10.0, $statement->nextRetainerHours);
        $this->assertSame(14.0, $statement->closingNetHours());
    }

    /**
     * 22 hours against 10 of January and 10 of February: 2 billed for work
     * and 1 to restore the one-hour minimum, labelled as such - and the figure
     * is the one on the catch-up line, not a second opinion of it.
     */
    public function test_catch_up_separates_work_from_the_minimum_availability_hour_and_matches_the_line(): void
    {
        $this->entry('2026-01-12', 1320);

        $invoice = $this->generate('2026-01');
        $statement = $invoice->hoursStatement();

        $this->assertInstanceOf(InvoiceHoursStatement::class, $statement);
        $this->assertSame(22.0, $statement->ordinaryHours);
        $this->assertSame(10.0, $statement->ordinaryAppliedToWorkPool);
        $this->assertSame(10.0, $statement->ordinaryAppliedToNextRetainer);
        $this->assertSame(2.0, $statement->ordinaryBilledAtRate);
        $this->assertSame(1.0, $statement->minimumAvailabilityHours);
        $this->assertSame(1.0, $statement->minimumAvailabilityThresholdHours);
        $this->assertSame(3.0, $statement->catchUpBilledHours);
        $this->assertSame(9.0, $statement->deficitCarriedForwardHours);
        $this->assertSame(1.0, $statement->closingNetHours(), 'The next period opens with exactly the minimum');

        $catchUp = $invoice->lines->firstWhere('type', InvoiceLineType::AdditionalHours->value);
        $this->assertSame((float) $catchUp?->hours, $statement->catchUpBilledHours);
        $this->assertSame((float) $invoice->hours_billed_at_rate, $statement->catchUpBilledHours);

        $html = app(InvoiceDocumentService::class)->html($invoice, InvoiceLineDetail::CLIENT)->render();
        $this->assertStringContainsString('Minimum availability: restores the agreement&#039;s 1.00-hour minimum for February 2026', $html);
    }

    /**
     * Deferred work draws only on what the month left free; what does not fit
     * waits. The statement counts both from the lines, and its closing
     * position is measured after the deferred draw rather than before it.
     */
    public function test_deferred_work_applied_and_waiting_is_reported_from_the_lines(): void
    {
        $this->entry('2026-01-05', 360);
        $this->entry('2026-01-20', 180, deferred: true);
        $this->entry('2026-01-21', 300, deferred: true);

        $invoice = $this->generate('2026-01');
        $statement = $invoice->hoursStatement();

        $this->assertInstanceOf(InvoiceHoursStatement::class, $statement);
        $this->assertSame(3.0, $statement->deferredAppliedHours);
        $this->assertSame(1, $statement->deferredAppliedEntries);
        $this->assertSame(5.0, $statement->deferredBacklogHours);
        $this->assertSame(1, $statement->deferredBacklogEntries);
        $this->assertSame(0.0, $statement->catchUpBilledHours);
        // 10 - 6 ordinary - 3 deferred: one hour left to roll forward, not four.
        $this->assertSame(1.0, $statement->rolledForwardHours);

        $deferredLine = $invoice->lines->first(fn ($line): bool => str_starts_with((string) $line->description, 'Deferred work items applied'));
        $this->assertSame((float) $deferredLine?->hours, $statement->deferredAppliedHours);
    }

    /**
     * Flat-hourly subcontractor time is on the invoice and in the appendix but
     * never draws on the pool. The statement accounts for it on its own row,
     * so every hour itemised is an hour the statement names - and the pool
     * figures are exactly what they would be without it.
     */
    public function test_separately_billed_subcontractor_hours_reconcile_with_the_appendix(): void
    {
        $this->entry('2026-01-05', 360);
        $this->entry('2026-01-06', 60, deferred: true);
        $this->entry('2026-01-07', 120)->forceFill([
            'subcontractor_billing_mode' => SubcontractorBillingMode::FlatHourly->value,
            'subcontractor_cost_amount' => 9000, 'subcontractor_cost_currency' => 'USD',
        ])->save();

        $invoice = $this->generate('2026-01');
        $statement = $invoice->hoursStatement();

        $this->assertInstanceOf(InvoiceHoursStatement::class, $statement);
        $this->assertSame(6.0, $statement->ordinaryHours);
        $this->assertSame(2.0, $statement->subcontractorHours);
        $this->assertSame(1.0, $statement->deferredAppliedHours);
        $this->assertSame(3.0, $statement->rolledForwardHours, 'Subcontractor time takes nothing from the pool');

        $itemised = 0;
        foreach (InvoiceLineDetail::forInvoice($invoice, InvoiceLineDetail::CLIENT) as $items) {
            $itemised += array_sum(array_column($items, 'minutes'));
        }
        $this->assertSame(
            round($itemised / 60, 4),
            $statement->ordinaryHours + $statement->subcontractorHours + $statement->deferredAppliedHours,
        );
        $html = app(InvoiceDocumentService::class)->html($invoice, InvoiceLineDetail::CLIENT)->render();
        $this->assertStringContainsString('Subcontractor hours billed separately at their own rate (not drawn on the pool)', $html);
    }

    /**
     * A correction range inside a month whose retainer an earlier invoice
     * already sold charges no retainer. Its statement keeps the pool position
     * - that is still where the client stands - but names the invoice that
     * sold the retainer and never presents it as sold again.
     */
    public function test_a_correction_names_the_invoice_that_sold_the_retainer(): void
    {
        $this->entry('2026-01-12', 360);
        $this->entry('2026-02-05', 120);
        $ordinary = $this->generate('2026-01');
        $this->assertNull($ordinary->hoursStatement()?->retainerSoldBy);

        $correction = app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse('2026-02-01'), Carbon::parse('2026-02-15'), $this->agreement,
        );
        $statement = $correction->hoursStatement();

        $this->assertSame(0, $correction->lines()->where('type', InvoiceLineType::Retainer->value)->count());
        $this->assertInstanceOf(InvoiceHoursStatement::class, $statement);
        $this->assertSame($ordinary->invoice_number, $statement->retainerSoldBy);

        foreach ([InvoiceLineDetail::CLIENT, InvoiceLineDetail::OPERATOR] as $audience) {
            $html = app(InvoiceDocumentService::class)->html($correction, $audience)->render();
            $this->assertStringContainsString('Retainer hours for February 2026, sold on invoice '.$ordinary->invoice_number, $html);
            $this->assertStringContainsString('This invoice does not sell the February 2026 retainer and charges nothing for it', $html);
            $this->assertStringNotContainsString('<td class="label">Retainer hours for February 2026</td>', $html);
        }
    }

    /**
     * Issued means frozen. Work logged later against the same month changes
     * what the ledger would now compute for it, and a statement recomputed at
     * render time would silently rewrite a document the client already holds.
     */
    public function test_an_issued_invoices_statement_does_not_move_when_the_ledger_does(): void
    {
        $this->entry('2026-01-12', 360);
        $invoice = $this->generate('2026-01');
        $this->travelTo(Carbon::parse('2026-02-01 09:00:00'));
        $issued = app(InvoiceLifecycleService::class)->issue($invoice, $this->workspace);
        $before = $issued->fresh()?->hours_statement;
        $html = app(InvoiceDocumentService::class)->html($issued->fresh() ?? $issued, InvoiceLineDetail::CLIENT)->render();

        // More January work, and February's invoice generated over it.
        $this->entry('2026-01-28', 240);
        $this->travelTo(Carbon::parse('2026-02-27 12:00:00'));
        $this->entry('2026-02-10', 120);
        $this->generate('2026-02');

        // The ledger behind January really did move: recomputed now, the month
        // holds the late entry, so a statement derived at render time would
        // print different figures on the invoice already issued.
        $january = collect(app(InvoiceLedgerBuilder::class)->buildAgreementLedgerThrough(
            $this->company, $this->agreement, Carbon::parse('2026-01-31'),
        ))->firstWhere('yearMonth', '2026-01');
        $this->assertSame(10.0, $january?->hoursWorked);

        $after = $issued->fresh();
        $this->assertSame($before, $after?->hours_statement);
        $this->assertSame($html, app(InvoiceDocumentService::class)->html($after ?? $issued, InvoiceLineDetail::CLIENT)->render());
        $this->assertSame(6.0, $after?->hoursStatement()?->ordinaryHours);
    }

    public function test_the_client_copy_never_prints_an_internal_description_and_labels_the_rest(): void
    {
        $this->entry('2026-01-05', 120, visibleWording: self::CLIENT_WORDING);
        $this->entry('2026-01-06', 60);
        $invoice = $this->generate('2026-01');

        $client = app(InvoiceDocumentService::class)->html($invoice, InvoiceLineDetail::CLIENT)->render();
        $operator = app(InvoiceDocumentService::class)->html($invoice, InvoiceLineDetail::OPERATOR)->render();

        $this->assertStringNotContainsString(self::INTERNAL, $client);
        $this->assertStringContainsString(self::CLIENT_WORDING, $client);
        $this->assertStringContainsString(InvoiceLineDetail::CLIENT_GENERIC_LABEL, $client);
        // Both entries are billed, so both are itemised: the appendix totals
        // the hours the statement reports.
        $this->assertStringContainsString('2 entries', $client);
        $this->assertStringContainsString('3.00', $client);
        $this->assertStringContainsString(self::INTERNAL, $operator);
        $this->assertStringContainsString('Administrator copy', $operator);
        $this->assertStringNotContainsString('Administrator copy', $client);
    }

    /**
     * An operator may still rewrite a generated draft's lines by hand - that is
     * what `invoices.update_draft` is for, on any draft - but the statement was
     * measured against the lines the generator wrote. Replaced lines take the
     * statement with them: the draft prints none rather than a wrong one, and
     * the next regeneration measures it again.
     */
    public function test_replacing_a_generated_drafts_lines_withdraws_its_statement(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true]);
        $this->entry('2026-01-12', 360);
        $draft = $this->generate('2026-01');
        $this->assertNotNull($draft->fresh()?->hours_statement);
        $this->actingAsMcp($this->member, [AgentApiScopes::BILLING_WRITE]);

        $this->withHeader('Idempotency-Key', 'synthetic-replace-lines')->patchJson(
            "/api/v1/workspaces/{$this->workspace->public_id}/invoices/{$draft->public_id}",
            [
                'expected_version' => AgentApiVersion::for($draft->fresh() ?? $draft),
                'time_entry_ids' => [],
                'manual_lines' => [['type' => 'adjustment', 'description' => 'Synthetic hand-written line', 'quantity' => '1', 'unit_amount' => 50000]],
            ],
        )->assertOk();

        $edited = $draft->fresh();
        $this->assertNull($edited?->hours_statement);
        $html = app(InvoiceDocumentService::class)->html($edited ?? $draft, InvoiceLineDetail::CLIENT)->render();
        $this->assertStringNotContainsString('Hours statement', $html);

        // Generated again, it is measured again.
        $this->assertNotNull($this->generate('2026-01')->fresh()?->hours_statement);
    }

    /** US Letter on every page, in both audiences, however long the appendix runs. */
    public function test_every_page_of_both_copies_is_us_letter(): void
    {
        foreach (range(1, 28) as $day) {
            foreach ([45, 30, 60] as $index => $minutes) {
                $this->entry(sprintf('2026-01-%02d', $day), $minutes, visibleWording: $index === 0 ? self::CLIENT_WORDING : null);
            }
        }
        $invoice = $this->generate('2026-01');

        foreach ([InvoiceLineDetail::CLIENT, InvoiceLineDetail::OPERATOR] as $audience) {
            $boxes = $this->mediaBoxes(app(InvoiceDocumentService::class)->pdf($invoice, $audience));

            $this->assertGreaterThanOrEqual(4, count($boxes), $audience.': invoice, statement and a multi-page appendix');
            foreach ($boxes as $box) {
                $this->assertSame([0.0, 0.0, 612.0, 792.0], $box, $audience);
            }
        }
    }

    /**
     * A quarter is reconciled from the same monthly ledger, so hours expire
     * inside it month by month. The statement says so, and what it leaves at
     * the close is exactly what the invoice's own stored balance records.
     */
    public function test_a_quarterly_invoice_accounts_for_hours_expiring_inside_the_cycle(): void
    {
        $this->agreement->forceFill(['billing_cadence' => 'quarterly', 'retainer_minutes' => 1800, 'retainer_amount' => 450000, 'catch_up_threshold_minutes' => null])->save();
        foreach (['2026-01-10' => 600, '2026-02-10' => 900, '2026-03-10' => 600, '2026-03-20' => 300] as $day => $minutes) {
            $this->entry($day, $minutes);
        }
        $this->travelTo(Carbon::parse('2026-04-02 12:00:00'));
        app(ClientInvoicingService::class)->generateAllInvoices($this->company);

        $invoice = ClientInvoice::query()->where('workspace_id', $this->workspace->id)->whereDate('service_period_start', '2026-01-01')->sole();
        $statement = $invoice->hoursStatement();

        $this->assertInstanceOf(InvoiceHoursStatement::class, $statement);
        $this->assertSame('quarterly', $statement->cadence);
        $this->assertSame(90.0, $statement->openingRetainerHours);
        $this->assertSame(40.0, $statement->ordinaryHours);
        $this->assertSame(40.0, $statement->ordinaryAppliedToWorkPool);
        // January's unused 20 expire at the start of March; February's 15
        // expire at the start of April and March's 15 roll into it.
        $this->assertSame(20.0, $statement->expiredWithinPeriodHours);
        $this->assertSame(15.0, $statement->expiringHours);
        $this->assertSame(15.0, $statement->rolledForwardHours);
        $this->assertSame((float) $invoice->unused_hours_balance, $statement->rolledForwardHours + $statement->expiringHours);
        $this->assertSame((float) $invoice->retainer_hours_included, $statement->nextRetainerHours);
    }

    public function test_an_invoice_without_a_statement_prints_without_one(): void
    {
        $this->entry('2026-01-12', 60);
        $invoice = $this->generate('2026-01');
        $invoice->forceFill(['hours_statement' => null])->save();

        $html = app(InvoiceDocumentService::class)->html($invoice->fresh() ?? $invoice, InvoiceLineDetail::CLIENT)->render();

        $this->assertStringNotContainsString('Hours statement', $html);
        $this->assertStringContainsString('Appendix: time entries on this invoice', $html);
    }

    /**
     * The MediaBox of every page object, in points.
     *
     * @return list<list<float>>
     */
    private function mediaBoxes(string $pdf): array
    {
        preg_match_all('~/MediaBox\s*\[\s*([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s*\]~', $pdf, $matches, PREG_SET_ORDER);
        preg_match('~/Type\s*/Pages\b[^>]*?/Count\s+(\d+)~s', $pdf, $count);
        $boxes = array_map(static fn (array $match): array => array_map('floatval', array_slice($match, 1)), $matches);
        $this->assertNotSame([], $boxes, 'The PDF states no page size');
        $this->assertArrayHasKey(1, $count, 'The PDF states no page count');
        // Dompdf may state the box once on the page tree rather than per page;
        // either way every page inherits one of these, so the count is the pages.
        $this->assertGreaterThan(0, (int) $count[1]);

        return array_pad($boxes, (int) $count[1], $boxes[0]);
    }

    private function generate(string $workMonth): ClientInvoice
    {
        $start = Carbon::parse($workMonth.'-01');

        return app(ClientInvoicingService::class)->generateInvoice(
            $this->company, $start, $start->copy()->endOfMonth()->startOfDay(), $this->agreement,
        );
    }

    private function entry(string $workedOn, int $minutes, bool $deferred = false, ?string $visibleWording = null): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id, 'user_id' => $this->member->id,
            'worked_on' => $workedOn, 'minutes' => $minutes, 'description' => self::INTERNAL,
            'client_visible_description' => $visibleWording, 'is_visible_to_client' => $visibleWording !== null,
            'is_billable' => true, 'is_deferred' => $deferred, 'status' => 'approved',
            'billing_rate_amount' => 15000, 'currency' => 'USD',
        ]);
    }
}
