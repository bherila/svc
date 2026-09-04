<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Balances\BillingCycle;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InterimOverageGenerator;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InvoiceKind;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A charged invoice that records no billed-overage figure stops billing.
 *
 * Three sums subtract `hours_billed_at_rate` so the next period does not charge
 * the same overage twice, and all three are `SUM(...)`. SQL contributes nothing
 * for a NULL, so a charged invoice with no recorded figure reads as *zero
 * already billed* - and the client is charged again for hours they have already
 * paid for.
 *
 * ## Why refusing, and not a default
 *
 * #135 fixed the same shape on `service_period_end` by reading the null
 * fail-closed: the question there was which side of a window a row falls on,
 * and counting an unplaceable row as *inside* turns a double charge into
 * capacity credited a period early. No such reading exists here. The question
 * is *how much* was billed. A null is not a quantity, coercing it to zero is
 * exactly the current behaviour and exactly the defect, and `COALESCE` to
 * anything else invents a number.
 *
 * ## Sequencing
 *
 * This refusal could not reach production until the column had been restored on
 * every imported invoice, because until then every one of them carried a null
 * and generation would have stopped for every company. That repair ran, the
 * import tooling that performed it has since been retired, and
 * `svc:billing:audit-missing-billed-overage` reports zero. The refusal remains
 * because the column is still nullable and a hand-edited row can still reach
 * it.
 *
 * ## Isolation
 *
 * Every test varies only `hours_billed_at_rate` between the priced and unknown
 * fixture. Nothing else about the invoice differs, so a pass cannot come from
 * some other column deciding the outcome.
 */
final class UnknownBilledOverageRefusalTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Overage', 'slug' => 'overage']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Overage Client', 'slug' => 'overage-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Overage Project',
        ]);
        $this->user = User::factory()->create();
    }

    /** The contract itself, stated once and read by both sums. */
    public function test_an_invoice_refuses_to_state_a_figure_it_does_not_have(): void
    {
        $agreement = $this->agreement();

        $this->assertSame(4.0, $this->invoice($agreement, billedHours: 4.0)->billedOverageHoursOrFail());

        $unknown = $this->invoice($agreement, billedHours: null);

        // The whole message, not a fragment. It has to name the invoice - an
        // operator hitting this mid-run has no other way to find the row - and
        // it has to say what to do about it, so asserting only the first clause
        // lets the actionable half be dropped silently. It no longer names a
        // command: the one it used to name was retired with the importer, and a
        // message telling an operator to run something that does not exist is
        // worse than one that tells them nothing.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            "Invoice {$unknown->invoice_number} is charged but records no billed-overage hours, so what it has "
            .'already billed cannot be known and the next period cannot be priced without risking a second '
            .'charge for the same hours. Set the figure on this invoice from what it actually billed before '
            .'billing this agreement again.',
        );
        $unknown->billedOverageHoursOrFail();
    }

    /**
     * A zero is a figure; only a null is unknown.
     *
     * The distinction the whole issue turns on, and the one a `COALESCE` would
     * erase. An invoice that genuinely billed no overage says so, and must keep
     * summing to nothing rather than stopping the run.
     */
    public function test_an_invoice_that_billed_nothing_is_not_a_refusal(): void
    {
        $agreement = $this->agreement();

        $this->assertSame(0.0, $this->invoice($agreement, billedHours: 0.0)->billedOverageHoursOrFail());
    }

    /**
     * The cadence path stops rather than charging the overage twice.
     *
     * The prior invoice is inside the already-billed window, so its figure is
     * subtracted from what this period charges. Reading it as zero bills the
     * same hours again.
     */
    public function test_cadence_generation_refuses_when_an_earlier_invoice_is_unknown(): void
    {
        $agreement = $this->agreement();
        $this->invoice($agreement, billedHours: null, periodEnd: '2024-01-31');
        $this->entry(600, '2024-02-12');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('records no billed-overage hours');
        app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2024-02-01'),
            Carbon::parse('2024-02-29'),
        );
    }

    /** The same window with a stated figure generates normally. */
    public function test_cadence_generation_proceeds_when_the_earlier_figure_is_stated(): void
    {
        $agreement = $this->agreement();
        $this->invoice($agreement, billedHours: 0.0, periodEnd: '2024-01-31');
        $this->entry(600, '2024-02-12');

        $invoice = app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2024-02-01'),
            Carbon::parse('2024-02-29'),
        );

        $this->assertInstanceOf(ClientInvoice::class, $invoice);
    }

    /**
     * The interim path stops too, through the shared attribution helper.
     *
     * `interimOverageHoursForCycle()` and
     * `alreadyBilledInterimHoursBeforePeriod()` both funnel through it, so one
     * refusal covers the two remaining readers.
     */
    public function test_interim_attribution_refuses_when_a_charged_interim_invoice_is_unknown(): void
    {
        $agreement = $this->quarterly($this->agreement());
        $this->invoice(
            $agreement,
            billedHours: null,
            periodEnd: '2024-01-31',
            kind: InvoiceKind::InterimOverage->value,
            cycleStart: '2024-01-01',
            cycleEnd: '2024-03-31',
        );

        // Asked directly rather than through `generateInterimOverageInvoice()`,
        // which refuses earlier for an unrelated reason - an issued interim
        // invoice already exists for the period - and would have made this pass
        // on the wrong exception.
        $cycle = new BillingCycle(
            start: Carbon::parse('2024-01-01'),
            end: Carbon::parse('2024-03-31'),
            isProrated: false,
            monthCount: 3,
            monthStarts: [Carbon::parse('2024-01-01'), Carbon::parse('2024-02-01'), Carbon::parse('2024-03-01')],
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('records no billed-overage hours');
        app(InterimOverageGenerator::class)->interimOverageHoursForCycle($this->company, $agreement, $cycle);
    }

    /** The same cycle sums normally once the figure is stated. */
    public function test_interim_attribution_sums_a_stated_figure(): void
    {
        $agreement = $this->quarterly($this->agreement());
        $this->invoice(
            $agreement,
            billedHours: 2.5,
            periodEnd: '2024-01-31',
            kind: InvoiceKind::InterimOverage->value,
            cycleStart: '2024-01-01',
            cycleEnd: '2024-03-31',
        );

        $cycle = new BillingCycle(
            start: Carbon::parse('2024-01-01'),
            end: Carbon::parse('2024-03-31'),
            isProrated: false,
            monthCount: 3,
            monthStarts: [Carbon::parse('2024-01-01'), Carbon::parse('2024-02-01'), Carbon::parse('2024-03-01')],
        );

        $this->assertSame(
            2.5,
            app(InterimOverageGenerator::class)->interimOverageHoursForCycle($this->company, $agreement, $cycle),
        );
    }

    /**
     * An invoice the application itself created is readable the moment it exists.
     *
     * Found by review of this branch, and the one case that would have made the
     * refusal worse than the defect. `InvoiceLifecycleService::createDraft()` is
     * the native creation path - every scheduled invoice from
     * `BillingScheduleService::generateDue()` and every ad-hoc invoice an
     * operator raises - and it never wrote `hours_billed_at_rate`. Issue one of
     * those against an agreement and it becomes a charged row carrying a null,
     * so the very next cadence period refused, permanently, over a value the
     * application had declined to state about itself.
     *
     * Zero rather than a widened window, because zero is the truth: nothing on
     * that path bills overage hours. Narrowing the window by invoice kind would
     * instead have left a null in the ledger for some later reader to coerce.
     *
     * The assertion is the contract, not the column: the invoice is asked for
     * its figure, which is what every sum does and what would have thrown.
     */
    public function test_a_natively_created_invoice_states_a_figure_rather_than_none(): void
    {
        $agreement = $this->agreement();

        $draft = app(InvoiceLifecycleService::class)->createDraft(
            $this->workspace,
            $this->company,
            [
                'invoice_number' => 'SCH-'.str()->random(8),
                'issue_date' => '2024-01-01',
                'due_date' => '2024-01-31',
                'service_period_start' => '2024-01-01',
                'service_period_end' => '2024-01-31',
                'currency' => 'USD',
                'client_agreement_id' => $agreement->id,
            ],
            [['description' => 'Monthly retainer', 'type' => 'retainer', 'quantity' => '1', 'unit_amount' => 150000]],
        );

        $this->assertSame(0.0, $draft->billedOverageHoursOrFail());
    }

    /**
     * And the period after one is priced rather than refused.
     *
     * The end-to-end half of the case above: the billed-overage ledger reads
     * this column, and the reported failure was generation stopping for the
     * agreement rather than a value being wrong. So this issues the native
     * invoice and then generates the following period, where the refusal would
     * have fired.
     */
    public function test_generation_continues_after_a_natively_created_invoice_is_issued(): void
    {
        $agreement = $this->agreement();

        $draft = app(InvoiceLifecycleService::class)->createDraft(
            $this->workspace,
            $this->company,
            [
                'invoice_number' => 'SCH-'.str()->random(8),
                'issue_date' => '2024-01-01',
                'due_date' => '2024-01-31',
                'service_period_start' => '2024-01-01',
                'service_period_end' => '2024-01-31',
                'currency' => 'USD',
                'client_agreement_id' => $agreement->id,
            ],
            [['description' => 'Monthly retainer', 'type' => 'retainer', 'quantity' => '1', 'unit_amount' => 150000]],
        );
        app(InvoiceLifecycleService::class)->issue($draft, $this->workspace);

        $this->entry(600, '2024-02-12');

        $invoice = app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2024-02-01'),
            Carbon::parse('2024-02-29'),
        );

        $this->assertInstanceOf(ClientInvoice::class, $invoice);
    }

    private function agreement(): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2024-01-01',
            'retainer_minutes' => 120,
            'retainer_amount' => 150000,
            'hourly_rate_amount' => 20000,
            'catch_up_threshold_minutes' => 0,
            'rollover_months' => 0,
        ]);
    }

    private function quarterly(ClientAgreement $agreement): ClientAgreement
    {
        $agreement->forceFill(['billing_cadence' => 'quarterly', 'bill_overage_interim' => true])->save();

        return $agreement->refresh();
    }

    private function invoice(
        ClientAgreement $agreement,
        ?float $billedHours,
        string $periodEnd = '2024-01-31',
        ?string $kind = null,
        ?string $cycleStart = null,
        ?string $cycleEnd = null,
    ): ClientInvoice {
        return ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $agreement->id,
            'invoice_number' => 'OVR-'.str()->random(8),
            'status' => 'paid',
            'currency' => 'USD',
            'issue_date' => $periodEnd,
            'due_date' => $periodEnd,
            'service_period_start' => '2024-01-01',
            'service_period_end' => $periodEnd,
            'invoice_kind' => $kind,
            'cycle_start' => $cycleStart,
            'cycle_end' => $cycleEnd,
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
            'balance_amount' => 0,
            'hours_billed_at_rate' => $billedHours,
        ]);
    }

    private function entry(int $minutes, string $workedOn): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }
}
