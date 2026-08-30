<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientStripeCustomer;
use App\Models\ClientStripePaymentMethod;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.webhook_secret' => 'whsec_synthetic']);
        if (! Route::has('svc.billing.stripe.webhook')) {
            require base_path('routes/billing.php');
        }
    }

    public function test_invalid_signature_cannot_create_a_ledger_event_or_payment(): void
    {
        $response = $this->webhookPost($this->payload(), 't=1,v1=invalid');
        $response->assertStatus(400);
        $this->assertDatabaseCount('client_stripe_events', 0);
    }

    public function test_signed_success_event_is_processed_once_and_replay_is_a_noop(): void
    {
        [, $workspace, $company] = $this->tenant();
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-WEBHOOK-SYNTH', 'currency' => 'USD', 'issue_date' => '2026-08-15', 'due_date' => '2026-09-14',
        ], [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $service->issue($invoice, $workspace);
        $payload = $this->payload($invoice->public_id, $workspace->public_id);
        $headers = ['Stripe-Signature' => $this->signature($payload)];

        $this->webhookPost($payload, $headers['Stripe-Signature'])->assertOk()->assertJsonPath('duplicate', false);
        $this->webhookPost($payload, $headers['Stripe-Signature'])->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('client_stripe_events', 1);
        $this->assertDatabaseCount('client_invoice_payments', 1);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.payment_received')->count());
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.marked_paid')->count());
    }

    public function test_signed_success_event_cannot_cross_workspace_scope_with_crafted_metadata(): void
    {
        [, $invoiceWorkspace, $invoiceCompany] = $this->tenant('invoice');
        [, $metadataWorkspace] = $this->tenant('metadata');
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($invoiceWorkspace, $invoiceCompany, [
            'invoice_number' => 'INV-WEBHOOK-CROSS-SCOPE', 'currency' => 'USD',
        ], [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $service->issue($invoice, $invoiceWorkspace);

        $payload = $this->payload($invoice->public_id, $metadataWorkspace->public_id, 'evt_synthetic_cross_scope', 'pi_synthetic_cross_scope');
        $this->webhookPost($payload, $this->signature($payload))->assertOk();

        $this->assertDatabaseCount('client_invoice_payments', 0);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertDatabaseHas('client_stripe_events', [
            'stripe_event_id' => 'evt_synthetic_cross_scope',
            'status' => 'processed',
            'workspace_id' => null,
        ]);
    }

    public function test_partial_refund_reopens_only_the_refunded_amount(): void
    {
        [, $workspace, $company] = $this->tenant();
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-WEBHOOK-REFUND', 'currency' => 'USD',
        ], [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $service->issue($invoice, $workspace);
        $success = $this->payload($invoice->public_id, $workspace->public_id);
        $this->webhookPost($success, $this->signature($success))->assertOk();

        $refund = json_encode([
            'id' => 'evt_synthetic_refund',
            'object' => 'event',
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'id' => 'ch_synthetic_refund',
                'payment_intent' => 'pi_synthetic_billing',
                'amount_refunded' => 400,
                'currency' => 'usd',
            ]],
        ], JSON_THROW_ON_ERROR);
        $this->webhookPost($refund, $this->signature($refund))->assertOk();

        $this->assertSame(400, $invoice->fresh()->balance_amount);
        $this->assertDatabaseHas('client_invoice_payments', [
            'provider_payment_identifier' => 'pi_synthetic_billing',
            'refunded_amount' => 400,
            'status' => 'succeeded',
        ]);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.payment_refunded')->count());
    }

    public function test_failure_and_dispute_transitions_append_once_per_processed_provider_event(): void
    {
        [, $workspace, $company] = $this->tenant('transitions');
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-WEBHOOK-TRANSITIONS', 'currency' => 'USD',
        ], [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $service->issue($invoice, $workspace);
        $payment = $service->applyPayment($invoice, [
            'amount' => 1000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'pending',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_activity_transitions',
        ], $workspace);

        $failed = $this->eventPayload('evt_activity_failed', 'payment_intent.payment_failed', [
            'id' => 'pi_activity_transitions',
        ]);
        $this->webhookPost($failed, $this->signature($failed))->assertOk();
        $this->webhookPost($failed, $this->signature($failed))->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.payment_failed')->count());

        $service->setPaymentStatus($payment->fresh(), 'succeeded', $workspace);
        $dispute = $this->eventPayload('evt_activity_dispute', 'charge.dispute.created', [
            'id' => 'dp_activity_transitions',
            'payment_intent' => 'pi_activity_transitions',
        ]);
        $this->webhookPost($dispute, $this->signature($dispute))->assertOk();
        $this->webhookPost($dispute, $this->signature($dispute))->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame('disputed', $payment->fresh()->status);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.payment_disputed')->count());
    }

    public function test_out_of_order_processing_event_cannot_reopen_a_paid_invoice(): void
    {
        [, $workspace, $company] = $this->tenant('late-processing');
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-LATE-PROCESSING', 'currency' => 'USD',
        ], [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $service->issue($invoice, $workspace);
        $payment = $service->applyPayment($invoice, [
            'amount' => 1000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'pending',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_late_processing',
        ], $workspace);

        $succeeded = $this->payload($invoice->public_id, $workspace->public_id, 'evt_late_success', 'pi_late_processing');
        $this->webhookPost($succeeded, $this->signature($succeeded))->assertOk();
        $processing = $this->eventPayload('evt_stale_processing', 'payment_intent.processing', [
            'id' => 'pi_late_processing',
        ]);
        $this->webhookPost($processing, $this->signature($processing))->assertOk();

        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->balance_amount);
        $this->assertDatabaseHas('client_stripe_events', [
            'stripe_event_id' => 'evt_stale_processing',
            'workspace_id' => $workspace->id,
            'status' => 'processed',
        ]);
    }

    public function test_canceled_payment_intent_is_terminal_against_older_processing_delivery(): void
    {
        [, $workspace, $company] = $this->tenant('canceled-terminal');
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-CANCELED-TERMINAL', 'currency' => 'USD',
        ], [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $service->issue($invoice, $workspace);
        $payment = $service->applyPayment($invoice, [
            'amount' => 1000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'pending',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_canceled_terminal',
        ], $workspace);

        $canceled = $this->eventPayload('evt_canceled_terminal', 'payment_intent.canceled', [
            'id' => 'pi_canceled_terminal',
        ], 200);
        $processing = $this->eventPayload('evt_older_processing', 'payment_intent.processing', [
            'id' => 'pi_canceled_terminal',
        ], 100);
        $this->webhookPost($canceled, $this->signature($canceled))->assertOk();
        $this->webhookPost($processing, $this->signature($processing))->assertOk();

        $this->assertSame('canceled', $payment->fresh()->status);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.payment_canceled')->count());
        $this->assertSame('void', $service->void($invoice->fresh(), $workspace)->status);
    }

    public function test_payment_method_webhooks_sync_safe_metadata_and_native_lifecycle_events(): void
    {
        [, $workspace, $company] = $this->tenant('methods');
        ClientStripeCustomer::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'stripe_customer_id' => 'cus_synthetic_activity',
        ]);
        $methodObject = [
            'id' => 'pm_synthetic_activity',
            'customer' => 'cus_synthetic_activity',
            'type' => 'card',
            'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2032],
            'metadata' => ['raw_provider_note' => 'must not enter activity'],
        ];
        $attached = $this->eventPayload('evt_method_attached', 'payment_method.attached', $methodObject, 100);

        $this->webhookPost($attached, $this->signature($attached))->assertOk();
        $this->webhookPost($attached, $this->signature($attached))->assertOk()->assertJsonPath('duplicate', true);

        $method = ClientStripePaymentMethod::query()->sole();
        $added = ClientCompanyActivity::query()->where('action', 'payment_method.added')->sole();
        $this->assertSame($method->public_id, $added->subject_public_id);
        $this->assertSame('client_stripe_payment_method', $added->subject_type);
        $this->assertSame(['type', 'brand', 'last4', 'exp_month', 'exp_year'], array_keys($added->payload));
        $this->assertStringNotContainsString('pm_synthetic_activity', json_encode($added->payload, JSON_THROW_ON_ERROR));

        $defaultChanged = $this->eventPayload('evt_method_default', 'customer.updated', [
            'id' => 'cus_synthetic_activity',
            'invoice_settings' => ['default_payment_method' => 'pm_synthetic_activity'],
        ], 150);
        $this->webhookPost($defaultChanged, $this->signature($defaultChanged))->assertOk();
        $this->assertTrue($method->fresh()->is_default);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'payment_method.default_changed')->count());

        $detached = $this->eventPayload('evt_method_detached', 'payment_method.detached', [
            'id' => 'pm_synthetic_activity',
        ], 200);
        $this->webhookPost($detached, $this->signature($detached))->assertOk();
        $this->assertSoftDeleted('client_stripe_payment_methods', ['id' => $method->id]);
        $removed = ClientCompanyActivity::query()->where('action', 'payment_method.removed')->sole();
        $this->assertSame($method->public_id, $removed->subject_public_id);
        $this->assertSame($workspace->id, $removed->workspace_id);
        $this->assertSame($company->id, $removed->client_company_id);

        $reattached = $this->eventPayload('evt_method_reattached', 'payment_method.attached', $methodObject, 300);
        $this->webhookPost($reattached, $this->signature($reattached))->assertOk();
        $restored = ClientStripePaymentMethod::query()->sole();
        $this->assertSame($method->public_id, $restored->public_id);
        $this->assertSame(2, ClientCompanyActivity::query()->where('action', 'payment_method.added')->count());
    }

    public function test_detach_tombstone_blocks_an_older_attach_event(): void
    {
        [, $workspace, $company] = $this->tenant('method-tombstone');
        ClientStripeCustomer::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'stripe_customer_id' => 'cus_method_tombstone',
        ]);
        $detached = $this->eventPayload('evt_detach_first', 'payment_method.detached', [
            'id' => 'pm_out_of_order',
        ], 200);
        $olderAttach = $this->eventPayload('evt_attach_late', 'payment_method.attached', [
            'id' => 'pm_out_of_order',
            'customer' => 'cus_method_tombstone',
            'type' => 'card',
            'card' => ['brand' => 'visa', 'last4' => '4242'],
        ], 100);

        $this->webhookPost($detached, $this->signature($detached))->assertOk();
        $this->webhookPost($olderAttach, $this->signature($olderAttach))->assertOk();

        $this->assertDatabaseCount('client_stripe_payment_methods', 0);
        $this->assertDatabaseHas('stripe_payment_method_states', [
            'provider_id_hash' => hash('sha256', 'pm_out_of_order'),
            'workspace_id' => null,
            'state' => 'detached',
            'provider_created_at' => 200,
        ]);
        $this->assertDatabaseHas('client_stripe_events', [
            'stripe_event_id' => 'evt_attach_late',
            'status' => 'processed',
            'workspace_id' => null,
        ]);
    }

    public function test_detach_uses_the_adapter_route_to_scope_the_tenant_read(): void
    {
        [, $firstWorkspace, $firstCompany] = $this->tenant('detach-route-first');
        [, $secondWorkspace, $secondCompany] = $this->tenant('detach-route-second');
        $firstCustomer = ClientStripeCustomer::query()->create([
            'workspace_id' => $firstWorkspace->id,
            'client_company_id' => $firstCompany->id,
            'stripe_customer_id' => 'cus_detach_route_first',
        ]);
        $secondCustomer = ClientStripeCustomer::query()->create([
            'workspace_id' => $secondWorkspace->id,
            'client_company_id' => $secondCompany->id,
            'stripe_customer_id' => 'cus_detach_route_second',
        ]);
        $attached = $this->eventPayload('evt_detach_route_attach', 'payment_method.attached', [
            'id' => 'pm_detach_route',
            'customer' => 'cus_detach_route_first',
            'type' => 'card',
            'card' => ['brand' => 'visa', 'last4' => '4242'],
        ], 100);
        $this->webhookPost($attached, $this->signature($attached))->assertOk();
        $method = ClientStripePaymentMethod::query()->sole();

        // A corrupt/hostile router record must fail closed: the tenant-scoped
        // read under the resolved second owner cannot load the first owner's row.
        DB::table('stripe_payment_method_states')
            ->where('provider_id_hash', hash('sha256', 'pm_detach_route'))
            ->update([
                'workspace_id' => $secondWorkspace->id,
                'client_company_id' => $secondCompany->id,
                'client_stripe_customer_id' => $secondCustomer->id,
            ]);
        $detached = $this->eventPayload('evt_detach_route_detach', 'payment_method.detached', [
            'id' => 'pm_detach_route',
        ], 200);
        $this->webhookPost($detached, $this->signature($detached))->assertOk();

        $this->assertDatabaseHas('client_stripe_payment_methods', [
            'id' => $method->id,
            'workspace_id' => $firstWorkspace->id,
            'client_stripe_customer_id' => $firstCustomer->id,
            'deleted_at' => null,
        ]);
        $this->assertSame(0, ClientCompanyActivity::query()->where('action', 'payment_method.removed')->count());
    }

    public function test_unmapped_customer_events_are_acknowledged_as_permanently_irrelevant(): void
    {
        $attached = $this->eventPayload('evt_unmapped_attached', 'payment_method.attached', [
            'id' => 'pm_unmapped',
            'customer' => 'cus_unmapped',
            'type' => 'card',
            'card' => ['brand' => 'visa', 'last4' => '4242'],
        ], 100);
        $updated = $this->eventPayload('evt_unmapped_customer', 'customer.updated', [
            'id' => 'cus_unmapped',
            'invoice_settings' => ['default_payment_method' => 'pm_unmapped'],
        ], 110);

        $this->webhookPost($attached, $this->signature($attached))->assertOk();
        $this->webhookPost($updated, $this->signature($updated))->assertOk();

        $this->assertDatabaseCount('client_stripe_payment_methods', 0);
        $this->assertSame(2, DB::table('client_stripe_events')->where('status', 'processed')->whereNull('workspace_id')->count());
    }

    public function test_payment_method_provider_ids_cannot_be_rebound_across_tenants(): void
    {
        [, $firstWorkspace, $firstCompany] = $this->tenant('method-first');
        [, $secondWorkspace, $secondCompany] = $this->tenant('method-second');
        foreach ([
            [$firstWorkspace, $firstCompany, 'cus_method_first'],
            [$secondWorkspace, $secondCompany, 'cus_method_second'],
        ] as [$workspace, $company, $providerId]) {
            ClientStripeCustomer::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'stripe_customer_id' => $providerId,
            ]);
        }

        $first = $this->eventPayload('evt_method_first', 'payment_method.attached', [
            'id' => 'pm_shared_provider_id', 'customer' => 'cus_method_first', 'type' => 'card',
            'card' => ['brand' => 'visa', 'last4' => '4242'],
        ]);
        $second = $this->eventPayload('evt_method_second', 'payment_method.attached', [
            'id' => 'pm_shared_provider_id', 'customer' => 'cus_method_second', 'type' => 'card',
            'card' => ['brand' => 'visa', 'last4' => '1881'],
        ]);

        $this->webhookPost($first, $this->signature($first))->assertOk();
        $this->webhookPost($second, $this->signature($second))->assertStatus(409);

        $method = ClientStripePaymentMethod::query()->sole();
        $this->assertSame($firstWorkspace->id, $method->workspace_id);
        $this->assertSame($firstCompany->id, $method->client_company_id);
        $this->assertSame('4242', $method->last4);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'payment_method.added')->count());
        $this->assertDatabaseHas('client_stripe_events', [
            'stripe_event_id' => 'evt_method_second',
            'workspace_id' => null,
            'status' => 'failed',
        ]);
    }

    public function test_event_id_replayed_with_different_payload_is_rejected(): void
    {
        $payload = $this->payload();
        $this->webhookPost($payload, $this->signature($payload))->assertOk();
        $conflict = str_replace('"amount":1000', '"amount":999', $payload);

        $this->webhookPost($conflict, $this->signature($conflict))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Conflicting Stripe webhook event.');
        $this->assertDatabaseCount('client_stripe_events', 1);
    }

    public function test_failed_event_persists_a_failure_record_and_is_not_retried_into_the_same_error(): void
    {
        [, $workspace, $company] = $this->tenant();
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-WEBHOOK-MISMATCH', 'currency' => 'USD',
        ], [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $service->issue($invoice, $workspace);
        $service->applyPayment($invoice, [
            'amount' => 1000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'pending',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_billing',
        ], $workspace);

        $mismatch = json_encode([
            'id' => 'evt_synthetic_mismatch', 'object' => 'event', 'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_synthetic_billing', 'amount' => 900, 'amount_received' => 900, 'currency' => 'usd', 'metadata' => []]],
        ], JSON_THROW_ON_ERROR);

        $this->webhookPost($mismatch, $this->signature($mismatch))->assertStatus(409);
        $this->assertDatabaseHas('client_stripe_events', [
            'stripe_event_id' => 'evt_synthetic_mismatch',
            'status' => 'failed',
            'error_summary' => 'Stripe payment does not match the pending invoice payment.',
        ]);

        $this->webhookPost($mismatch, $this->signature($mismatch))->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('client_stripe_events', 1);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->paid_amount);
    }

    public function test_payment_succeeding_against_a_void_invoice_is_recorded_as_a_failed_event_not_absorbed(): void
    {
        [, $workspace, $company] = $this->tenant();
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-WEBHOOK-VOID', 'currency' => 'USD',
        ], [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $service->issue($invoice, $workspace);
        $service->applyPayment($invoice, [
            'amount' => 1000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'pending',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_billing',
        ], $workspace);
        // Voiding through the service is now blocked while payments are pending, so
        // simulate the race where the invoice was voided out-of-band anyway.
        $invoice->forceFill(['status' => 'void', 'voided_at' => now(), 'balance_amount' => 0])->save();

        $payload = $this->payload($invoice->public_id, $workspace->public_id);
        $this->webhookPost($payload, $this->signature($payload))->assertStatus(409);

        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_synthetic_billing', 'status' => 'failed']);
        $fresh = $invoice->fresh();
        $this->assertSame('void', $fresh->status);
        $this->assertSame(0, $fresh->paid_amount);
        $this->assertDatabaseHas('client_invoice_payments', [
            'provider_payment_identifier' => 'pi_synthetic_billing',
            'status' => 'pending',
        ]);
    }

    private function payload(?string $invoicePublicId = null, ?string $workspacePublicId = null, string $eventId = 'evt_synthetic_billing', string $paymentId = 'pi_synthetic_billing'): string
    {
        return json_encode(['id' => $eventId, 'object' => 'event', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => $paymentId, 'amount' => 1000, 'amount_received' => 1000, 'currency' => 'usd', 'metadata' => ['invoice_public_id' => $invoicePublicId ?? 'missing', 'workspace_public_id' => $workspacePublicId ?? 'missing']]]], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $object */
    private function eventPayload(string $eventId, string $type, array $object, ?int $created = null): string
    {
        $event = [
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ];
        if ($created !== null) {
            $event['created'] = $created;
        }

        return json_encode($event, JSON_THROW_ON_ERROR);
    }

    private function signature(string $payload): string
    {
        $timestamp = time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_synthetic');
    }

    /** @return TestResponse<Response> */
    private function webhookPost(string $payload, string $signature): TestResponse
    {
        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $payload);
    }

    /** @return array{0:User,1:Workspace,2:ClientCompany} */
    private function tenant(string $suffix = 'primary'): array
    {
        $owner = User::factory()->create(['email' => 'stripe-owner-'.$suffix.'@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Stripe Workspace '.$suffix, 'slug' => 'stripe-workspace-'.$suffix]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Stripe Client '.$suffix, 'slug' => 'stripe-client-'.$suffix]);

        return [$owner, $workspace, $company];
    }
}
