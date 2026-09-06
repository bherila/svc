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
use Carbon\CarbonImmutable;
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

        $this->expectException(\DomainException::class);
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
    public function test_an_unlinked_invoice_does_not_stop_a_schedule_billing_its_period_again(): void
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

        $this->expectException(\DomainException::class);
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

        $this->expectException(\DomainException::class);
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
        } catch (\DomainException $exception) {
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
