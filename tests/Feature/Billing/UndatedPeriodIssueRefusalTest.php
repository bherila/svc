<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\ServicePeriodRequirement;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * An invoice that claims a span of time may not be issued without stating it.
 *
 * #250 closed the generation half: `BillingPeriodCollisionResolver` refuses a
 * schedule run when a row it must place states no complete service period,
 * because a null answers UNKNOWN to every date comparison and
 * `billing_schedule_service_period_unique` cannot reject the duplicate that
 * follows - a unique index does not constrain a null.
 *
 * That stops the money mutation and not the row. This is the other half: the
 * transition where such a row starts asking a client for money. Every issuance
 * door - browser, console, API, MCP - arrives at
 * {@see InvoiceLifecycleService::issue()}, so the rule lives there rather than
 * on any one of them.
 *
 * These tests pin the *transition*, not the helper. A matrix that agrees with
 * itself proves nothing about what `issue()` does with it.
 */
final class UndatedPeriodIssueRefusalTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Undated', 'slug' => 'undated']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Undated Client',
            'slug' => 'undated-client',
        ]);
    }

    /**
     * Both boundaries, each on its own.
     *
     * A row stating a start and no end is exactly as unplaceable as one stating
     * neither - the guards compare both ends - so a test that only ever removes
     * both would pass against an implementation checking only the start.
     */
    public static function unplaceableCombinations(): iterable
    {
        foreach (['cadence_period', 'interim_overage', 'terminal'] as $kind) {
            yield $kind.', no start' => [$kind, null, '2024-01-31'];
            yield $kind.', no end' => [$kind, '2024-01-01', null];
            yield $kind.', neither' => [$kind, null, null];
        }

        // Not representable in `InvoiceKind`, and both handled by
        // `ServicePeriodRequirement` rather than by the enum.
        yield 'null kind, no end' => [null, '2024-01-01', null];
        yield 'unrecognised kind, no start' => ['reconciliation', null, '2024-01-31'];
    }

    #[DataProvider('unplaceableCombinations')]
    public function test_a_period_invoice_missing_a_boundary_cannot_be_issued(?string $kind, ?string $start, ?string $end): void
    {
        $invoice = $this->draft($kind, $start, $end);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cannot be issued');

        app(InvoiceLifecycleService::class)->issue($invoice, $this->workspace);
    }

    /**
     * The refusal is total: the draft is left exactly as it was found.
     *
     * `issue()` spends overpayment credit, takes a company lock, marks approved
     * time entries invoiced, flips visibility and records an activity. A guard
     * that threw after any of those would leave a half-issued row behind, which
     * is worse than the defect it prevents - so this asserts the state rather
     * than trusting the ordering to stay where it was put.
     */
    public function test_a_refusal_leaves_the_draft_untouched(): void
    {
        $invoice = $this->draft('cadence_period', '2024-01-01', null);
        $activitiesBefore = ClientCompanyActivity::query()->count();

        try {
            app(InvoiceLifecycleService::class)->issue($invoice, $this->workspace);
            $this->fail('An undated cadence invoice must not be issued.');
        } catch (DomainException) {
            // The state below is the assertion.
        }

        $fresh = $invoice->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->issued_at);
        $this->assertNull($fresh->issue_date);
        $this->assertFalse((bool) $fresh->is_visible_to_client);
        $this->assertSame(
            $activitiesBefore,
            ClientCompanyActivity::query()->count(),
            'A refused issue records nothing',
        );
        $this->assertSame(
            0,
            ClientCompanyActivity::query()->where('action', 'invoice.issued')->count(),
        );
    }

    /** Ad hoc bills a thing rather than a span, and stays exempt. */
    public function test_an_ad_hoc_draft_with_no_period_still_issues(): void
    {
        $issued = app(InvoiceLifecycleService::class)
            ->issue($this->draft('ad_hoc', null, null), $this->workspace);

        $this->assertSame('issued', $issued->status);
    }

    #[DataProvider('periodKinds')]
    public function test_a_complete_invoice_of_each_required_kind_still_issues(?string $kind): void
    {
        $issued = app(InvoiceLifecycleService::class)
            ->issue($this->draft($kind, '2024-01-01', '2024-01-31'), $this->workspace);

        $this->assertSame('issued', $issued->status);
    }

    public static function periodKinds(): iterable
    {
        yield 'cadence' => ['cadence_period'];
        yield 'interim' => ['interim_overage'];
        yield 'terminal' => ['terminal'];
        yield 'null kind' => [null];
        yield 'unrecognised kind' => ['reconciliation'];
    }

    /**
     * A row that already took money keeps its idempotent `issue()`.
     *
     * The charged-status return above this guard is what makes re-issuing a
     * paid invoice a no-op rather than an error, and existing malformed live
     * rows belong to the census and the repair path. Turning that no-op into an
     * exception would break callers that re-issue defensively, for a row the
     * new invariant was never meant to govern.
     */
    public function test_an_already_charged_undated_invoice_is_still_idempotent(): void
    {
        $invoice = $this->draft('cadence_period', null, null);
        $invoice->forceFill(['status' => 'paid'])->save();

        $returned = app(InvoiceLifecycleService::class)->issue($invoice, $this->workspace);

        $this->assertSame('paid', $returned->status);
        $this->assertSame($invoice->id, $returned->id);
    }

    /**
     * The matrix itself, so a kind added later has to answer this question.
     *
     * `requiresCompleteServicePeriod()` is an exhaustive `match`, so a fifth
     * case is a PHPStan error rather than a silent "exempt" - but that only
     * holds while the arms stay as written, which is what this asserts.
     */
    public function test_the_requirement_matrix(): void
    {
        $this->assertTrue(InvoiceKind::CadencePeriod->requiresCompleteServicePeriod());
        $this->assertTrue(InvoiceKind::InterimOverage->requiresCompleteServicePeriod());
        $this->assertTrue(InvoiceKind::Terminal->requiresCompleteServicePeriod());
        $this->assertFalse(InvoiceKind::AdHoc->requiresCompleteServicePeriod());

        $this->assertSame(ServicePeriodRequirement::Required, ServicePeriodRequirement::for(null));
        $this->assertSame(ServicePeriodRequirement::Required, ServicePeriodRequirement::for('cadence_period'));
        $this->assertSame(ServicePeriodRequirement::Exempt, ServicePeriodRequirement::for('ad_hoc'));
        $this->assertSame(ServicePeriodRequirement::Undecidable, ServicePeriodRequirement::for('reconciliation'));

        // Undecidable requires the period rather than refusing outright: the
        // invariant is about the span, not about kind hygiene.
        $this->assertTrue(ServicePeriodRequirement::Undecidable->requiresBothBoundaries());
        $this->assertFalse(ServicePeriodRequirement::Exempt->requiresBothBoundaries());
    }

    private function draft(?string $kind, ?string $start, ?string $end): ClientInvoice
    {
        $this->sequence++;

        // Through `createDraft()`, which deliberately still accepts a missing
        // boundary: an incomplete draft has charged nobody and must keep being
        // creatable, or the interim generator cannot raise the correctly placed
        // replacement beside one. The kind is then forced, because `createDraft()`
        // infers it from the schedule link and cannot express an unrecognised value.
        $invoice = app(InvoiceLifecycleService::class)->createDraft(
            $this->workspace,
            $this->company,
            [
                'invoice_number' => 'UND-'.$this->sequence,
                'currency' => 'USD',
                'service_period_start' => $start,
                'service_period_end' => $end,
            ],
            [['type' => 'adjustment', 'description' => 'Work', 'quantity' => 1, 'unit_amount' => 10000]],
        );

        $invoice->forceFill(['invoice_kind' => $kind])->save();

        return $invoice->fresh() ?? $invoice;
    }
}
