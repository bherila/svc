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
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InvoiceHoursStatement;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * An earlier month's invoice that is still a draft already bills its catch-up.
 *
 * The capacity ledger books every month's work from the time entries, but
 * reads catch-up hours only from charged invoices. While January's invoice is
 * a draft, February's ledger therefore sees January's work without the hours
 * January bills for it, and opens on a debt January already charges.
 *
 * A 10-hour retainer with a one-hour minimum availability. January works 20
 * hours: 10 from its own pool, 10 lent by February's retainer, and one hour
 * billed to restore the minimum. February then works 10 hours. With January's
 * hour counted, February has that hour left, borrows 9 from March and keeps the
 * minimum without billing anything. Without it, February opens at zero, borrows
 * all 10 and bills the minimum a second time.
 */
final class EarlierDraftCatchUpTest extends TestCase
{
    use BuildsSyntheticExpenses;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientAgreement $agreement;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-03-02 12:00:00'));
        $this->workspace = $this->syntheticWorkspace('Earlier draft');
        $this->company = $this->syntheticCompany($this->workspace, 'Earlier draft');
        $this->project = $this->syntheticProject($this->company, 'Earlier draft');
        $this->member = $this->syntheticMember($this->workspace, 'Earlier draft worker');
        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'title' => 'Retainer',
            'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 150000, 'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 15000, 'billing_cadence' => 'monthly', 'rollover_months' => 1,
        ]);
        $this->entry('2026-01-12', 1200);
        $this->entry('2026-02-10', 600);
    }

    public function test_the_next_month_counts_what_an_earlier_draft_month_already_bills(): void
    {
        [$january, $januaryStatement] = $this->month('2026-01-01', '2026-01-31');
        $this->assertSame(1.0, $januaryStatement->catchUpBilledHours, 'January restores the minimum');
        $this->assertSame('draft', $january->fresh()?->status);

        [$february, $statement] = $this->month('2026-02-01', '2026-02-28');

        $this->assertSame(1.0, $statement->ordinaryAppliedToWorkPool, 'The hour January\'s draft leaves once it is paid');
        $this->assertSame(9.0, $statement->ordinaryAppliedToNextRetainer);
        $this->assertSame(0.0, $statement->minimumAvailabilityHours);
        $this->assertSame(0.0, $statement->catchUpBilledHours, 'January\'s minimum is not billed a second time');
        $this->assertSame(0.0, (float) $february->hours_billed_at_rate);
    }

    /** The same figures once January is issued: the draft's charge is not counted twice. */
    public function test_the_next_month_bills_the_same_once_the_earlier_month_is_issued(): void
    {
        [$january] = $this->month('2026-01-01', '2026-01-31');
        $this->month('2026-02-01', '2026-02-28');
        $this->issue($january);

        [$february, $statement] = $this->month('2026-02-01', '2026-02-28');

        $this->assertSame(1.0, $statement->ordinaryAppliedToWorkPool);
        $this->assertSame(0.0, $statement->catchUpBilledHours);
        $this->assertSame(0.0, (float) $february->hours_billed_at_rate);
    }

    /**
     * Across issued invoices the minimum is bought once: March, working its
     * own retainer, has the one-hour buffer February left and bills nothing.
     */
    public function test_the_minimum_is_billed_once_across_the_months(): void
    {
        [$january] = $this->month('2026-01-01', '2026-01-31');
        [$february] = $this->month('2026-02-01', '2026-02-28');
        $this->issue($january);
        $this->issue($february);

        $this->entry('2026-03-10', 60);
        $this->travelTo(Carbon::parse('2026-04-02 12:00:00'));
        [$march] = $this->month('2026-03-01', '2026-03-31');

        $this->assertSame(
            1.0,
            (float) $january->fresh()?->hours_billed_at_rate
                + (float) $february->fresh()?->hours_billed_at_rate
                + (float) $march->hours_billed_at_rate,
        );
    }

    /**
     * A voided earlier draft charged nothing, so the debt it would have billed
     * is still owed and the next month bills it.
     */
    public function test_a_voided_earlier_draft_is_not_counted(): void
    {
        [$january] = $this->month('2026-01-01', '2026-01-31');
        app(InvoiceLifecycleService::class)->void($january, $this->workspace, 'Synthetic void');

        [$february, $statement] = $this->month('2026-02-01', '2026-02-28');

        $this->assertSame(1.0, $statement->catchUpBilledHours);
        $this->assertSame(1.0, (float) $february->hours_billed_at_rate);
    }

    /** A later draft is not an earlier month's charge: regenerating January ignores February's draft. */
    public function test_regenerating_the_earlier_draft_ignores_the_later_one(): void
    {
        $this->month('2026-01-01', '2026-01-31');
        $this->month('2026-02-01', '2026-02-28');

        [$january, $statement] = $this->month('2026-01-01', '2026-01-31');

        $this->assertSame(1.0, $statement->catchUpBilledHours);
        $this->assertSame(1.0, (float) $january->hours_billed_at_rate);
    }

    /**
     * February was sized against January's charge, so it cannot be issued
     * while that charge could still be discarded.
     */
    public function test_the_later_invoice_cannot_be_issued_before_the_draft_it_relies_on(): void
    {
        [$january] = $this->month('2026-01-01', '2026-01-31');
        [$february] = $this->month('2026-02-01', '2026-02-28');

        try {
            $this->issue($february);
            $this->fail('February was issued ahead of the January draft it relies on');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString((string) $january->invoice_number, $refusal->getMessage());
        }
        $this->assertSame('draft', $february->fresh()?->status);

        $this->issue($january);
        $this->issue($february);
        $this->assertSame('issued', $february->fresh()?->status);
    }

    /** Nor can the draft a later invoice relies on be discarded or voided under it. */
    public function test_the_draft_a_later_invoice_relies_on_cannot_be_discarded(): void
    {
        [$january] = $this->month('2026-01-01', '2026-01-31');
        [$february] = $this->month('2026-02-01', '2026-02-28');
        $lifecycle = app(InvoiceLifecycleService::class);

        foreach ([
            fn () => $lifecycle->discardDraft($january, $this->workspace, 'Synthetic discard'),
            fn () => $lifecycle->void($january, $this->workspace, 'Synthetic void'),
        ] as $discard) {
            try {
                $discard();
                $this->fail('The January draft was discarded under February');
            } catch (DomainException $refusal) {
                $this->assertStringContainsString((string) $february->invoice_number, $refusal->getMessage());
            }
            $this->assertSame('draft', $january->fresh()?->status);
        }

        // Discarding the dependent invoice first releases it; February is then
        // rebuilt without the charge and bills the minimum itself.
        $lifecycle->discardDraft($february, $this->workspace, 'Synthetic discard');
        $lifecycle->discardDraft($january, $this->workspace, 'Synthetic discard');
        $this->assertSame('void', $january->fresh()?->status);
    }

    /**
     * Changing the agreement's cadence after its monthly drafts exist does not
     * lift either guard: the later draft was still sized against the earlier.
     */
    public function test_changing_the_agreement_cadence_keeps_the_dependency(): void
    {
        [$january] = $this->month('2026-01-01', '2026-01-31');
        [$february] = $this->month('2026-02-01', '2026-02-28');
        $this->agreement->forceFill(['billing_cadence' => 'quarterly'])->save();

        try {
            $this->issue($february);
            $this->fail('February was issued ahead of the January draft once the cadence changed');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString((string) $january->invoice_number, $refusal->getMessage());
        }

        $this->expectException(DomainException::class);
        app(InvoiceLifecycleService::class)->discardDraft($january, $this->workspace, 'Synthetic discard');
    }

    /**
     * Discarding or voiding a cadence draft takes the agreement lock that
     * monthly generation holds while it reads which earlier drafts to count,
     * and takes it before the invoice's, as every path ranks them. Otherwise a
     * generation could count a draft that a concurrent discard then voids
     * without seeing the later invoice it produced.
     */
    public function test_discarding_a_cadence_draft_locks_its_agreement_first(): void
    {
        foreach (['discardDraft', 'void'] as $operation) {
            [$january] = $this->month('2026-01-01', '2026-01-31');
            $tables = [];
            $agreementReads = [];
            DB::listen(function (QueryExecuted $query) use (&$tables, &$agreementReads): void {
                if (preg_match('/^select .* from ["`]?(client_agreements|client_invoices)["`]?/i', $query->sql, $match) === 1) {
                    $tables[] = $match[1];
                    if ($match[1] === 'client_agreements' && $agreementReads === []) {
                        $agreementReads[] = $query;
                    }
                }
            });

            app(InvoiceLifecycleService::class)->{$operation}($january, $this->workspace, 'Synthetic discard');

            $this->assertSame('client_agreements', $tables[0] ?? null, $operation.' reads the invoice before locking the agreement');
            // Tenant-scoped: a legacy invoice naming another workspace's
            // agreement cannot take that tenant's lock.
            $this->assertMatchesRegularExpression('/["`]?workspace_id["`]? = \\?/', $agreementReads[0]->sql);
            $this->assertContains($this->workspace->id, $agreementReads[0]->bindings);
            $this->assertSame('void', $january->fresh()?->status);
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }
    }

    /**
     * A backdated line widens the later invoice's period back into the earlier
     * month. The guards compare periods by end, as the ledger places a charge,
     * so the widening does not hide the draft February was sized against.
     */
    public function test_a_later_period_widened_backwards_keeps_the_dependency(): void
    {
        [$january] = $this->month('2026-01-01', '2026-01-31');
        [$february] = $this->month('2026-02-01', '2026-02-28');
        ClientInvoice::query()->whereKey($february->id)->update(['service_period_start' => '2026-01-20']);

        try {
            $this->issue($february->fresh() ?? $february);
            $this->fail('A widened February was issued ahead of the January draft it relies on');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString((string) $january->invoice_number, $refusal->getMessage());
        }

        $this->expectException(DomainException::class);
        app(InvoiceLifecycleService::class)->discardDraft($january, $this->workspace, 'Synthetic discard');
    }

    /** An earlier draft's catch-up that cannot be known is refused, not read as zero. */
    public function test_an_earlier_draft_with_unknown_catch_up_is_refused(): void
    {
        [$january] = $this->month('2026-01-01', '2026-01-31');
        ClientInvoice::query()->whereKey($january->id)->update(['hours_billed_at_rate' => null]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage((string) $january->invoice_number);

        app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28'), $this->agreement,
        );
    }

    /**
     * A malformed row naming this company and agreement under another
     * workspace cannot lend its catch-up to this ledger.
     */
    public function test_another_tenants_draft_is_not_counted(): void
    {
        $this->month('2026-01-01', '2026-01-31');
        $otherWorkspace = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere-earlier-draft']);
        // Unstorable since #113; written with enforcement suspended so the
        // overlay query's own scoping stays the subject of the test.
        $this->writingLegacyCrossTenantRows(fn (): ClientInvoice => ClientInvoice::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $this->agreement->id,
            'invoice_number' => 'X-'.uniqid(),
            'status' => 'draft',
            'currency' => 'USD',
            'invoice_kind' => 'cadence_period',
            'service_period_start' => '2026-01-01',
            'service_period_end' => '2026-01-31',
            'hours_billed_at_rate' => 5,
            'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]));

        [, $statement] = $this->month('2026-02-01', '2026-02-28');

        $this->assertSame(1.0, $statement->ordinaryAppliedToWorkPool, 'Only this tenant\'s January hour');
        $this->assertSame(0.0, $statement->catchUpBilledHours);
    }

    private function issue(ClientInvoice $invoice): void
    {
        app(InvoiceLifecycleService::class)->issue($invoice, $this->workspace);
    }

    /** @return array{ClientInvoice, InvoiceHoursStatement} */
    private function month(string $from, string $to): array
    {
        $invoice = app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse($from), Carbon::parse($to), $this->agreement,
        );
        $statement = $invoice->hoursStatement();
        $this->assertInstanceOf(InvoiceHoursStatement::class, $statement);
        $this->assertNull($statement->retainerSoldBy, 'The fixture is an ordinary month');

        return [$invoice, $statement];
    }

    private function entry(string $workedOn, int $minutes): void
    {
        ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id, 'user_id' => $this->member->id,
            'worked_on' => $workedOn, 'minutes' => $minutes, 'description' => 'Synthetic ledger work',
            'is_billable' => true, 'status' => 'approved', 'billing_rate_amount' => 15000, 'currency' => 'USD',
        ]);
    }
}
