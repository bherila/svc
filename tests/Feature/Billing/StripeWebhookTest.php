<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $payload = $this->payload($invoice->public_id);
        $headers = ['Stripe-Signature' => $this->signature($payload)];

        $this->webhookPost($payload, $headers['Stripe-Signature'])->assertOk()->assertJsonPath('duplicate', false);
        $this->webhookPost($payload, $headers['Stripe-Signature'])->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('client_stripe_events', 1);
        $this->assertDatabaseCount('client_invoice_payments', 1);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_partial_refund_reopens_only_the_refunded_amount(): void
    {
        [, $workspace, $company] = $this->tenant();
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-WEBHOOK-REFUND', 'currency' => 'USD',
        ], [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $service->issue($invoice, $workspace);
        $success = $this->payload($invoice->public_id);
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

    private function payload(?string $invoicePublicId = null): string
    {
        return json_encode(['id' => 'evt_synthetic_billing', 'object' => 'event', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_synthetic_billing', 'amount' => 1000, 'amount_received' => 1000, 'currency' => 'usd', 'metadata' => ['invoice_public_id' => $invoicePublicId ?? 'missing']]]], JSON_THROW_ON_ERROR);
    }

    private function signature(string $payload): string
    {
        $timestamp = time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_synthetic');
    }

    private function webhookPost(string $payload, string $signature): TestResponse
    {
        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $payload);
    }

    /** @return array{0:User,1:Workspace,2:ClientCompany} */
    private function tenant(): array
    {
        $owner = User::factory()->create(['email' => 'stripe-owner@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Stripe Workspace', 'slug' => 'stripe-workspace']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Stripe Client', 'slug' => 'stripe-client']);

        return [$owner, $workspace, $company];
    }
}
