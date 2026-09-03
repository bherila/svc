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
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\StripePaymentIntentService;
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
        $this->actingAs($owner)->postJson("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/send")
            ->assertAccepted();
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

        $html = view('invoices.show', ['invoice' => $invoice->fresh(['lines', 'clientCompany'])])->render();
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
