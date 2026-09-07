<?php

namespace Tests\Feature\Billing;

use App\Http\Requests\Billing\StoreInvoiceRequest;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\ServicePeriodRequirement;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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

        // Not representable in `InvoiceKind`, and handled by
        // `ServicePeriodRequirement` rather than by the enum.
        yield 'null kind, no end' => [null, '2024-01-01', null];
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

        // The two mutations `issue()` performs that this test's earlier version
        // only claimed to cover. That they are reachable at all is proved by
        // `test_a_successful_issue_does_mutate_that_same_fixture`, which issues
        // the identical fixture on a complete invoice and watches both change.
        ['credit' => $credit, 'entry' => $entry] = $this->attachCreditAndTime($invoice);
        $totalBefore = (int) $invoice->refresh()->total_amount;
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

        $this->assertSame(-2500, (int) $credit->refresh()->total_amount, 'The credit line is not trimmed');
        $this->assertSame(1, $fresh->lines()->where('type', 'credit')->count(), 'The credit line is not deleted');
        $this->assertSame($totalBefore, (int) $fresh->total_amount, 'The totals are not recomputed');
        $this->assertSame('approved', $entry->refresh()->status, 'Approved time is not marked invoiced');
    }

    /**
     * The control for the test above, and the reason it is worth anything.
     *
     * A fixture that no successful run would touch proves nothing when a
     * refusal leaves it alone. So the same credit line and the same approved
     * entry are put on a *complete* invoice and issued: the credit line is
     * deleted, the totals move, and the entry becomes `invoiced`. Those are the
     * three mutations the refusal above is asserting the absence of.
     */
    public function test_a_successful_issue_does_mutate_that_same_fixture(): void
    {
        $invoice = $this->draft('cadence_period', '2024-01-01', '2024-01-31');
        $credit = $this->attachCreditAndTime($invoice);
        $entry = $credit['entry'];
        $totalBefore = (int) $invoice->refresh()->total_amount;

        app(InvoiceLifecycleService::class)->issue($invoice->refresh(), $this->workspace);

        $fresh = $invoice->refresh();
        $this->assertSame('issued', $fresh->status);
        $this->assertSame(
            0,
            $fresh->lines()->where('type', 'credit')->count(),
            'With no overpayment available the credit line is deleted, so the refusal above has something to prevent',
        );
        $this->assertNotSame($totalBefore, (int) $fresh->total_amount, 'The totals are recomputed');
        $this->assertSame('invoiced', $entry->refresh()->status);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.issued')->count());
    }

    /**
     * @return array{credit: ClientInvoiceLine, entry: ClientTimeEntry}
     */
    private function attachCreditAndTime(ClientInvoice $invoice): array
    {
        // The company holds no overpayment, so `capOverpaymentCreditAtIssue()`
        // finds nothing available and deletes this line outright rather than
        // trimming it - a mutation large enough to be unmistakable either way.
        $credit = $invoice->lines()->create([
            'workspace_id' => $this->workspace->id,
            'type' => 'credit',
            'description' => 'Applied overpayment',
            'quantity' => 1,
            'unit_amount' => -2500,
            'tax_amount' => 0,
            'total_amount' => -2500,
            'sort_order' => 1,
        ]);
        // Carried on the invoice as well, the way an applied credit really is -
        // otherwise the stored total never included it, and the recalculation a
        // successful issue performs would land back on the same number and look
        // like no recalculation at all.
        $invoice->forceFill(['total_amount' => 7500, 'balance_amount' => 7500])->save();

        $entry = $this->approvedEntry();
        $invoice->lines()->where('type', 'adjustment')->first()?->timeEntries()->attach($entry->id, [
            'workspace_id' => $this->workspace->id,
        ]);

        return ['credit' => $credit, 'entry' => $entry];
    }

    private function approvedEntry(): ClientTimeEntry
    {
        $project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Undated Project',
        ]);

        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $project->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => '2024-01-15',
            'minutes' => 60,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
            'billing_rate_amount' => 20000,
            'billing_rate_source' => 'agreement',
        ]);
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

        $unlinked = false;
        $linked = true;

        $this->assertSame(ServicePeriodRequirement::Required, ServicePeriodRequirement::for(null, $unlinked));
        $this->assertSame(ServicePeriodRequirement::Required, ServicePeriodRequirement::for('cadence_period', $unlinked));
        $this->assertSame(ServicePeriodRequirement::Exempt, ServicePeriodRequirement::for('ad_hoc', $unlinked));
        $this->assertSame(ServicePeriodRequirement::UnsupportedKind, ServicePeriodRequirement::for('reconciliation', $unlinked));

        // Ownership overrides the kind exemption, mirroring the resolver: its
        // `cycleGuardExclusions()` branch is reached only when the row names no
        // schedule.
        $this->assertSame(ServicePeriodRequirement::Required, ServicePeriodRequirement::for('ad_hoc', $linked));
        $this->assertSame(ServicePeriodRequirement::Required, ServicePeriodRequirement::for('cadence_period', $linked));

        // An unrecognised kind is refused before ownership is consulted: it may
        // not be issued at all, so there is nothing for the link to change.
        $this->assertSame(ServicePeriodRequirement::UnsupportedKind, ServicePeriodRequirement::for('reconciliation', $linked));

        $this->assertTrue(ServicePeriodRequirement::Required->requiresBothBoundaries());
        $this->assertFalse(ServicePeriodRequirement::Exempt->requiresBothBoundaries());
    }

    /**
     * An unrecognised kind cannot be issued at all, complete period or not.
     *
     * Not a tightening for tidiness. The application gives such a row two
     * incompatible identities: `invoiceKindValue()` answers `cadence_period`,
     * so the model and every activity payload call it a cadence invoice, while
     * `ClientInvoicingService::cycleAlreadySold()` matches
     * `invoice_kind IS NULL OR invoice_kind = 'cadence_period'` on the raw
     * column and does not see it. Issued, it is a cadence invoice that is
     * invisible to the guard stopping a later correction from selling the same
     * retainer and recurring items a second time - and the service-period
     * overlap guard does not cover that case, which is why the cycle guard
     * exists separately.
     *
     * The complete period is the point of the fixture: an implementation that
     * only required the dates would issue this.
     */
    #[DataProvider('unrecognisedKinds')]
    public function test_an_unrecognised_kind_cannot_be_issued_even_with_a_complete_period(string $kind): void
    {
        $invoice = $this->draft($kind, '2024-01-01', '2024-01-31');
        $activitiesBefore = ClientCompanyActivity::query()->count();

        try {
            app(InvoiceLifecycleService::class)->issue($invoice, $this->workspace);
            $this->fail('An invoice of an unrecognised kind must not be issued.');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString('unrecognised invoice kind', $refusal->getMessage());
        }

        $this->assertSame('draft', $invoice->refresh()->status);
        $this->assertNull($invoice->issued_at);
        $this->assertFalse((bool) $invoice->is_visible_to_client);
        $this->assertSame($activitiesBefore, ClientCompanyActivity::query()->count());
        $this->assertSame(0, ClientCompanyActivity::query()->where('action', 'invoice.issued')->count());
    }

    public static function unrecognisedKinds(): iterable
    {
        yield 'a plausible one' => ['reconciliation'];
        yield 'a versioned one' => ['cadence-v2'];
        yield 'the empty string' => [''];
    }

    /**
     * Ad hoc is exempt only while the row names no schedule.
     *
     * `BillingPeriodCollisionResolver` reaches its kind exemption only for an
     * unlinked row - "a row naming this schedule is this schedule's whatever
     * kind it carries" - so a schedule-linked ad-hoc invoice with no period is
     * read there as unbounded, established as the schedule's, and refused.
     * Issuing one manufactures a live row that halts the schedule's next run,
     * which is the population the guard exists to keep out.
     *
     * The link is set as a bare id rather than against a real schedule row
     * because `client_invoices.client_billing_schedule_id` carries no foreign
     * key - an imported or hand-edited row reaches exactly this shape, and the
     * requirement reads only whether the column is set.
     */
    #[DataProvider('incompleteBoundaries')]
    public function test_a_schedule_linked_ad_hoc_draft_missing_a_boundary_cannot_be_issued(?string $start, ?string $end): void
    {
        $invoice = $this->draft('ad_hoc', $start, $end);
        $invoice->forceFill(['client_billing_schedule_id' => 4242])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('An invoice naming a billing schedule states');

        app(InvoiceLifecycleService::class)->issue($invoice->refresh(), $this->workspace);
    }

    public static function incompleteBoundaries(): iterable
    {
        yield 'no start' => [null, '2024-01-31'];
        yield 'no end' => ['2024-01-01', null];
        yield 'neither' => [null, null];
    }

    /** The control for the pair above: unlinked, it stays exempt. */
    public function test_an_unlinked_ad_hoc_draft_without_a_period_still_issues(): void
    {
        $issued = app(InvoiceLifecycleService::class)
            ->issue($this->draft('ad_hoc', null, null), $this->workspace);

        $this->assertSame('issued', $issued->status);
        $this->assertNull($issued->client_billing_schedule_id);
    }

    /**
     * Both boundaries present is necessary and not sufficient.
     *
     * `possiblyOverlapping()` asks `service_period_start <= $end` and
     * `service_period_end >= $start`. A row whose start follows its end fails
     * one of those for *every* period, including the two it sits between - so
     * it leaves the collision resolver entirely, and the unique index does not
     * object because the reversed tuple differs from either valid one.
     * Ordinary invoices can then be generated beside it for work it charged.
     *
     * Asked of ad hoc too: a period may be absent, but one that is stated has
     * to mean something.
     */
    #[DataProvider('everySupportedKind')]
    public function test_a_reversed_service_period_cannot_be_issued(?string $kind): void
    {
        $invoice = $this->draft($kind, '2026-02-01', '2026-01-31');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The service period start cannot follow the service period end');

        app(InvoiceLifecycleService::class)->issue($invoice, $this->workspace);
    }

    public static function everySupportedKind(): iterable
    {
        yield 'cadence' => ['cadence_period'];
        yield 'interim' => ['interim_overage'];
        yield 'terminal' => ['terminal'];
        yield 'ad hoc' => ['ad_hoc'];
        yield 'null kind' => [null];
    }

    /**
     * Every refusal names a repair this application can actually perform.
     *
     * Asserting the wording is not enough, and the first version of this test
     * only did that - it compared the message to a literal copied from the same
     * source string, which pins the sentence and proves nothing about the thing
     * it promises. That is exactly how "Give it a service period start and
     * end." survived, and then how "correct its stored period through the
     * audited administrative repair path" survived: **nothing** in the
     * application writes either boundary on an existing row.
     *
     * So this asserts the endpoint the message names actually offers what the
     * message says it does.
     */
    public function test_each_refusal_names_a_repair_that_exists(): void
    {
        $lifecycle = app(InvoiceLifecycleService::class);

        $unlinked = $this->refusalFor($lifecycle, $this->draft('cadence_period', '2024-01-01', null));
        $reversed = $this->refusalFor($lifecycle, $this->draft('ad_hoc', '2026-02-01', '2026-01-31'));
        $unsupported = $this->refusalFor($lifecycle, $this->draft('reconciliation', '2024-01-01', '2024-01-31'));

        $linkedDraft = $this->draft('cadence_period', '2024-01-01', null);
        $linkedDraft->forceFill(['client_billing_schedule_id' => 4242])->save();
        $linked = $this->refusalFor($lifecycle, $linkedDraft->refresh());

        // The unlinked remedy: create the invoice again, stating the period.
        // Only true while the create endpoint accepts both boundaries.
        $rules = (new StoreInvoiceRequest)->rules();
        $this->assertArrayHasKey('service_period_start', $rules);
        $this->assertArrayHasKey('service_period_end', $rules);
        foreach ([$unlinked, $reversed, $unsupported] as $message) {
            $this->assertStringContainsString('Discard this draft and create it again', $message);
        }

        // The linked remedy is different precisely because that endpoint cannot
        // name a schedule, so recreating by hand would drop the link.
        $this->assertArrayNotHasKey('client_billing_schedule_id', $rules);
        $this->assertArrayNotHasKey('client_agreement_id', $rules);
        $this->assertStringContainsString('re-run its billing schedule', $linked);
        $this->assertTrue(
            Route::has('svc.billing.schedules.generate'),
            'The linked remedy names a schedule re-run, so that route has to exist',
        );

        // Discarding is the step both remedies open with, and the exit
        // `BillingScheduleService` already offers on a halted schedule. It has
        // no kind or period guard of its own, so it stays available for every
        // draft refused above.
        foreach ([$unlinked, $reversed, $unsupported, $linked] as $message) {
            $this->assertStringStartsWith('Discard this draft', substr($message, strpos($message, 'Discard this draft')));
            $this->assertStringNotContainsString('Give it a service period', $message);
            $this->assertStringNotContainsString('administrative repair path', $message);
        }

        $discardable = $this->draft('reconciliation', '2024-01-01', '2024-01-31');
        $this->assertSame(
            'void',
            $lifecycle->discardDraft($discardable, $this->workspace, 'Refused at issue')->status,
            'A draft this refuses to issue must still be discardable, or the remedy is not a remedy',
        );
    }

    private function refusalFor(InvoiceLifecycleService $lifecycle, ClientInvoice $invoice): string
    {
        try {
            $lifecycle->issue($invoice, $this->workspace);
            $this->fail('Invoice '.$invoice->invoice_number.' must not be issued.');
        } catch (DomainException $refusal) {
            return $refusal->getMessage();
        }
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
