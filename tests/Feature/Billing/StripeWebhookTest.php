<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientStripeCustomer;
use App\Models\ClientStripePaymentMethod;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Finance\PaymentReconciliationService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\PaymentVersionChanged;
use App\Support\Finance\ReconciliationReviewReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $stripeRequests = [];

    /** @var list<array<string, mixed>> */
    private array $stripeRefunds = [];

    /** @var list<array<string, mixed>> */
    private array $stripeDisputes = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.webhook_secret' => 'whsec_synthetic']);
        if (! Route::has('svc.billing.stripe.webhook')) {
            require base_path('routes/billing.php');
        }
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
        parent::tearDown();
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
        $this->fakeStripeRefunds([['id' => 're_initial_fixture', 'object' => 'refund', 'amount' => 400, 'currency' => 'usd', 'status' => 'succeeded', 'charge' => 'ch_synthetic_refund']]);
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
        $this->fakeStripeDisputes([['id' => 'dp_activity_transitions', 'charge' => 'ch_activity_transitions', 'payment_intent' => 'pi_activity_transitions', 'amount' => 1000, 'currency' => 'usd', 'status' => 'needs_response']]);
        $this->webhookPost($dispute, $this->signature($dispute))->assertOk();
        $this->webhookPost($dispute, $this->signature($dispute))->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame('disputed', $payment->fresh()->status);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.payment_disputed')->count());
    }

    public function test_refund_webhook_after_a_full_reconciliation_is_recorded_and_flags_the_allocation(): void
    {
        [$owner, $workspace, $payment] = $this->stripePayment('refund-reconciled');
        $allocation = app(PaymentReconciliationService::class)->upsert($workspace, $payment, $owner, [
            'external_system_slug' => 'finance', 'external_transaction_uuid' => (string) Str::uuid(), 'allocated_amount' => 1000, 'currency' => 'USD',
        ]);

        $refund = $this->eventPayload('evt_reconciled_full_refund', 'charge.refunded', [
            'id' => 'ch_reconciled_full_refund', 'payment_intent' => 'pi_synthetic_billing', 'amount_refunded' => 1000, 'currency' => 'usd',
        ], 100);
        $this->fakeStripeRefunds([['id' => 're_initial_fixture', 'object' => 'refund', 'amount' => 1000, 'currency' => 'usd', 'status' => 'succeeded', 'charge' => 'ch_reconciled_full_refund']]);
        $this->webhookPost($refund, $this->signature($refund))->assertOk()->assertJsonPath('duplicate', false);

        $fresh = $payment->fresh();
        $this->assertSame('refunded', $fresh?->status);
        $this->assertSame(1000, $fresh?->refunded_amount);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_reconciled_full_refund', 'status' => 'processed']);
        $this->assertTrue($allocation->fresh()->is_active);
        $this->assertSame(1000, $allocation->fresh()->allocated_amount);
        $this->assertSame(ReconciliationReviewReason::ProcessorRefund, $allocation->fresh()->review_reason);
    }

    public function test_dispute_webhook_after_reconciliation_flags_the_allocation(): void
    {
        [$owner, $workspace, $payment] = $this->stripePayment('dispute-reconciled');
        $allocation = app(PaymentReconciliationService::class)->upsert($workspace, $payment, $owner, [
            'external_system_slug' => 'finance', 'external_transaction_uuid' => (string) Str::uuid(), 'allocated_amount' => 1000, 'currency' => 'USD',
        ]);

        $dispute = $this->eventPayload('evt_reconciled_dispute', 'charge.dispute.created', [
            'id' => 'dp_reconciled', 'charge' => 'ch_reconciled', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 1000, 'currency' => 'usd', 'status' => 'needs_response',
        ], 100);
        $this->fakeStripeDisputes([['id' => 'dp_reconciled', 'charge' => 'ch_reconciled', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 1000, 'currency' => 'usd', 'status' => 'needs_response']]);
        $this->webhookPost($dispute, $this->signature($dispute))->assertOk();

        $this->assertSame('disputed', $payment->fresh()?->status);
        $this->assertSame(ReconciliationReviewReason::ProcessorDispute, $allocation->fresh()->review_reason);
    }

    public function test_a_partially_won_dispute_restores_the_payment(): void
    {
        [, , $payment, $invoice] = $this->stripePayment('partial-dispute-win');
        $dispute = $this->eventPayload('evt_partial_dispute_created', 'charge.dispute.created', [
            'id' => 'dp_partial', 'charge' => 'ch_partial', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 400, 'currency' => 'usd', 'status' => 'needs_response',
        ], 100);
        $this->fakeStripeDisputes([['id' => 'dp_partial', 'charge' => 'ch_partial', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 400, 'currency' => 'usd', 'status' => 'needs_response']]);
        $this->webhookPost($dispute, $this->signature($dispute))->assertOk();
        $this->assertSame('disputed', $payment->fresh()?->status);
        $this->assertSame(400, $payment->fresh()?->disputed_amount);
        $this->assertSame(400, $invoice->fresh()->balance_amount);

        $won = $this->eventPayload('evt_partial_dispute_won', 'charge.dispute.closed', [
            'id' => 'dp_partial', 'charge' => 'ch_partial', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 400, 'currency' => 'usd', 'status' => 'won',
        ], 200);
        $this->fakeStripeDisputes([['id' => 'dp_partial', 'charge' => 'ch_partial', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 400, 'currency' => 'usd', 'status' => 'won']]);
        $this->webhookPost($won, $this->signature($won))->assertOk()->assertJsonPath('duplicate', false);

        $this->assertSame('succeeded', $payment->fresh()?->status);
        $this->assertNull($payment->fresh()?->disputed_amount);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_partial_dispute_won', 'status' => 'processed']);
    }

    public function test_a_won_dispute_larger_than_the_payment_or_in_another_currency_is_refused(): void
    {
        [, , $payment] = $this->stripePayment('dispute-mismatch');
        $dispute = $this->eventPayload('evt_mismatch_dispute_created', 'charge.dispute.created', [
            'id' => 'dp_mismatch', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 1000, 'currency' => 'usd',
        ], 100);
        $this->fakeStripeDisputes([['id' => 'dp_mismatch', 'charge' => 'ch_mismatch', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 1000, 'currency' => 'usd', 'status' => 'needs_response']]);
        $this->webhookPost($dispute, $this->signature($dispute))->assertOk();

        $tooLarge = $this->eventPayload('evt_mismatch_dispute_large', 'charge.dispute.closed', [
            'id' => 'dp_mismatch', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 1001, 'currency' => 'usd', 'status' => 'won',
        ], 200);
        $this->fakeStripeDisputes([['id' => 'dp_mismatch', 'charge' => 'ch_mismatch', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 1001, 'currency' => 'usd', 'status' => 'won']]);
        $this->webhookPost($tooLarge, $this->signature($tooLarge))->assertStatus(503);
        $otherCurrency = $this->eventPayload('evt_mismatch_dispute_currency', 'charge.dispute.closed', [
            'id' => 'dp_mismatch', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 1000, 'currency' => 'eur', 'status' => 'won',
        ], 300);
        $this->fakeStripeDisputes([['id' => 'dp_mismatch', 'charge' => 'ch_mismatch', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 1000, 'currency' => 'eur', 'status' => 'won']]);
        $this->webhookPost($otherCurrency, $this->signature($otherCurrency))->assertStatus(503);

        $this->assertSame('disputed', $payment->fresh()?->status);
    }

    public function test_a_failed_refund_lowers_the_refunded_amount_again(): void
    {
        [, , $payment, $invoice] = $this->stripePayment('failed-refund');
        $refund = $this->eventPayload('evt_failing_refund_created', 'charge.refunded', [
            'id' => 'ch_failing_refund', 'payment_intent' => 'pi_synthetic_billing', 'amount_refunded' => 400, 'currency' => 'usd',
        ], 100);
        $this->fakeStripeRefunds([['id' => 're_initial_fixture', 'object' => 'refund', 'amount' => 400, 'currency' => 'usd', 'status' => 'succeeded', 'charge' => 'ch_failing_refund']]);
        $this->webhookPost($refund, $this->signature($refund))->assertOk();
        $this->assertSame(400, $payment->fresh()?->refunded_amount);
        $this->assertSame(400, $invoice->fresh()->balance_amount);

        $this->fakeStripeRefunds([
            ['id' => 're_failing', 'object' => 'refund', 'amount' => 400, 'currency' => 'usd', 'status' => 'failed', 'charge' => 'ch_failing_refund'],
        ]);
        $failed = $this->eventPayload('evt_failing_refund_failed', 'charge.refund.updated', [
            'id' => 're_failing', 'object' => 'refund', 'amount' => 400, 'currency' => 'usd', 'status' => 'failed',
            'charge' => 'ch_failing_refund', 'payment_intent' => 'pi_synthetic_billing',
        ], 200);
        $this->webhookPost($failed, $this->signature($failed))->assertOk()->assertJsonPath('duplicate', false);

        $fresh = $payment->fresh();
        $this->assertSame(0, $fresh?->refunded_amount);
        $this->assertSame('succeeded', $fresh?->status);
        $this->assertSame(0, $invoice->fresh()->balance_amount);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('ch_failing_refund', $this->stripeRequests[0]['charge'] ?? null);

        // The same failure reported again, by its newer name, changes nothing.
        $again = $this->eventPayload('evt_failing_refund_failed_again', 'refund.failed', [
            'id' => 're_failing', 'object' => 'refund', 'amount' => 400, 'currency' => 'usd', 'status' => 'failed',
            'charge' => 'ch_failing_refund', 'payment_intent' => 'pi_synthetic_billing',
        ], 300);
        $this->webhookPost($again, $this->signature($again))->assertOk();
        $this->assertSame(0, $payment->fresh()?->refunded_amount);
        // The failure gave money back: recorded as a reversal, not a second refund.
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.payment_refunded')->count());
        $reversal = ClientCompanyActivity::query()->where('action', 'invoice.payment_refund_reversed')->sole();
        $this->assertSame(400, $reversal->payload['previous_refunded_amount'] ?? null);
        $this->assertSame(0, $reversal->payload['refunded_amount'] ?? null);
    }

    public function test_a_refund_total_read_before_the_payment_moved_is_retried_not_applied(): void
    {
        [, , $payment] = $this->stripePayment('stale-refund-read');
        $refund = $this->eventPayload('evt_stale_read_refund', 'charge.refunded', [
            'id' => 'ch_stale_read', 'payment_intent' => 'pi_synthetic_billing', 'amount_refunded' => 400, 'currency' => 'usd',
        ], 100);
        $this->fakeStripeRefunds([['id' => 're_initial_fixture', 'object' => 'refund', 'amount' => 400, 'currency' => 'usd', 'status' => 'succeeded', 'charge' => 'ch_stale_read']]);
        $this->webhookPost($refund, $this->signature($refund))->assertOk();

        // While Stripe is being read, a newer refund is recorded on the payment.
        $this->fakeStripeRefunds([
            ['id' => 're_stale_read', 'object' => 'refund', 'amount' => 400, 'currency' => 'usd', 'status' => 'failed', 'charge' => 'ch_stale_read'],
        ], function () use ($payment): void {
            app(InvoiceLifecycleService::class)->setRefundedAmount($payment->fresh(), 700);
        });
        $failed = $this->eventPayload('evt_stale_read_failed', 'charge.refund.updated', [
            'id' => 're_stale_read', 'object' => 'refund', 'amount' => 400, 'currency' => 'usd', 'status' => 'failed',
            'charge' => 'ch_stale_read', 'payment_intent' => 'pi_synthetic_billing',
        ], 200);
        $this->webhookPost($failed, $this->signature($failed))->assertStatus(503);

        $this->assertSame(700, $payment->fresh()?->refunded_amount);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_stale_read_failed', 'status' => 'received']);

        // Stripe's retry reads the current refunds and applies them.
        $this->fakeStripeRefunds([
            ['id' => 're_stale_read', 'object' => 'refund', 'amount' => 400, 'currency' => 'usd', 'status' => 'failed', 'charge' => 'ch_stale_read'],
            ['id' => 're_stale_newer', 'object' => 'refund', 'amount' => 300, 'currency' => 'usd', 'status' => 'succeeded', 'charge' => 'ch_stale_read'],
        ]);
        $this->webhookPost($failed, $this->signature($failed))->assertOk()->assertJsonPath('duplicate', false);
        $this->assertSame(300, $payment->fresh()?->refunded_amount);
    }

    public function test_a_failed_refund_counts_only_the_refunds_still_standing(): void
    {
        [, , $payment] = $this->stripePayment('one-of-two-refunds-failed');
        $refund = $this->eventPayload('evt_two_refunds', 'charge.refunded', [
            'id' => 'ch_two_refunds', 'payment_intent' => 'pi_synthetic_billing', 'amount_refunded' => 700, 'currency' => 'usd',
        ], 100);
        $this->fakeStripeRefunds([['id' => 're_initial_fixture', 'object' => 'refund', 'amount' => 700, 'currency' => 'usd', 'status' => 'succeeded', 'charge' => 'ch_two_refunds']]);
        $this->webhookPost($refund, $this->signature($refund))->assertOk();

        $this->fakeStripeRefunds([
            ['id' => 're_standing', 'object' => 'refund', 'amount' => 300, 'currency' => 'usd', 'status' => 'succeeded', 'charge' => 'ch_two_refunds'],
            ['id' => 're_pending', 'object' => 'refund', 'amount' => 100, 'currency' => 'usd', 'status' => 'pending', 'charge' => 'ch_two_refunds'],
            ['id' => 're_canceled', 'object' => 'refund', 'amount' => 300, 'currency' => 'usd', 'status' => 'canceled', 'charge' => 'ch_two_refunds'],
        ]);
        $canceled = $this->eventPayload('evt_two_refunds_canceled', 'charge.refund.updated', [
            'id' => 're_canceled', 'object' => 'refund', 'amount' => 300, 'currency' => 'usd', 'status' => 'canceled',
            'charge' => 'ch_two_refunds', 'payment_intent' => 'pi_synthetic_billing',
        ], 200);
        $this->webhookPost($canceled, $this->signature($canceled))->assertOk();

        $this->assertSame(400, $payment->fresh()?->refunded_amount);
    }

    public function test_a_refund_update_that_is_not_a_failure_asks_stripe_nothing(): void
    {
        [, , $payment] = $this->stripePayment('refund-reference-update');
        $refund = $this->eventPayload('evt_reference_refund', 'charge.refunded', [
            'id' => 'ch_reference_refund', 'payment_intent' => 'pi_synthetic_billing', 'amount_refunded' => 400, 'currency' => 'usd',
        ], 100);
        $this->fakeStripeRefunds([['id' => 're_initial_fixture', 'object' => 'refund', 'amount' => 400, 'currency' => 'usd', 'status' => 'succeeded', 'charge' => 'ch_reference_refund']]);
        $this->webhookPost($refund, $this->signature($refund))->assertOk();
        $this->fakeStripeRefunds([]);
        $this->stripeRequests = []; // Measure only this unrelated refund update.

        $updated = $this->eventPayload('evt_reference_refund_updated', 'charge.refund.updated', [
            'id' => 're_reference', 'object' => 'refund', 'amount' => 400, 'currency' => 'usd', 'status' => 'succeeded',
            'charge' => 'ch_reference_refund', 'payment_intent' => 'pi_synthetic_billing',
        ], 200);
        $this->webhookPost($updated, $this->signature($updated))->assertOk();

        $this->assertSame([], $this->stripeRequests);
        $this->assertSame(400, $payment->fresh()?->refunded_amount);
    }

    public function test_a_failed_refund_under_dispute_is_recorded_and_the_dispute_kept(): void
    {
        [, , $payment] = $this->stripePayment('refund-failed-under-dispute');
        $refund = $this->eventPayload('evt_disputed_refund', 'charge.refunded', [
            'id' => 'ch_disputed_refund', 'payment_intent' => 'pi_synthetic_billing', 'amount_refunded' => 400, 'currency' => 'usd',
        ], 100);
        $this->fakeStripeRefunds([['id' => 're_initial_fixture', 'object' => 'refund', 'amount' => 400, 'currency' => 'usd', 'status' => 'succeeded', 'charge' => 'ch_disputed_refund']]);
        $this->webhookPost($refund, $this->signature($refund))->assertOk();
        $dispute = $this->eventPayload('evt_disputed_refund_dispute', 'charge.dispute.created', [
            'id' => 'dp_disputed_refund', 'charge' => 'ch_disputed_refund', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 1000, 'currency' => 'usd',
        ], 150);
        $this->fakeStripeDisputes([['id' => 'dp_disputed_refund', 'charge' => 'ch_disputed_refund', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 1000, 'currency' => 'usd', 'status' => 'needs_response']]);
        $this->webhookPost($dispute, $this->signature($dispute))->assertOk();
        $this->assertSame('disputed', $payment->fresh()?->status);

        // A refund still pending when the charge is disputed fails with
        // `charge_for_pending_refund_disputed`.
        $this->fakeStripeRefunds([
            ['id' => 're_disputed', 'object' => 'refund', 'amount' => 400, 'currency' => 'usd', 'status' => 'failed', 'charge' => 'ch_disputed_refund'],
        ]);
        $failed = $this->eventPayload('evt_disputed_refund_failed', 'charge.refund.updated', [
            'id' => 're_disputed', 'object' => 'refund', 'amount' => 400, 'currency' => 'usd', 'status' => 'failed',
            'failure_reason' => 'charge_for_pending_refund_disputed', 'charge' => 'ch_disputed_refund', 'payment_intent' => 'pi_synthetic_billing',
        ], 200);
        $this->webhookPost($failed, $this->signature($failed))->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame(0, $fresh?->refunded_amount);
        $this->assertSame('disputed', $fresh?->status);
    }

    public function test_a_refund_reversal_delivered_after_a_newer_payment_event_is_still_applied(): void
    {
        [, , $payment] = $this->stripePayment('late-refund-reversal');
        $refund = $this->eventPayload('evt_late_reversal_refund', 'charge.refunded', [
            'id' => 'ch_late_reversal', 'payment_intent' => 'pi_synthetic_billing', 'amount_refunded' => 400, 'currency' => 'usd',
        ], 100);
        $this->fakeStripeRefunds([['id' => 're_initial_fixture', 'object' => 'refund', 'amount' => 400, 'currency' => 'usd', 'status' => 'succeeded', 'charge' => 'ch_late_reversal']]);
        $this->webhookPost($refund, $this->signature($refund))->assertOk();
        $dispute = $this->eventPayload('evt_late_reversal_dispute', 'charge.dispute.created', [
            'id' => 'dp_late_reversal', 'charge' => 'ch_late_reversal', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 1000, 'currency' => 'usd',
        ], 300);
        $this->fakeStripeDisputes([['id' => 'dp_late_reversal', 'charge' => 'ch_late_reversal', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 1000, 'currency' => 'usd', 'status' => 'needs_response']]);
        $this->webhookPost($dispute, $this->signature($dispute))->assertOk();

        // Stripe does not order its events: the refund failed at 200 but the
        // notification arrives after the dispute's at 300. What Stripe holds
        // refunded now is read live, so it is applied whatever came before.
        $this->fakeStripeRefunds([
            ['id' => 're_late_reversal', 'object' => 'refund', 'amount' => 400, 'currency' => 'usd', 'status' => 'failed', 'charge' => 'ch_late_reversal'],
        ]);
        $failed = $this->eventPayload('evt_late_reversal_failed', 'charge.refund.updated', [
            'id' => 're_late_reversal', 'object' => 'refund', 'amount' => 400, 'currency' => 'usd', 'status' => 'failed',
            'charge' => 'ch_late_reversal', 'payment_intent' => 'pi_synthetic_billing',
        ], 200);
        $this->webhookPost($failed, $this->signature($failed))->assertOk()->assertJsonPath('duplicate', false);

        $fresh = $payment->fresh();
        $this->assertSame(0, $fresh?->refunded_amount);
        $this->assertSame('disputed', $fresh?->status);
        // The older event does not become the payment's latest.
        $this->assertSame(300, $fresh?->provider_event_created_at);
        $this->assertSame('evt_late_reversal_dispute', $fresh?->provider_event_id);

        $again = $this->eventPayload('evt_late_reversal_failed_again', 'refund.failed', [
            'id' => 're_late_reversal', 'object' => 'refund', 'amount' => 400, 'currency' => 'usd', 'status' => 'failed',
            'charge' => 'ch_late_reversal', 'payment_intent' => 'pi_synthetic_billing',
        ], 250);
        $this->webhookPost($again, $this->signature($again))->assertOk();
        $this->assertSame(0, $payment->fresh()?->refunded_amount);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.payment_refund_reversed')->count());
    }

    public function test_a_won_dispute_on_an_invoice_paid_again_meanwhile_is_restored_as_an_overpayment(): void
    {
        [, $workspace, $payment, $invoice] = $this->stripePayment('won-after-repayment');
        $dispute = $this->eventPayload('evt_repaid_dispute', 'charge.dispute.created', [
            'id' => 'dp_repaid', 'charge' => 'ch_repaid', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 1000, 'currency' => 'usd',
        ], 100);
        $this->fakeStripeDisputes([['id' => 'dp_repaid', 'charge' => 'ch_repaid', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 1000, 'currency' => 'usd', 'status' => 'needs_response']]);
        $this->webhookPost($dispute, $this->signature($dispute))->assertOk();
        app(InvoiceLifecycleService::class)->applyPayment($invoice->fresh(), [
            'amount' => 1000, 'currency' => 'USD', 'method' => 'wire', 'status' => 'succeeded', 'idempotency_key' => 'repaid-during-dispute',
        ], $workspace);
        $this->assertSame('disputed', $invoice->fresh()->status);

        $won = $this->eventPayload('evt_repaid_dispute_won', 'charge.dispute.closed', [
            'id' => 'dp_repaid', 'charge' => 'ch_repaid', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 1000, 'currency' => 'usd', 'status' => 'won',
        ], 200);
        $this->fakeStripeDisputes([['id' => 'dp_repaid', 'charge' => 'ch_repaid', 'payment_intent' => 'pi_synthetic_billing', 'amount' => 1000, 'currency' => 'usd', 'status' => 'won']]);
        $this->webhookPost($won, $this->signature($won))->assertOk()->assertJsonPath('duplicate', false);

        $this->assertSame('succeeded', $payment->fresh()?->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->balance_amount);
        $received = ClientCompanyActivity::query()->where('action', 'invoice.payment_received')->where('subject_public_id', $payment->public_id)->latest('id')->firstOrFail();
        $this->assertSame(1000, $received->payload['overpaid_amount'] ?? null);
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

    public function test_older_processing_event_cannot_regress_a_failed_payment(): void
    {
        [, $workspace, $company] = $this->tenant('failed-ordering');
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-FAILED-ORDERING', 'currency' => 'USD',
        ], [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $service->issue($invoice, $workspace);
        $payment = $service->applyPayment($invoice, [
            'amount' => 1000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'pending',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_failed_ordering',
        ], $workspace);

        $failed = $this->eventPayload('evt_newer_failure', 'payment_intent.payment_failed', [
            'id' => 'pi_failed_ordering',
        ], 200);
        $processing = $this->eventPayload('evt_older_processing_after_failure', 'payment_intent.processing', [
            'id' => 'pi_failed_ordering',
        ], 100);
        $this->webhookPost($failed, $this->signature($failed))->assertOk();
        $this->webhookPost($processing, $this->signature($processing))->assertOk();

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame(200, $payment->fresh()->provider_event_created_at);
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

    public function test_older_customer_update_cannot_rewind_the_default_payment_method(): void
    {
        [, $workspace, $company] = $this->tenant('default-ordering');
        ClientStripeCustomer::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'stripe_customer_id' => 'cus_default_ordering',
        ]);
        foreach (['a', 'b'] as $suffix) {
            $attached = $this->eventPayload('evt_attach_'.$suffix, 'payment_method.attached', [
                'id' => 'pm_default_'.$suffix,
                'customer' => 'cus_default_ordering',
                'type' => 'card',
                'card' => ['brand' => 'visa', 'last4' => $suffix === 'a' ? '4242' : '1881'],
            ], 50);
            $this->webhookPost($attached, $this->signature($attached))->assertOk();
        }
        $newer = $this->eventPayload('evt_default_b', 'customer.updated', [
            'id' => 'cus_default_ordering',
            'invoice_settings' => ['default_payment_method' => 'pm_default_b'],
        ], 200);
        $older = $this->eventPayload('evt_default_a', 'customer.updated', [
            'id' => 'cus_default_ordering',
            'invoice_settings' => ['default_payment_method' => 'pm_default_a'],
        ], 100);

        $this->webhookPost($newer, $this->signature($newer))->assertOk();
        $this->webhookPost($older, $this->signature($older))->assertOk();

        $this->assertTrue(ClientStripePaymentMethod::query()->where('stripe_payment_method_id', 'pm_default_b')->sole()->is_default);
        $this->assertFalse(ClientStripePaymentMethod::query()->where('stripe_payment_method_id', 'pm_default_a')->sole()->is_default);
        $this->assertSame(200, ClientStripeCustomer::query()->sole()->default_payment_method_event_created_at);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'payment_method.default_changed')->count());
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

    /**
     * Recording which provider event last touched a payment is a write to the
     * row, and the one the webhook makes through the query builder - so it
     * never reaches the model's revision hook. A correction prepared against
     * the version read before the event must be refused like any other.
     */
    public function test_recording_a_provider_event_moves_the_payments_version(): void
    {
        [, $workspace, $company] = $this->tenant('event-version');
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-EVENT-VERSION', 'currency' => 'USD',
        ], [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $service->issue($invoice, $workspace);
        $success = $this->payload($invoice->public_id, $workspace->public_id);
        $this->webhookPost($success, $this->signature($success))->assertOk();
        $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->sole();
        $read = AgentApiVersion::for($payment);

        // A refund of nothing: the lifecycle writes nothing, the event is recorded.
        $refund = $this->eventPayload('evt_zero_refund', 'charge.refunded', [
            'id' => 'ch_zero_refund', 'payment_intent' => 'pi_synthetic_billing', 'amount_refunded' => 0, 'currency' => 'usd',
        ], time() + 60);
        $this->fakeStripeRefunds([]);
        $this->webhookPost($refund, $this->signature($refund))->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame('evt_zero_refund', $fresh?->provider_event_id);
        $this->assertNotSame($read, AgentApiVersion::for($fresh ?? $payment));
        $this->expectException(PaymentVersionChanged::class);
        $service->correctPayment($payment, ['reference' => 'Synthetic'], 'Synthetic stale correction', $read, $workspace);
    }

    /**
     * A succeeded Stripe payment of 1000 on its own issued invoice.
     *
     * @return array{0:User,1:Workspace,2:ClientInvoicePayment,3:ClientInvoice}
     */
    private function stripePayment(string $suffix): array
    {
        [$owner, $workspace, $company] = $this->tenant($suffix);
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-'.strtoupper($suffix), 'currency' => 'USD',
        ], [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $service->issue($invoice, $workspace);
        $success = $this->eventPayload('evt_success_'.$suffix, 'payment_intent.succeeded', [
            'id' => 'pi_synthetic_billing', 'amount' => 1000, 'amount_received' => 1000, 'currency' => 'usd',
            'metadata' => ['invoice_public_id' => $invoice->public_id, 'workspace_public_id' => $workspace->public_id],
        ], 50);
        $this->webhookPost($success, $this->signature($success))->assertOk();

        return [$owner, $workspace, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->sole(), $invoice];
    }

    /**
     * Answer Stripe's refund list for a charge with these refunds, and record
     * the parameters of each request in {@see self::$stripeRequests}. Anything
     * in `$whileReading` happens while Stripe is being read.
     *
     * @param  list<array<string, mixed>>  $refunds
     */
    private function fakeStripeRefunds(array $refunds, ?\Closure $whileReading = null): void
    {
        $this->stripeRefunds = $refunds;
        $this->fakeStripeLists($whileReading);
    }

    /** @param  list<array<string, mixed>>  $disputes */
    private function fakeStripeDisputes(array $disputes): void
    {
        $this->stripeDisputes = array_map(static fn (array $dispute): array => ['object' => 'dispute', 'balance_transactions' => [], ...$dispute], $disputes);
        $this->fakeStripeLists();
    }

    private function fakeStripeLists(?\Closure $whileReading = null): void
    {
        config(['services.stripe.secret_key' => 'sk_test_synthetic']);
        $answer = function (string $method, string $path, array $params) use ($whileReading): array {
            if ($method !== 'get' || ! in_array($path, ['/v1/refunds', '/v1/disputes'], true)) {
                return [['error' => ['message' => 'Unexpected synthetic Stripe request.']], 400];
            }
            if ($path === '/v1/refunds') {
                $this->stripeRequests[] = $params;
                if ($whileReading !== null) {
                    $whileReading();
                }
            }

            return [['object' => 'list', 'url' => $path, 'has_more' => false, 'data' => $path === '/v1/refunds' ? $this->stripeRefunds : $this->stripeDisputes], 200];
        };
        ApiRequestor::setHttpClient(new class($answer) implements ClientInterface
        {
            public function __construct(private readonly \Closure $answer) {}

            /**
             * @param  array<int|string, mixed>  $headers
             * @param  array<string, mixed>  $params
             * @return array{0: string, 1: int, 2: array<string, string>}
             */
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                [$body, $status] = ($this->answer)((string) $method, (string) parse_url($absUrl, PHP_URL_PATH), $params);

                return [json_encode($body, JSON_THROW_ON_ERROR), $status, []];
            }
        });
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
