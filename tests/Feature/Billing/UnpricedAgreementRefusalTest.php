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
use App\Services\Billing\InterimOverageGenerator;
use App\Services\Billing\InvoiceLineComposer;
use App\Support\Billing\InvoiceKind;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An agreement that states no hourly rate cannot price hourly work.
 *
 * A null `client_agreements.hourly_rate_amount` is the absence of a price, not
 * a price of zero. Three money paths read it as `(int) ($rate ?? 0)` and billed
 * the hours at nothing:
 *
 * - `ClientInvoicingService::createHourlyLine` - cadence overage,
 * - `InterimOverageGenerator` - the interim overage line,
 * - `InvoiceLineComposer::addDeferredTerminationLine` - deferred work at
 *   termination, which put the coercion in front of the client in words:
 *   "Deferred work items billed on agreement termination (1.5 hrs @ $0.00/hr)".
 *
 * A fourth reader, `AgreementBillingRateResolver::resolve`, always refused. So
 * one nullable column carried two incompatible readings across the billing
 * engine, and the silent one was in the majority. This makes the three agree
 * with the one that was already right.
 *
 * ## Why refusing, rather than keeping zero and documenting it
 *
 * Keeping zero makes "the rate is genuinely free" and "the rate was never set"
 * the same row forever, with nothing to tell them apart and no error to notice.
 * A mis-imported agreement would bill nothing for the life of the contract. A
 * rate of zero is still available - it just has to be typed.
 *
 * ## Not affected
 *
 * The replay path keeps its `?? 0`, deliberately. Replay charges nothing; its
 * consumer treats a non-positive rate as "cannot prove this line" and declines
 * to make a claim, so refusing at load time would only make an agreement that
 * never billed hourly unreplayable. That is a sentinel, not a coercion.
 *
 * ## Isolation
 *
 * Every test here varies *only* `hourly_rate_amount` between the priced and
 * unpriced agreement. Nothing else about the fixture differs, so a pass cannot
 * come from some other column deciding the outcome - the failure #143 exists to
 * prevent.
 */
final class UnpricedAgreementRefusalTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Unpriced', 'slug' => 'unpriced']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Unpriced Client', 'slug' => 'unpriced-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Unpriced Project',
        ]);
        $this->user = User::factory()->create();
    }

    /** The contract itself, stated once and read by all three paths. */
    public function test_the_agreement_refuses_to_state_a_rate_it_does_not_have(): void
    {
        $this->assertSame(30000, $this->agreement(30000)->hourlyRateAmountOrFail());

        $unpriced = $this->agreement(null);

        // The whole message, not a fragment. The refusal has to name *which*
        // agreement - an operator reading it mid-run has no other way to find
        // the row - and it has to say what to do about it, so asserting only
        // the first clause lets the actionable half be dropped silently.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            "Agreement {$unpriced->public_id} states no hourly rate, so hourly work on it cannot be priced. "
            .'Set the rate - zero if the work is genuinely at no charge - before billing against it.',
        );
        $unpriced->hourlyRateAmountOrFail();
    }

    /**
     * Deferred work at termination is refused rather than billed at nothing.
     *
     * The invoice line here is the one a client actually reads, and under the
     * old coercion it described its own defect: hours stated, rate zero, total
     * zero. Nothing raised, so nothing was ever reviewed.
     */
    public function test_a_termination_line_is_refused_when_the_agreement_states_no_rate(): void
    {
        $priced = $this->agreement(30000);
        $entry = $this->entry(90);
        $invoice = $this->invoice($priced);
        $sort = 0;

        app(InvoiceLineComposer::class)->addDeferredTerminationLine($invoice, $priced, collect([$entry]), $sort);
        $this->assertSame(45000, (int) $invoice->lines()->firstOrFail()->total_amount);

        // Same entry shape, same invoice shape; only the rate differs.
        $unpriced = $this->agreement(null);
        $otherEntry = $this->entry(90);
        $otherInvoice = $this->invoice($unpriced);
        $otherSort = 0;

        try {
            app(InvoiceLineComposer::class)
                ->addDeferredTerminationLine($otherInvoice, $unpriced, collect([$otherEntry]), $otherSort);
            $this->fail('Deferred work on an unpriced agreement must not compose a line.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('states no hourly rate', $exception->getMessage());
        }

        // No zero-rate line was written on the way to the refusal.
        $this->assertSame(0, $otherInvoice->lines()->count());
    }

    /**
     * An interim overage on an unpriced agreement stops rather than bills zero.
     *
     * This is the path where the coercion was most expensive: an interim
     * overage exists precisely because the client went past their retainer, so
     * a zero charge is the difference between billing the overrun and giving it
     * away, silently, every cycle.
     */
    public function test_an_interim_overage_is_refused_when_the_agreement_states_no_rate(): void
    {
        $priced = $this->quarterly($this->agreement(30000));
        $this->entry(300, '2024-01-12', deferred: false);

        $invoice = app(InterimOverageGenerator::class)
            ->generateInterimOverageInvoice($this->company, Carbon::parse('2024-01-01'), $priced);

        $this->assertInstanceOf(ClientInvoice::class, $invoice);
        $this->assertSame(InvoiceKind::InterimOverage->value, $invoice->invoice_kind);
        $this->assertGreaterThan(0, (int) $invoice->total_amount);

        // A second company so the priced run above cannot influence this one,
        // and an agreement identical to it but for the rate.
        [$company, $project] = $this->secondCompany();
        $unpriced = $this->quarterly($this->agreement(null, $company, $project));
        $this->entry(300, '2024-01-12', $company, $project, deferred: false);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('states no hourly rate');
        app(InterimOverageGenerator::class)
            ->generateInterimOverageInvoice($company, Carbon::parse('2024-01-01'), $unpriced);
    }

    /**
     * Cadence overage is refused rather than billed at nothing.
     *
     * `ClientInvoicingService::createHourlyLine` is the third reader, and the
     * ordinary one: it prices every hour a monthly cycle bills beyond the
     * retainer. The priced half of this test is the same arithmetic as
     * `InvoicingExamplesTest`'s zero-threshold example - 8h worked against a 5h
     * retainer bills 3h - so the unpriced half is that example with one column
     * changed and nothing else.
     */
    public function test_cadence_overage_is_refused_when_the_agreement_states_no_rate(): void
    {
        $priced = $this->monthly($this->agreement(15000));
        $this->entry(480, '2024-01-15', deferred: false);

        $invoice = app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2024-01-01'),
            Carbon::parse('2024-01-31'),
        )->refresh();

        $additional = $invoice->lines()->where('type', 'additional_hours')->firstOrFail();
        $this->assertSame(3.0, (float) $additional->hours);
        $this->assertSame(45000, (int) $additional->total_amount, '3h at 150.00');
        $this->assertSame($priced->id, (int) $additional->client_agreement_id);

        [$company, $project] = $this->secondCompany();
        $this->monthly($this->agreement(null, $company, $project));
        $this->entry(480, '2024-01-15', $company, $project, deferred: false);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('states no hourly rate');
        app(ClientInvoicingService::class)->generateInvoice(
            $company,
            Carbon::parse('2024-01-01'),
            Carbon::parse('2024-01-31'),
        );
    }

    private function monthly(ClientAgreement $agreement): ClientAgreement
    {
        $agreement->forceFill([
            'billing_cadence' => 'monthly',
            // February, so January's work is covered retroactively and the
            // excess is billed rather than absorbed - the same construction as
            // `InvoicingExamplesTest`'s zero-threshold example.
            'starts_on' => '2024-02-01',
            'retainer_minutes' => 300,
            'retainer_amount' => 75000,
            'catch_up_threshold_minutes' => 0,
            'rollover_months' => 3,
        ])->save();

        return $agreement->refresh();
    }

    /** @return array{ClientCompany, ClientProject} */
    private function secondCompany(): array
    {
        $company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Second Client', 'slug' => 'second-client',
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $company->id, 'name' => 'Second Project',
        ]);

        return [$company, $project];
    }

    private function agreement(?int $hourlyRate, ?ClientCompany $company = null, ?ClientProject $project = null): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => ($company ?? $this->company)->id,
            'client_project_id' => $project?->id,
            'title' => 'Agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2024-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'hourly_rate_amount' => $hourlyRate,
            'rollover_months' => 0,
        ]);
    }

    private function quarterly(ClientAgreement $agreement): ClientAgreement
    {
        $agreement->forceFill([
            'billing_cadence' => 'quarterly',
            'bill_overage_interim' => true,
            'retainer_minutes' => 120,
        ])->save();

        return $agreement->refresh();
    }

    private function entry(int $minutes, string $workedOn = '2026-03-14', ?ClientCompany $company = null, ?ClientProject $project = null, bool $deferred = true): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => ($company ?? $this->company)->id,
            'client_project_id' => ($project ?? $this->project)->id,
            'user_id' => $this->user->id,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => $deferred,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }

    private function invoice(ClientAgreement $agreement): ClientInvoice
    {
        return ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $agreement->client_company_id,
            'client_agreement_id' => $agreement->id,
            'invoice_number' => 'UNPRICED-'.str()->random(6),
            'status' => 'draft',
            'currency' => 'USD',
            'issue_date' => '2026-03-31',
            'service_period_start' => '2026-03-01',
            'service_period_end' => '2026-03-31',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
            'balance_amount' => 0,
        ]);
    }
}
