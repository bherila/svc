<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingScheduleService;
use App\Services\Billing\InvoiceDocumentService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\StripePaymentIntentService;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Route::has('svc.billing.stripe.webhook')) {
            require base_path('routes/billing.php');
        }
    }

    public function test_issue_payment_partial_payment_and_reopen_after_refund_are_idempotent(): void
    {
        [$owner, $workspace, $company] = $this->tenant();
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, $this->invoiceData(), [$this->line()]);
        $service->issue($invoice, $workspace);

        $payment = $service->applyPayment($invoice, [
            'amount' => 4000, 'currency' => 'USD', 'method' => 'ach',
            'status' => 'succeeded', 'idempotency_key' => 'synthetic-payment-1',
        ], $workspace);
        $samePayment = $service->applyPayment($invoice, [
            'amount' => 4000, 'currency' => 'USD', 'method' => 'ach',
            'status' => 'succeeded', 'idempotency_key' => 'synthetic-payment-1',
        ], $workspace);

        $this->assertSame($payment->id, $samePayment->id);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertSame(6000, $invoice->fresh()->balance_amount);

        $service->setPaymentStatus($payment, 'refunded', $workspace);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->paid_amount);
        $this->assertDatabaseCount('client_invoice_payments', 1);
        $this->assertSame($owner->id, $workspace->memberships()->first()->user_id);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.payment_received')->count());
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.payment_refunded')->count());
        $this->assertSame(0, ClientCompanyActivity::query()->where('action', 'invoice.marked_paid')->count());
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.issued')->count());
    }

    public function test_issuing_an_undated_invoice_uses_the_workspace_calendar_date(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-08-30 05:30:00 UTC'));
        try {
            [, $workspace, $company] = $this->tenant();
            $workspace->forceFill(['timezone' => 'America/Los_Angeles'])->save();
            $service = app(InvoiceLifecycleService::class);
            $invoice = $service->createDraft($workspace, $company, [
                'invoice_number' => 'INV-WORKSPACE-DATE',
                'currency' => 'USD',
            ], [$this->line()]);

            $issued = $service->issue($invoice, $workspace);

            $this->assertSame('2026-08-29', $issued->issue_date?->toDateString());
            $this->assertSame('2026-08-29', $issued->due_date?->toDateString());
        } finally {
            Date::setTestNow();
        }
    }

    public function test_partial_refund_reopens_only_the_refunded_balance(): void
    {
        [, $workspace, $company] = $this->tenant();
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, $this->invoiceData(), [$this->line()]);
        $service->issue($invoice, $workspace);
        $payment = $service->applyPayment($invoice, [
            'amount' => 10000,
            'currency' => 'USD',
            'method' => 'stripe',
            'status' => 'succeeded',
            'idempotency_key' => 'synthetic-full-payment',
        ], $workspace);

        $service->setRefundedAmount($payment, 2500, $workspace);

        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertSame(7500, $invoice->fresh()->paid_amount);
        $this->assertSame(2500, $invoice->fresh()->balance_amount);
        $this->assertSame(2500, $payment->fresh()->refunded_amount);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.marked_paid')->count());
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.payment_refunded')->count());
    }

    public function test_client_cannot_view_a_draft_even_when_visibility_flag_is_set(): void
    {
        [, $workspace, $company] = $this->tenant();
        $client = User::factory()->create();
        $company->portalUsers()->attach($client, ['role' => 'client']);
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft(
            $workspace,
            $company,
            [...$this->invoiceData(), 'is_visible_to_client' => true, 'notes' => 'Internal synthetic note'],
            [$this->line()],
        );

        $this->actingAs($client)
            ->getJson("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}")
            ->assertForbidden();

        $service->issue($invoice, $workspace);
        $this->actingAs($client)
            ->getJson("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}")
            ->assertOk()
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.workspace_id')
            ->assertJsonMissingPath('data.notes');
    }

    public function test_tenant_mismatch_is_not_visible_or_mutable(): void
    {
        [, $workspace, $company] = $this->tenant();
        [, $otherWorkspace, $otherCompany] = $this->tenant('Other Workspace');
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, $this->invoiceData(), [$this->line()]);

        $this->actingAs(User::query()->whereHas('workspaces', fn ($q) => $q->whereKey($otherWorkspace->id))->firstOrFail())
            ->getJson("/workspaces/{$otherWorkspace->public_id}/invoices/{$invoice->public_id}")
            ->assertNotFound();
        $this->expectException(ModelNotFoundException::class);
        $service->issue($invoice, $otherWorkspace);
        $this->assertSame($otherCompany->workspace_id, $otherWorkspace->id);
    }

    public function test_void_invoice_rejects_new_payments(): void
    {
        [, $workspace, $company] = $this->tenant();
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, $this->invoiceData(), [$this->line()]);
        $service->issue($invoice, $workspace);
        $service->void($invoice, $workspace);

        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.voided')->count());

        $this->expectException(DomainException::class);
        $service->applyPayment($invoice, ['amount' => 100, 'currency' => 'USD', 'method' => 'cash'], $workspace);
    }

    public function test_schedule_generation_is_idempotent_per_service_period(): void
    {
        [, $workspace, $company] = $this->tenant('Schedule Workspace');
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic agreement',
            'currency' => 'USD', 'billing_cadence' => 'monthly', 'status' => 'active', 'starts_on' => '2026-01-01',
        ]);
        $schedule = ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'cadence' => 'monthly', 'next_run_on' => '2026-08-01', 'due_days' => 14, 'currency' => 'USD',
            'line_template' => [$this->line()],
        ]);

        $service = app(BillingScheduleService::class);
        $service->generateDue($schedule, CarbonImmutable::parse('2026-09-01'));
        $service->generateDue($schedule->fresh(), CarbonImmutable::parse('2026-09-01'));

        $this->assertDatabaseCount('client_invoices', 2);
        $this->assertDatabaseCount('client_invoice_lines', 2);
        $this->assertSame(2, ClientInvoice::query()->where('client_billing_schedule_id', $schedule->id)->count());
    }

    /**
     * A schedule's own invoice, unlinked, still stops it billing the period again.
     *
     * `generateDue()` used to decide this with
     * `where('client_billing_schedule_id', $locked->id)` and two date matches.
     * SQL compares a null to a value as UNKNOWN, so an invoice for exactly that
     * period whose link was missing satisfied neither branch: the schedule
     * concluded the period was unbilled and raised - and issued - another
     * invoice for it. The unique index did not save it either, because a unique
     * index does not constrain a null.
     *
     * The guard now matches the tenant and the period first and reads the link
     * only to decide whose invoice it is, so an invoice that does not say which
     * schedule made it blocks. #219, #224.
     *
     * The rewind is the point of the fixture. `next_run_on` moves forward on
     * every generated period, so a schedule only re-asks about a period it has
     * already produced after a replay, a repair or a corrected cadence; that is
     * precisely when a hand-edited or migrated row is likely to be missing its
     * link. The control below runs the same rewind with the link intact and
     * produces nothing new.
     */
    public function test_an_unlinked_invoice_stops_a_schedule_billing_its_period_again(): void
    {
        [, $workspace, $company] = $this->tenant('Unlinked Schedule Workspace');
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic agreement',
            'currency' => 'USD', 'billing_cadence' => 'monthly', 'status' => 'active', 'starts_on' => '2026-01-01',
        ]);
        $schedule = ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'cadence' => 'monthly', 'next_run_on' => '2026-08-01', 'due_days' => 14, 'currency' => 'USD',
            'line_template' => [$this->line()],
        ]);
        $service = app(BillingScheduleService::class);
        // Refreshed first: `generateDue()` advances `next_run_on` on its own
        // locked copy, so this in-memory model is stale and a forceFill to the
        // value it already believes it holds would write nothing at all.
        $rewind = function () use ($schedule): ClientBillingSchedule {
            $schedule->refresh()->forceFill(['next_run_on' => '2026-08-01'])->save();

            return $schedule->fresh();
        };

        $service->generateDue($schedule, CarbonImmutable::parse('2026-08-15'));
        $this->assertDatabaseCount('client_invoices', 1);
        $august = ClientInvoice::query()->sole();

        // Control: asked about August again, the schedule finds its own invoice.
        $service->generateDue($rewind(), CarbonImmutable::parse('2026-08-15'));
        $this->assertDatabaseCount('client_invoices', 1);

        // Only the link goes. Both dates still name August exactly.
        $august->forceFill(['client_billing_schedule_id' => null])->save();
        $this->assertSame('2026-08-01', $august->refresh()->service_period_start?->format('Y-m-d'));
        $this->assertSame('2026-08-31', $august->service_period_end?->format('Y-m-d'));

        $service->generateDue($rewind(), CarbonImmutable::parse('2026-08-15'));
        $this->assertDatabaseCount('client_invoices', 1);
        $this->assertSame(
            1,
            ClientInvoice::query()->whereDate('service_period_start', '2026-08-01')->count(),
            'August is billed once: an invoice that does not name a schedule still covers the period',
        );
        $this->assertSame(
            ['issued'],
            ClientInvoice::query()->orderBy('id')->pluck('status')->all(),
        );
    }

    /**
     * Another schedule's period is not this schedule's to skip.
     *
     * The fail-closed reading above has an obvious over-correction: block on
     * *any* invoice for the tenant and period, and a company billed by two
     * schedules gets one of them silently stop. So an invoice that names a
     * different schedule is deliberately not a match - only one that names this
     * schedule, or names none at all, is.
     *
     * This is the direction that loses revenue rather than double-charging, so
     * it needs its own case: nothing else in the suite would notice if the
     * `orWhereNull` above were widened to drop the schedule clause entirely.
     */
    public function test_an_invoice_owned_by_another_schedule_does_not_block_this_one(): void
    {
        [, $workspace, $company] = $this->tenant('Two Schedule Workspace');
        // Two agreements, because a schedule is unique per agreement per
        // workspace - which is also why two schedules on one company is a real
        // shape rather than a contrived one.
        $schedules = collect(['First', 'Second'])->map(function (string $name) use ($workspace, $company): ClientBillingSchedule {
            $agreement = ClientAgreement::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => "Synthetic {$name} agreement",
                'currency' => 'USD', 'billing_cadence' => 'monthly', 'status' => 'active', 'starts_on' => '2026-01-01',
            ]);

            return ClientBillingSchedule::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
                'cadence' => 'monthly', 'next_run_on' => '2026-08-01', 'due_days' => 14, 'currency' => 'USD',
                'line_template' => [$this->line()],
            ]);
        });

        $service = app(BillingScheduleService::class);

        foreach ($schedules as $schedule) {
            $service->generateDue($schedule, CarbonImmutable::parse('2026-08-15'));
        }

        $this->assertDatabaseCount('client_invoices', 2);
        $this->assertSame(
            $schedules->pluck('id')->sort()->values()->all(),
            ClientInvoice::query()->orderBy('client_billing_schedule_id')
                ->pluck('client_billing_schedule_id')->map(fn ($id): int => (int) $id)->all(),
            'each schedule bills its own August',
        );
    }

    /**
     * An operator's ad-hoc invoice for the same dates is not this period's bill.
     *
     * `InvoiceKind::cycleGuardExclusions()` is a decision this codebase already
     * made and enforces in `ClientInvoicingService::assertNoOverlappingInvoice()`:
     * an interim or ad-hoc invoice must not block a cadence one, because
     * neither is tied to an agreement cycle. Both also leave
     * `client_billing_schedule_id` null, so reading *every* null as "unclaimed,
     * therefore blocking" quietly reversed that decision for the schedule path
     * - `generateDue()` would return the ad-hoc invoice as though it were the
     * cadence one and advance `next_run_on` past a period nothing had billed.
     *
     * The guard that exists to stop a double-charge would have caused lost
     * revenue instead, which is why the exclusion is asserted here against the
     * same list the sibling guard reads rather than restated as a literal.
     */
    public function test_an_ad_hoc_invoice_sharing_the_period_does_not_block_the_schedule(): void
    {
        [, $workspace, $company] = $this->tenant('Ad Hoc Overlap Workspace');
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic agreement',
            'currency' => 'USD', 'billing_cadence' => 'monthly', 'status' => 'active', 'starts_on' => '2026-01-01',
        ]);
        $schedule = ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'cadence' => 'monthly', 'next_run_on' => '2026-08-01', 'due_days' => 14, 'currency' => 'USD',
            'line_template' => [$this->line()],
        ]);

        // Dated to August exactly, so only the kind keeps it out of the guard.
        $adHoc = app(InvoiceLifecycleService::class)->createDraft(
            $workspace,
            $company,
            $this->invoiceData() + ['service_period_start' => '2026-08-01', 'service_period_end' => '2026-08-31'],
            [$this->line()],
        );

        $this->assertNull($adHoc->client_billing_schedule_id);
        $this->assertContains($adHoc->invoice_kind, InvoiceKind::cycleGuardExclusions());

        app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-08-15'));

        $this->assertSame(
            1,
            ClientInvoice::query()->where('client_billing_schedule_id', $schedule->id)->count(),
            'the schedule still bills August: an ad-hoc invoice bills a thing, not this period',
        );
        $this->assertDatabaseCount('client_invoices', 2);
    }

    /**
     * Another agreement's unlinked cadence invoice is not this schedule's period.
     *
     * The same over-correction as the two-schedules case, reached through the
     * other column. `ClientInvoicingService` creates cadence invoices with an
     * agreement and no schedule, so a company holding two agreements can carry
     * an unlinked cadence invoice for August that belongs to the *other* one.
     * Treating it as unclaimed stops this schedule billing August at all.
     *
     * A row naming no agreement is the opposite case and still blocks - there
     * is nowhere else to attribute it, and that is the fail-closed reading
     * #219 is about.
     */
    public function test_another_agreements_unlinked_invoice_does_not_block_this_schedule(): void
    {
        [, $workspace, $company] = $this->tenant('Two Agreement Workspace');
        $agreements = collect(['Billed', 'Scheduled'])->map(fn (string $name): ClientAgreement => ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => "Synthetic {$name} agreement",
            'currency' => 'USD', 'billing_cadence' => 'monthly', 'status' => 'active', 'starts_on' => '2026-01-01',
        ]));
        $schedule = ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreements[1]->id,
            'cadence' => 'monthly', 'next_run_on' => '2026-08-01', 'due_days' => 14, 'currency' => 'USD',
            'line_template' => [$this->line()],
        ]);

        // The other agreement's August, as `ClientInvoicingService` writes one:
        // a cadence kind, an agreement, and no schedule.
        app(InvoiceLifecycleService::class)->createDraft(
            $workspace,
            $company,
            $this->invoiceData() + [
                'client_agreement_id' => $agreements[0]->id,
                'invoice_kind' => InvoiceKind::CadencePeriod->value,
                'service_period_start' => '2026-08-01',
                'service_period_end' => '2026-08-31',
            ],
            [$this->line()],
        );

        app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-08-15'));

        $this->assertSame(
            1,
            ClientInvoice::query()->where('client_billing_schedule_id', $schedule->id)->count(),
            'the schedule bills its own agreement August, which the other agreement has not billed',
        );
        $this->assertDatabaseCount('client_invoices', 2);
    }

    /**
     * An invoice attributable to nobody blocks - while only one schedule exists.
     *
     * This is the fail-closed half of #219 in its narrowest honest form. A row
     * naming neither a schedule nor an agreement cannot be attributed anywhere
     * else, so with a single schedule on the company it is this schedule's
     * period and blocking it is right.
     *
     * Paired deliberately with the refusal below, which is the same fixture
     * plus a rival: the two together are what stop the blocking rule being
     * either dropped or widened, since each alone reads as the general case.
     */
    public function test_an_unattributed_invoice_blocks_the_only_schedule_that_could_own_it(): void
    {
        [, $workspace, $company] = $this->tenant('Sole Schedule Workspace');
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic agreement',
            'currency' => 'USD', 'billing_cadence' => 'monthly', 'status' => 'active', 'starts_on' => '2026-01-01',
        ]);
        $schedule = ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'cadence' => 'monthly', 'next_run_on' => '2026-08-01', 'due_days' => 14, 'currency' => 'USD',
            'line_template' => [$this->line()],
        ]);

        // The shape a migration leaves: a period, no kind, no agreement, no
        // schedule. Nothing in this application writes it - `createDraft()`
        // coalesces an absent *or null* kind to `ad_hoc`, so the null has to be
        // written afterwards rather than passed in, which is itself the proof
        // that only a migrated or hand-edited row can look like this.
        $lifecycle = app(InvoiceLifecycleService::class);
        $unattributed = $lifecycle->createDraft(
            $workspace,
            $company,
            $this->invoiceData() + [
                'service_period_start' => '2026-08-01',
                'service_period_end' => '2026-08-31',
            ],
            [$this->line()],
        );
        $unattributed->forceFill(['invoice_kind' => null])->save();

        // Issued, because *billed* is what this test is about. A draft covering
        // the period is a different verdict - it has charged nobody, so the
        // schedule neither bills it again nor advances past it - and that case
        // has its own test below.
        $lifecycle->issue($unattributed->fresh(), $workspace);

        app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-08-15'));

        $this->assertDatabaseCount('client_invoices', 1);
        $this->assertSame(
            0,
            ClientInvoice::query()->where('client_billing_schedule_id', $schedule->id)->count(),
            'August is already covered by the unattributed invoice, so nothing new is raised',
        );
        $this->assertSame('2026-09-01', $schedule->fresh()?->next_run_on?->toDateString());
    }

    /**
     * With a rival schedule, an unattributed invoice is refused, not guessed.
     *
     * The row matches every schedule the company has, and at most one of them
     * can be the one it covers. Treating it as unclaimed-therefore-blocking
     * makes a single invoice suppress two: both schedules return it, both
     * advance their own `next_run_on`, and at least one agreement goes unbilled
     * for a period nothing charged - the silent revenue loss this guard's
     * narrowing exists to prevent, arrived at from the other direction.
     *
     * Neither silent answer is available: billing anyway double-charges,
     * skipping loses a period. So it refuses, and the assertions below are
     * about the refusal being *safe* rather than merely loud - the transaction
     * rolls back, `next_run_on` does not move, and nothing is created, so the
     * run can simply be repeated once someone attributes the row.
     */
    public function test_an_unattributed_invoice_is_refused_when_a_rival_schedule_could_own_it(): void
    {
        [, $workspace, $company] = $this->tenant('Ambiguous Owner Workspace');
        $schedules = collect(['First', 'Second'])->map(function (string $name) use ($workspace, $company): ClientBillingSchedule {
            $agreement = ClientAgreement::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => "Synthetic {$name} agreement",
                'currency' => 'USD', 'billing_cadence' => 'monthly', 'status' => 'active', 'starts_on' => '2026-01-01',
            ]);

            return ClientBillingSchedule::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
                'cadence' => 'monthly', 'next_run_on' => '2026-08-01', 'due_days' => 14, 'currency' => 'USD',
                'line_template' => [$this->line()],
            ]);
        });

        $orphan = app(InvoiceLifecycleService::class)->createDraft(
            $workspace,
            $company,
            $this->invoiceData() + [
                'service_period_start' => '2026-08-01',
                'service_period_end' => '2026-08-31',
            ],
            [$this->line()],
        );
        // As above: the null kind is written, not passed, because `createDraft()`
        // coalesces a null to `ad_hoc`.
        $orphan->forceFill(['invoice_kind' => null])->save();

        try {
            app(BillingScheduleService::class)->generateDue($schedules[0], CarbonImmutable::parse('2026-08-15'));
            $this->fail('An invoice that could belong to either schedule must not be attributed to one of them silently.');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString($orphan->invoice_number, $refusal->getMessage());
            $this->assertStringContainsString('2026-08-01', $refusal->getMessage());
        }

        $this->assertDatabaseCount('client_invoices', 1);
        $this->assertSame(
            '2026-08-01',
            $schedules[0]->fresh()?->next_run_on?->format('Y-m-d'),
            'the refusal rolls back: a schedule that billed nothing must not move on',
        );

        // Attributed, the ambiguity is gone and the run succeeds - so the
        // refusal is a prompt to repair rather than a dead end.
        $orphan->forceFill(['client_agreement_id' => $schedules[1]->client_agreement_id])->save();
        app(BillingScheduleService::class)->generateDue($schedules[0]->fresh(), CarbonImmutable::parse('2026-08-15'));

        $this->assertSame(
            1,
            ClientInvoice::query()->where('client_billing_schedule_id', $schedules[0]->id)->count(),
            'once the row names the other agreement, this schedule bills its own August',
        );
    }

    /**
     * A foreign workspace's identical invoice neither blocks nor is returned.
     *
     * The guard now blocks on an invoice that names no schedule, so a row from
     * another tenant reaching this query would stop a paying client being
     * billed - and `generateDue()` returns the row it found, so it would hand
     * another tenant's invoice back with it. The fixture is the worst case on
     * purpose: same dates, same null link, same cadence kind.
     *
     * **What this does and does not prove.** It pins the behaviour, which is
     * what makes it worth keeping. It does *not* isolate the `workspace_id`
     * predicate: deleting that line leaves this test green, because
     * `client_company_id` is a global key and already separates the tenants.
     * The workspace clause is defence in depth against a future caller that
     * scopes differently, not the thing holding this case up, and saying so
     * here is cheaper than a later reader trusting a citation that proves
     * something narrower than it reads.
     */
    public function test_a_foreign_workspaces_invoice_does_not_block_generation(): void
    {
        [, $workspace, $company] = $this->tenant('Own Schedule Workspace');
        [, $otherWorkspace, $otherCompany] = $this->tenant('Foreign Invoice Workspace');
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic agreement',
            'currency' => 'USD', 'billing_cadence' => 'monthly', 'status' => 'active', 'starts_on' => '2026-01-01',
        ]);
        $schedule = ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'cadence' => 'monthly', 'next_run_on' => '2026-08-01', 'due_days' => 14, 'currency' => 'USD',
            'line_template' => [$this->line()],
        ]);

        $foreign = app(InvoiceLifecycleService::class)->createDraft(
            $otherWorkspace,
            $otherCompany,
            $this->invoiceData() + [
                'invoice_kind' => InvoiceKind::CadencePeriod->value,
                'service_period_start' => '2026-08-01',
                'service_period_end' => '2026-08-31',
            ],
            [$this->line()],
        );

        $created = app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-08-15'));

        $this->assertCount(1, $created);
        $this->assertNotSame($foreign->id, $created[0]->id, 'another tenant\'s invoice is never handed back');
        $this->assertSame($workspace->id, $created[0]->workspace_id);
        $this->assertSame($schedule->id, $created[0]->client_billing_schedule_id);
    }

    /**
     * A dangling schedule link is refused, not read as someone else's.
     *
     * `client_billing_schedule_id` is an unconstrained integer, so a non-null
     * value is a *claim* that some schedule owns the row rather than proof that
     * one does. The guard's three-way reading - mine, unclaimed, another
     * schedule's - quietly assigned every unresolvable id to the third case,
     * which is the one branch that does not block. That reproduces the original
     * defect exactly: the invoice covering this period is invisible, and the
     * schedule issues a second one for it.
     */
    public function test_an_invoice_naming_a_schedule_that_does_not_exist_is_refused(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Dangling Schedule Workspace');

        $orphan = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'client_billing_schedule_id' => $schedule->id,
        ]);
        $orphan->forceFill(['client_billing_schedule_id' => $schedule->id + 1_000])->save();

        $this->assertRefusedAndUnchanged($schedule, $orphan);
    }

    /**
     * Nor is another client's schedule id, which resolves to a real row.
     *
     * The row exists, so an existence check alone passes; it is only "someone
     * else's" if that someone could have billed *this* client. Resolving the id
     * against the invoice's own workspace and client is what separates the two,
     * and is the same narrowing `UnplaceableInvoiceAuditor` already applies to
     * the agreement column for the same reason.
     */
    public function test_an_invoice_naming_another_clients_schedule_is_refused(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Foreign Lineage Workspace');
        [, , , $foreignSchedule] = $this->scheduledClient('Other Client Workspace');

        $orphan = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'client_billing_schedule_id' => $schedule->id,
        ]);
        $orphan->forceFill(['client_billing_schedule_id' => $foreignSchedule->id])->save();

        $this->assertRefusedAndUnchanged($schedule, $orphan);
    }

    /**
     * The agreement column gets the same treatment, for the same reason.
     *
     * An unlinked invoice naming an agreement that does not resolve was read as
     * "another agreement's, therefore not mine" - the over-block guard running
     * backwards, letting the period be billed twice instead of once.
     */
    public function test_an_invoice_naming_an_agreement_that_does_not_exist_is_refused(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Dangling Agreement Workspace');

        // The kind matters, and a fixture that leaves it to `createDraft()` does
        // not test this: with no schedule passed it stamps `ad_hoc`, which the
        // kind exemption releases before lineage is examined at all. A cadence
        // invoice is what a period guard actually reads.
        $orphan = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'invoice_kind' => InvoiceKind::CadencePeriod->value,
        ]);
        $orphan->forceFill(['client_agreement_id' => $agreement->id + 1_000])->save();

        $this->assertRefusedAndUnchanged($schedule, $orphan);
    }

    /**
     * A sibling client's agreement resolves, and still is not this one's.
     *
     * The narrower half of the same rule as the test above, and the one an
     * existence check alone would pass: this agreement is a real row in the
     * right workspace, so only resolving it against the invoice's *client* as
     * well separates "another agreement of ours" from "not ours at all". Two
     * companies in one workspace on purpose - the foreign-schedule case above
     * already covers the tenant boundary, so this isolates `client_company_id`
     * rather than re-proving `workspace_id`.
     */
    public function test_an_invoice_naming_another_companys_agreement_is_refused(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Cross Company Agreement Workspace');
        $sibling = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Sibling Client',
            'slug' => 'sibling-client-'.$workspace->id,
        ]);
        $siblingAgreement = $this->agreementFor($workspace, $sibling, 'Sibling');

        $orphan = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'invoice_kind' => InvoiceKind::CadencePeriod->value,
        ]);
        $orphan->forceFill(['client_agreement_id' => $siblingAgreement->id])->save();

        $this->assertRefusedAndUnchanged($schedule, $orphan);
    }

    /**
     * Lineage that contradicts itself names no owner at all.
     *
     * Both ids resolve, and they disagree: the invoice says it came from this
     * schedule, and also from an agreement this schedule does not bill. Reading
     * either half alone gives a confident and opposite answer - "mine, blocked"
     * from the schedule, "another agreement's, generate" from the agreement -
     * so the row is refused rather than decided by whichever column happens to
     * be checked first.
     */
    public function test_an_invoice_whose_schedule_and_agreement_disagree_is_refused(): void
    {
        [$workspace, $company, , $schedule] = $this->scheduledClient('Contradictory Lineage Workspace');
        $other = $this->agreementFor($workspace, $company, 'Second');

        $orphan = $this->augustInvoice($workspace, $company, [
            'client_billing_schedule_id' => $schedule->id,
            'client_agreement_id' => $other->id,
        ]);

        $this->assertRefusedAndUnchanged($schedule, $orphan);
    }

    /**
     * An agreement with no schedule at all still makes an orphan ambiguous.
     *
     * The ambiguity test used to ask whether another *active schedule* existed,
     * which reads a client's billing history as though every agreement had one.
     * It does not: `ClientInvoicingService` creates cadence invoices with an
     * agreement and no schedule, and `AgreementSelector` treats a client as a
     * sequence of agreement segments that can be paused, terminated or expired.
     * So a client can hold exactly one active schedule and several agreements
     * that have produced invoices, and an unattributed row from any of them was
     * adopted by that single schedule - which then advanced past its own
     * unbilled August. The lost-revenue half of #219, reintroduced by the fix
     * for its double-charge half.
     *
     * Asking instead whether a rival is *currently due* would be worse:
     * `next_run_on` is a mutable cursor, and a schedule that already produced
     * the row has by definition advanced past it.
     */
    public function test_an_unattributed_invoice_is_refused_when_a_scheduleless_agreement_could_own_it(): void
    {
        [$workspace, $company, , $schedule] = $this->scheduledClient('Scheduleless Rival Workspace');
        // No schedule of its own, and never billed by one. Invisible to any
        // question about active schedules.
        $this->agreementFor($workspace, $company, 'Second');

        $orphan = $this->augustInvoice($workspace, $company, []);
        $orphan->forceFill(['invoice_kind' => null])->save();

        $this->assertRefusedAndUnchanged($schedule, $orphan);
    }

    /**
     * An invoice that contains this period blocks it, rather than being missed.
     *
     * The guard compared both boundaries for equality, so an invoice covering
     * July *and* August did not stop August being billed: the dates were not
     * equal, the row fell out of the query, and the second invoice's
     * `(schedule, start, end)` tuple was distinct enough for the unique index
     * to accept it. `ClientInvoicingService::assertNoOverlappingInvoice()` has
     * always used inclusive overlap; this now does too.
     *
     * Refused rather than treated as billed, because a containing invoice is
     * not the same claim as this period's: skipping would silently leave
     * whatever August the wider invoice does not actually cover unbilled, and
     * billing would charge the shared days twice.
     */
    public function test_an_invoice_containing_the_period_is_refused_rather_than_billed_again(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Containing Period Workspace');

        $wide = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'client_billing_schedule_id' => $schedule->id,
            'service_period_start' => '2026-07-01',
        ]);

        $this->assertRefusedAndUnchanged($schedule, $wide);
    }

    /**
     * Voiding the wider invoice is a real way out of the overlap refusal.
     *
     * The refusal above has to be escapable, and for a *voided* invoice it was
     * not: `updateDraft()` accepts nothing but a draft, so a voided invoice
     * cannot be re-keyed in the application, and the schedule would refuse on
     * every run with a database edit as the only remedy - while
     * `ClientInvoicingService::assertNoOverlappingInvoice()` tells operators to
     * "void the existing invoice first". This makes that advice true here too.
     *
     * A void charged nobody, so the row cannot stand in for this period; and
     * the waiver rule it might otherwise invoke is about not reselling *the
     * same* cycle, which a differently-dated span is not.
     */
    public function test_voiding_a_containing_invoice_lets_the_schedule_bill_the_period(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Voided Overlap Workspace');

        $wide = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'client_billing_schedule_id' => $schedule->id,
            'service_period_start' => '2026-07-01',
        ]);
        $wide->forceFill(['status' => InvoiceStatus::Void->value])->save();

        $created = app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-08-15'));

        $this->assertCount(1, $created);
        $this->assertNotSame($wide->id, $created[0]->id);
        $this->assertSame('2026-08-01', $created[0]->service_period_start?->format('Y-m-d'));
    }

    /**
     * An exact match still blocks after it is voided, unlike an overlap.
     *
     * The counterpart to the test above, and the reason status is read in one
     * place and not the other. Voiding a cadence invoice is the documented way
     * to waive *its own* period, and regenerating it would write the same
     * `(schedule, start, end)` and collide with
     * `billing_schedule_service_period_unique` - so here the void is honoured
     * and nothing new is raised.
     */
    public function test_a_voided_invoice_for_exactly_this_period_still_blocks_it(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Voided Exact Workspace');

        $august = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'client_billing_schedule_id' => $schedule->id,
        ]);
        $august->forceFill(['status' => InvoiceStatus::Void->value])->save();

        $created = app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-08-15'));

        $this->assertDatabaseCount('client_invoices', 1);
        $this->assertSame([$august->id], array_map(static fn ($i) => $i->id, $created));
    }

    /**
     * An ad-hoc invoice is exempt before its lineage is ever examined.
     *
     * `cycleGuardExclusions()` says an interim or ad-hoc invoice must never
     * block a cadence one, and an exemption reached only after the lineage
     * refusals is not an exemption: `client_invoices.client_agreement_id`
     * carries no foreign key, so an imported or repaired ad-hoc row with a
     * dangling agreement hard-refused the whole run over an invoice that is not
     * allowed to affect it in either direction.
     */
    public function test_an_ad_hoc_invoice_with_dangling_lineage_does_not_refuse_the_run(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Exempt Lineage Workspace');

        $adHoc = $this->augustInvoice($workspace, $company, ['client_agreement_id' => $agreement->id]);
        $adHoc->forceFill([
            'invoice_kind' => InvoiceKind::AdHoc->value,
            'client_agreement_id' => $agreement->id + 1_000,
        ])->save();

        $created = app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-08-15'));

        $this->assertCount(1, $created);
        $this->assertSame($schedule->id, $created[0]->client_billing_schedule_id);
    }

    /**
     * A periodless invoice whose known date proves it cannot reach this period.
     *
     * The unplaceable check used to be a sweep that never saw the period being
     * billed: any live invoice of this schedule's missing a boundary halted it,
     * whatever its other date said. So an invoice ending January 2024 with no
     * start - which cannot reach August 2026 however early it began - refused
     * August 2026 and every period after it. For a *paid* row that outage is
     * permanent: it can be neither voided (`paid_amount > 0`) nor re-dated
     * (`updateDraft()` rewrites no period column at any status).
     *
     * A missing boundary means unbounded in that direction, not unknowable. The
     * interval still cannot overlap if a known boundary rules it out.
     */
    public function test_a_periodless_invoice_that_cannot_reach_this_period_does_not_halt_it(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Distant Periodless Workspace');

        $ancient = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'client_billing_schedule_id' => $schedule->id,
        ]);
        $ancient->forceFill([
            'status' => InvoiceStatus::Paid->value,
            'service_period_start' => null,
            'service_period_end' => '2024-01-31',
        ])->save();

        $created = app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-08-15'));

        $this->assertCount(1, $created);
        $this->assertSame('2026-08-01', $created[0]->service_period_start?->format('Y-m-d'));
    }

    /**
     * The mirror: a known start after the period, during catch-up generation.
     */
    public function test_a_periodless_invoice_starting_after_this_period_does_not_halt_it(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Future Periodless Workspace');
        $schedule->forceFill(['next_run_on' => '2024-01-01'])->save();

        $future = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'client_billing_schedule_id' => $schedule->id,
        ]);
        $future->forceFill(['service_period_start' => '2026-08-01', 'service_period_end' => null])->save();

        $created = app(BillingScheduleService::class)->generateDue(
            $schedule->fresh(),
            CarbonImmutable::parse('2024-01-15'),
        );

        $this->assertCount(1, $created);
        $this->assertSame('2024-01-01', $created[0]->service_period_start?->format('Y-m-d'));
    }

    /**
     * But one whose known date *does* reach this period still halts it.
     *
     * The counterpart, so the narrowing above is a narrowing and not a hole.
     */
    public function test_a_periodless_invoice_that_could_reach_this_period_still_halts_it(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Reaching Periodless Workspace');

        $reaching = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'client_billing_schedule_id' => $schedule->id,
        ]);
        $reaching->forceFill(['service_period_start' => null, 'service_period_end' => '2026-08-15'])->save();

        $this->assertRefusedAndUnchanged($schedule, $reaching);
    }

    /**
     * A null boundary must not smuggle a row past the lineage resolver.
     *
     * The sharpest form of the two-query seam. This row names the schedule's
     * own agreement and a schedule id that does not resolve, so the complete
     * candidate query could not see it (it has no period end) and the old
     * unplaceable sweep could not either (its schedule id was not this
     * schedule's, and not null). Both missed it, the resolver answered clear,
     * and August was issued a second time. With a real period end the very same
     * row refuses - so adding a null turned "unsafe, refuse" into "invisible,
     * issue".
     */
    public function test_a_dangling_schedule_link_is_refused_even_with_no_period_end(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Seam Workspace');

        $smuggled = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'client_billing_schedule_id' => $schedule->id,
            'invoice_kind' => InvoiceKind::CadencePeriod->value,
        ]);
        $smuggled->forceFill([
            'status' => InvoiceStatus::Issued->value,
            'client_billing_schedule_id' => $schedule->id + 1_000,
            'service_period_end' => null,
        ])->save();

        $this->assertRefusedAndUnchanged($schedule, $smuggled);
    }

    /**
     * Another schedule's periodless invoice is still not this one's problem.
     *
     * The narrowing that keeps the unified query from halting everyone:
     * ownership is resolved for incomplete rows exactly as for complete ones,
     * so a row belonging to a resolvable other schedule is cleared rather than
     * swept up by a simpler "mine or unlinked" test.
     */
    public function test_another_schedules_periodless_invoice_does_not_halt_this_one(): void
    {
        [$workspace, $company, , $schedule] = $this->scheduledClient('Sibling Periodless Workspace');
        $otherAgreement = $this->agreementFor($workspace, $company, 'Second');
        $otherSchedule = ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'client_agreement_id' => $otherAgreement->id, 'cadence' => 'monthly',
            'next_run_on' => '2026-08-01', 'due_days' => 14, 'currency' => 'USD', 'line_template' => [$this->line()],
        ]);

        $theirs = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $otherAgreement->id,
            'client_billing_schedule_id' => $otherSchedule->id,
        ]);
        $theirs->forceFill(['service_period_end' => null])->save();

        $created = app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-08-15'));

        $this->assertCount(1, $created);
        $this->assertSame($schedule->id, $created[0]->client_billing_schedule_id);
    }

    /**
     * A status this application does not recognise refuses, never clears.
     *
     * `status` is a varchar, and the vocabulary already fails closed on values
     * it cannot read - `InvoiceStatus::isSettledValue()` and `hasChargedValue()`
     * both answer *yes* to an unknown one, because a status this code cannot
     * read is one it cannot show is safe to act on. Asking instead whether a
     * status is absent from `live()` put every unknown value on the same path
     * as `void`: the overlap cleared, and a second invoice was issued beside a
     * row that may well have charged the client.
     */
    public function test_an_unrecognised_status_refuses_rather_than_clearing(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Unknown Status Workspace');

        $strange = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'client_billing_schedule_id' => $schedule->id,
            'service_period_start' => '2026-07-01',
        ]);
        $strange->forceFill(['status' => 'awaiting_dispute_resolution'])->save();

        $this->assertRefusedAndUnchanged($schedule, $strange);
    }

    /**
     * And the same when the unknown status is on a row with no period end.
     */
    public function test_an_unrecognised_status_refuses_with_no_period_end_too(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Unknown Periodless Workspace');

        $strange = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'client_billing_schedule_id' => $schedule->id,
        ]);
        $strange->forceFill(['status' => 'awaiting_dispute_resolution', 'service_period_end' => null])->save();

        $this->assertRefusedAndUnchanged($schedule, $strange);
    }

    /**
     * Voiding works as the way out even when the row's lineage is broken.
     *
     * The void clearance has to be reached *before* the lineage refusals or it
     * is not an escape hatch: a voided, non-exact row with a dangling schedule
     * id refused at lineage resolution and never arrived at the branch that
     * clears it, so voiding - the remedy
     * `ClientInvoicingService::assertNoOverlappingInvoice()` tells operators to
     * use - did nothing. A known void that does not cover exactly this period
     * charged nobody and cannot collide with the tuple about to be written, so
     * whose it is never needs establishing.
     */
    public function test_a_voided_overlap_clears_even_with_dangling_lineage(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Voided Dangling Workspace');

        $wide = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'client_billing_schedule_id' => $schedule->id,
            'service_period_start' => '2026-07-01',
        ]);
        $wide->forceFill([
            'status' => InvoiceStatus::Void->value,
            'client_billing_schedule_id' => $schedule->id + 1_000,
        ])->save();

        $created = app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-08-15'));

        $this->assertCount(1, $created);
        $this->assertSame('2026-08-01', $created[0]->service_period_start?->format('Y-m-d'));
    }

    /**
     * An invoice of this schedule's with no period end halts it, loudly.
     *
     * This is the null-in-a-predicate class turned on the guard itself. Such a
     * row cannot be placed by any date comparison, so it is not that the guard
     * decides wrongly - it never sees the row at all, bills the period again,
     * and the unique index does not reject the second invoice because one of
     * its three columns is null. Since neither answer can be derived, the
     * schedule stops and names the row to repair; `UnplaceableInvoiceAuditor`
     * counts the same population so it can be found before a run hits it.
     *
     * Scoped to rows demonstrably this schedule's or its agreement's. A
     * periodless row naming neither owner is not evidence about this schedule
     * and does not halt it - there is no date tying it to this period and no
     * lineage tying it to this client's schedule.
     */
    public function test_an_invoice_of_this_schedule_with_no_period_end_is_refused(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Unplaceable Period Workspace');

        $unplaceable = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'client_billing_schedule_id' => $schedule->id,
        ]);
        $unplaceable->forceFill(['service_period_end' => null])->save();

        $this->assertRefusedAndUnchanged($schedule, $unplaceable);
    }

    /**
     * Consecutive periods touch and never overlap, for every cadence and every
     * awkward start date.
     *
     * This is the property the overlap guard rests on. The guard used to
     * compare both boundaries for equality, so a period that overlapped its own
     * predecessor by a day was simply billed again; now an overlap *refuses*,
     * which turns any adjacency bug in `period()` from a silent double-charge
     * into a schedule that halts on its second run and never recovers. The
     * arithmetic is `addMonthsNoOverflow()` and the end is `$next->subDay()`,
     * so adjacency holds by construction - but "by construction" is exactly
     * what a month-arithmetic change quietly breaks, and nothing else here
     * would notice.
     *
     * Driven through `generateDue()` rather than the private `period()`,
     * because the claim being pinned is about the invoices that actually get
     * written: the run generating without refusing *is* half the assertion.
     * Start dates are the ones where naive month arithmetic goes wrong - the
     * 31st into a short month, February 29th, a non-leap February 28th, and a
     * year boundary.
     */
    public function test_consecutive_periods_are_adjacent_for_every_cadence_and_awkward_start(): void
    {
        $starts = ['2024-01-31', '2024-02-29', '2023-02-28', '2024-08-31', '2024-12-31', '2024-01-01'];
        $cadences = ['monthly' => 1, 'quarterly' => 3, 'semi_annual' => 6, 'annual' => 12];

        foreach ($cadences as $cadence => $months) {
            foreach ($starts as $start) {
                [, $workspace, $company] = $this->tenant("Adjacency {$cadence} {$start}");
                $agreement = $this->agreementFor($workspace, $company, 'Adjacency');
                $schedule = ClientBillingSchedule::query()->create([
                    'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                    'client_agreement_id' => $agreement->id, 'cadence' => $cadence, 'next_run_on' => $start,
                    'due_days' => 14, 'currency' => 'USD', 'line_template' => [$this->line()],
                ]);

                // Far enough past the start to force several consecutive
                // periods out of one run, whatever the cadence.
                $through = CarbonImmutable::parse($start)->addMonthsNoOverflow($months * 4);
                $invoices = app(BillingScheduleService::class)->generateDue($schedule, $through);

                // Exactly five, not "at least four". The through-date is four
                // cadence increments past the start and the loop is inclusive,
                // so five period starts fall due. A lower bound would still
                // pass if the cadence were shortened, or if the loop became
                // exclusive and dropped the last period - both of which are
                // changes to what gets billed.
                $this->assertCount(5, $invoices, "{$cadence} from {$start} did not generate five periods");

                $previousEnd = null;
                foreach ($invoices as $invoice) {
                    $periodStart = CarbonImmutable::parse((string) $invoice->service_period_start);
                    $periodEnd = CarbonImmutable::parse((string) $invoice->service_period_end);

                    $this->assertTrue(
                        $periodStart->lte($periodEnd),
                        "{$cadence} from {$start}: period {$periodStart->toDateString()}..{$periodEnd->toDateString()} ends before it begins",
                    );

                    if ($previousEnd instanceof CarbonImmutable) {
                        // No gap: a day belonging to no period is a day nobody
                        // is billed for.
                        $this->assertTrue(
                            $previousEnd->addDay()->isSameDay($periodStart),
                            "{$cadence} from {$start}: {$previousEnd->toDateString()} is not the day before {$periodStart->toDateString()}",
                        );

                        // And no overlap, which the guard would now refuse.
                        $this->assertTrue(
                            $previousEnd->lt($periodStart),
                            "{$cadence} from {$start}: {$previousEnd->toDateString()} overlaps {$periodStart->toDateString()}",
                        );
                    }

                    $previousEnd = $periodEnd;
                }
            }
        }
    }

    /**
     * A draft covering exactly this period is neither billed nor billed again.
     *
     * The shape is the ordinary one, and that is the point: `ClientInvoicingService`
     * creates cadence invoices with an agreement and *no* schedule, and they sit
     * as committed drafts while somebody reviews them. A schedule's own draft is
     * not this shape - `generateDue()` creates and issues in one transaction, so
     * a failure rolls the draft back and a success commits it issued.
     *
     * An earlier revision reported this as `AlreadyBilled`. That advanced
     * `next_run_on` past a period no money had been asked for, and - the part
     * that makes it a lost-billing path rather than a delay - nothing brought it
     * back. The unique-index argument that justifies the exact-match rule does
     * not apply to this row either: its `client_billing_schedule_id` is null, so
     * the index never constrained it.
     */
    public function test_a_pending_draft_for_the_period_neither_bills_it_nor_advances_the_schedule(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Pending Draft Workspace');

        // The other generator's shape: agreement, cadence kind, no schedule.
        $draft = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'invoice_kind' => InvoiceKind::CadencePeriod->value,
        ]);
        $this->assertNull($draft->client_billing_schedule_id);
        $this->assertSame('draft', $draft->status);

        try {
            app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-08-15'));
            $this->fail('the schedule should not decide a period a pending draft has claimed');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString((string) $draft->invoice_number, $refusal->getMessage());
            $this->assertStringContainsString('charged nobody', $refusal->getMessage());
        }

        // No second invoice for the period...
        $this->assertDatabaseCount('client_invoices', 1);
        // ...and the cursor has not moved past a period nothing has billed.
        $this->assertSame('2026-08-01', $schedule->fresh()?->next_run_on?->toDateString());
    }

    /**
     * Issuing the draft is what lets the schedule move on.
     *
     * The other half of the contract, and the reason the halt is a nuisance
     * rather than a trap: the operator does the thing they were going to do
     * anyway, and the next run advances without raising anything.
     */
    public function test_issuing_the_pending_draft_lets_the_schedule_advance_without_billing_again(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Issued Draft Workspace');

        $draft = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'invoice_kind' => InvoiceKind::CadencePeriod->value,
        ]);
        app(InvoiceLifecycleService::class)->issue($draft, $workspace);

        $created = app(BillingScheduleService::class)
            ->generateDue($schedule, CarbonImmutable::parse('2026-08-15'));

        $this->assertCount(1, $created);
        $this->assertSame($draft->id, $created[0]->id);
        $this->assertDatabaseCount('client_invoices', 1);
        $this->assertSame('2026-09-01', $schedule->fresh()?->next_run_on?->toDateString());
    }

    /**
     * Discarding the draft waives the period, and that is deliberate.
     *
     * `discardDraft()` does not delete the row - it turns it into a *void*
     * invoice keeping its period - so the period reads afterwards exactly as a
     * deliberately waived one, and the schedule advances without billing it.
     *
     * That is the documented meaning of voiding a cadence invoice, so it is the
     * right outcome; it is pinned here because it is also the reason the
     * `PendingDraft` verdict has to exist. Under the old `AlreadyBilled`
     * reading the schedule had *already* advanced before anyone discarded
     * anything, so this waiver happened whether or not a person intended it,
     * and rewinding the cursor could not undo it. Now the waiver only happens
     * when somebody discards the draft on purpose.
     */
    public function test_discarding_the_pending_draft_waives_the_period_rather_than_rebilling_it(): void
    {
        [$workspace, $company, $agreement, $schedule] = $this->scheduledClient('Discarded Draft Workspace');

        $draft = $this->augustInvoice($workspace, $company, [
            'client_agreement_id' => $agreement->id,
            'invoice_kind' => InvoiceKind::CadencePeriod->value,
        ]);
        $voided = app(InvoiceLifecycleService::class)->discardDraft($draft, $workspace, 'Superseded');

        $this->assertSame('void', $voided->status);
        $this->assertSame('2026-08-01', $voided->service_period_start?->toDateString());

        app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-08-15'));

        $this->assertDatabaseCount('client_invoices', 1);
        $this->assertSame('2026-09-01', $schedule->fresh()?->next_run_on?->toDateString());
    }

    /**
     * A null `client_billing_schedule_id` is what makes a draft ad hoc.
     *
     * `createDraft` classifies on the absence of a schedule, not on who called
     * it: a draft with no schedule is an operator's one-off and is exempted
     * from the cadence overlap guard, while a scheduled one is a machine-made
     * cadence invoice that guard has to see. Classifying the scheduled ones as
     * ad hoc let a second invoice be generated for the same agreement and
     * period, so the two halves of the branch are asserted together.
     */
    public function test_a_draft_without_a_billing_schedule_is_classified_ad_hoc(): void
    {
        [, $workspace, $company] = $this->tenant('Kind Workspace');
        $service = app(InvoiceLifecycleService::class);

        $operatorDraft = $service->createDraft($workspace, $company, $this->invoiceData(), [$this->line()]);

        $this->assertNull($operatorDraft->client_billing_schedule_id);
        $this->assertSame('ad_hoc', $operatorDraft->invoice_kind);

        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic agreement',
            'currency' => 'USD', 'billing_cadence' => 'monthly', 'status' => 'active', 'starts_on' => '2026-01-01',
        ]);
        $schedule = ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'cadence' => 'monthly', 'next_run_on' => '2026-08-01', 'due_days' => 14, 'currency' => 'USD',
            'line_template' => [$this->line()],
        ]);

        $scheduledDraft = $service->createDraft(
            $workspace,
            $company,
            $this->invoiceData() + ['client_billing_schedule_id' => $schedule->id],
            [$this->line()],
        );

        $this->assertSame($schedule->id, $scheduledDraft->client_billing_schedule_id);
        $this->assertSame('cadence_period', $scheduledDraft->invoice_kind);
    }

    public function test_manual_payment_command_is_tenant_scoped_and_returns_json(): void
    {
        [, $workspace, $company] = $this->tenant('Command Workspace');
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, $this->invoiceData(), [$this->line()]);
        $service->issue($invoice, $workspace);

        $this->artisan('svc:billing:payment', [
            'invoice' => $invoice->public_id, 'amount' => '1000', 'currency' => 'USD', 'method' => 'wire',
            '--workspace' => $workspace->public_id, '--format' => 'json', '--idempotency-key' => 'command-payment-1',
        ])->expectsOutputToContain('"invoice_public_id":"'.$invoice->public_id.'"')->assertSuccessful();
        $this->assertDatabaseCount('client_invoice_payments', 1);
    }

    public function test_authenticated_json_routes_accept_the_exact_primary_payload_names(): void
    {
        [$owner, $workspace, $company] = $this->tenant();
        $response = $this->actingAs($owner)->postJson(
            "/workspaces/{$workspace->public_id}/clients/{$company->public_id}/invoices",
            ['invoice_number' => 'INV-SYNTH-001', 'currency' => 'USD', 'issue_date' => '2026-08-15', 'due_date' => '2026-09-14', 'lines' => [$this->line()]],
        );
        $response->assertCreated()->assertJsonPath('data.invoice_number', 'INV-SYNTH-001');
        $invoice = ClientInvoice::query()->sole();
        $this->actingAs($owner)->postJson("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/issue")->assertOk();
        $payment = $this->actingAs($owner)->postJson("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/payments", [
            'amount' => 1000, 'currency' => 'USD', 'received_on' => '2026-08-15', 'method' => 'wire', 'reference' => 'SYNTH-REF', 'notes' => 'Synthetic', 'status' => 'succeeded',
        ]);
        $payment->assertCreated()->assertJsonPath('data.currency', 'USD');
        $this->actingAs($owner)->postJson("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/payments", [
            'amount' => 500, 'currency' => 'USD', 'received_on' => '2026-08-15', 'method' => 'wire', 'status' => 'canceled',
        ])->assertCreated()->assertJsonPath('data.status', 'canceled');
        $this->assertSame('partially_paid', $invoice->fresh()->status);
    }

    public function test_pdf_download_is_a_real_pdf_and_email_delivery_is_recorded(): void
    {
        [$owner, $workspace, $company] = $this->tenant();
        $company->forceFill(['billing_email' => 'billing@synthetic.test'])->save();
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, $this->invoiceData(), [$this->line()]);
        $service->issue($invoice, $workspace);

        $this->actingAs($owner)->get("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/pdf")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertSee('%PDF', false)
            // Inline: the control that leads here says "View PDF", and as an
            // attachment every reader who wanted to look at an invoice got a
            // file in Downloads instead.
            ->assertHeader(
                'Content-Disposition',
                'inline; filename=invoice-'.Str::slug($invoice->invoice_number).'.pdf',
            );
        // 200 rather than 202: the send happens in the request now, so the
        // answer is what happened rather than what was promised.
        $this->actingAs($owner)->postJson("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/send")
            ->assertOk();
        $this->assertDatabaseHas('client_invoice_email_deliveries', ['status' => 'sent']);
    }

    /** @return array{0:User,1:Workspace,2:ClientCompany} */
    public function test_pending_stripe_intent_reserves_the_balance_against_a_second_intent(): void
    {
        [, $workspace, $company] = $this->tenant();
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, $this->invoiceData(), [$this->line()]);
        $service->issue($invoice, $workspace);
        $service->applyPayment($invoice, [
            'amount' => 10000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'pending',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_first_tab',
            'idempotency_key' => 'first-tab-key',
        ], $workspace);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A pending payment already reserves the remaining invoice balance.');
        app(StripePaymentIntentService::class)
            ->create($invoice->fresh(), $workspace, null, 'second-tab-key');
    }

    public function test_void_is_blocked_while_payments_are_pending(): void
    {
        [, $workspace, $company] = $this->tenant();
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, $this->invoiceData(), [$this->line()]);
        $service->issue($invoice, $workspace);
        $service->applyPayment($invoice, [
            'amount' => 10000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'pending',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_pending_void',
        ], $workspace);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cancel or resolve pending payments before voiding this invoice.');
        $service->void($invoice, $workspace);
    }

    public function test_marking_a_payment_succeeded_on_a_void_invoice_is_refused(): void
    {
        [, $workspace, $company] = $this->tenant();
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, $this->invoiceData(), [$this->line()]);
        $service->issue($invoice, $workspace);
        $payment = $service->applyPayment($invoice, [
            'amount' => 10000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'pending',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_void_race',
        ], $workspace);
        $invoice->forceFill(['status' => 'void', 'voided_at' => now(), 'balance_amount' => 0])->save();

        try {
            $service->setPaymentStatus($payment, 'succeeded', $workspace);
            $this->fail('Expected DomainException for a payment succeeding against a void invoice.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('void invoice', $exception->getMessage());
        }
        $fresh = $invoice->fresh();
        $this->assertSame('void', $fresh->status);
        $this->assertSame(0, $fresh->paid_amount);
    }

    public function test_over_balance_payment_surfaces_as_a_422_not_a_500(): void
    {
        [$owner, $workspace, $company] = $this->tenant();
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, $this->invoiceData(), [$this->line()]);
        $service->issue($invoice, $workspace);

        $this->actingAs($owner)
            ->postJson("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/payments", [
                'amount' => 99999, 'currency' => 'USD', 'method' => 'ach', 'status' => 'succeeded',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Payment cannot exceed the invoice balance.');
    }

    public function test_invoice_document_never_renders_internal_notes(): void
    {
        [$owner, $workspace, $company] = $this->tenant();
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            ...$this->invoiceData(), 'notes' => 'Internal synthetic note',
        ], [$this->line()]);
        $service->issue($invoice, $workspace);

        // Through the service that owns this document rather than by handing
        // the view a set of variables assembled here: what the template is
        // given - which lines, and whose appendix - is exactly the decision
        // being asserted, and a test that supplies its own inputs is asserting
        // its own assembly instead of the one clients receive.
        $html = app(InvoiceDocumentService::class)->html($invoice->fresh())->render();
        $this->assertStringNotContainsString('Internal synthetic note', $html);
    }

    /**
     * A client with one agreement and one monthly schedule due for August 2026.
     *
     * @return array{0:Workspace,1:ClientCompany,2:ClientAgreement,3:ClientBillingSchedule}
     */
    private function scheduledClient(string $name): array
    {
        [, $workspace, $company] = $this->tenant($name);
        $agreement = $this->agreementFor($workspace, $company, 'Synthetic');
        $schedule = ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id,
            'cadence' => 'monthly', 'next_run_on' => '2026-08-01', 'due_days' => 14, 'currency' => 'USD',
            'line_template' => [$this->line()],
        ]);

        return [$workspace, $company, $agreement, $schedule];
    }

    private function agreementFor(Workspace $workspace, ClientCompany $company, string $title): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => $title.' agreement',
            'currency' => 'USD', 'billing_cadence' => 'monthly', 'status' => 'active', 'starts_on' => '2026-01-01',
        ]);
    }

    /**
     * A draft covering August 2026, with whatever lineage the caller needs.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function augustInvoice(Workspace $workspace, ClientCompany $company, array $attributes): ClientInvoice
    {
        return app(InvoiceLifecycleService::class)->createDraft(
            $workspace,
            $company,
            $attributes + $this->invoiceData() + [
                'service_period_start' => '2026-08-01',
                'service_period_end' => '2026-08-31',
            ],
            [$this->line()],
        );
    }

    /**
     * The schedule refuses August, names the row, and leaves everything alone.
     *
     * A refusal is only safe if it is recoverable, so every one of these
     * asserts the rollback as well as the throw: nothing new is created and
     * `next_run_on` does not move, so the run can be repeated once a person has
     * repaired the row the message names.
     */
    private function assertRefusedAndUnchanged(ClientBillingSchedule $schedule, ClientInvoice $offending): void
    {
        $before = ClientInvoice::query()->count();

        try {
            app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-08-15'));
            $this->fail('An invoice whose claim on this period cannot be established must not be billed past silently.');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString($offending->invoice_number, $refusal->getMessage());
        }

        $this->assertSame($before, ClientInvoice::query()->count(), 'a refusal creates nothing');
        $this->assertSame(
            '2026-08-01',
            $schedule->fresh()?->next_run_on?->format('Y-m-d'),
            'a schedule that billed nothing must not move on',
        );
    }

    private function tenant(string $name = 'Synthetic Workspace'): array
    {
        $owner = User::factory()->create(['email' => strtolower(str_replace(' ', '-', $name)).'@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => $name, 'slug' => str($name)->slug().'-'.str()->random(5)]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Client', 'slug' => 'synthetic-client-'.$workspace->id]);

        return [$owner, $workspace, $company];
    }

    /** @return array<string, mixed> */
    private function invoiceData(): array
    {
        return ['invoice_number' => 'INV-SYNTH-'.str()->upper(str()->random(8)), 'currency' => 'USD', 'issue_date' => '2026-08-15', 'due_date' => '2026-09-14'];
    }

    /** @return array<string, mixed> */
    private function line(): array
    {
        return ['type' => 'service', 'description' => 'Synthetic service', 'quantity' => '2', 'unit_amount' => 5000, 'tax_amount' => 0, 'sort_order' => 1];
    }
}
