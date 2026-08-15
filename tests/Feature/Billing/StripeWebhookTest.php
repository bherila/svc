<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
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
