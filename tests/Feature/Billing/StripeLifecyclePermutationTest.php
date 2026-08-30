<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientStripeCustomer;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AssertsEventOrderingInvariance;
use Tests\TestCase;

/**
 * PR #111's second review round was five ordering/tenant-edge defects in the
 * Stripe webhook lifecycle handlers, each found by a reviewer reading the
 * code and imagining an arrival order. This exhaustively enumerates every
 * arrival order instead - for every scenario below, all n! deliveries of its
 * events (n <= 6, so this stays fast), plus every adjacent and separated
 * duplicate-delivery variant - and asserts what StripeWebhookTest can only
 * assert one hand-picked order at a time:
 *
 *  - the ledger and payment/method state never land somewhere illegal;
 *  - an out-of-order or otherwise-refused delivery writes nothing to the
 *    domain tables (only its own ledger row records that it was seen);
 *  - redelivering an already-applied event, whether immediately after itself
 *    or separated by the rest of the sequence, is a no-op;
 *  - wherever StripeWebhookService's provider-timestamp bookkeeping claims
 *    the arrival order should not matter, every order actually agrees.
 *
 * Each scenario keeps its own workspace/company fixed and only resets the
 * mutable rows between orderings (see resetLedgerAndDomainState()), since
 * client_stripe_events.stripe_event_id and the payment/method provider ids
 * are globally unique and get reused, unchanged, run after run.
 */
class StripeLifecyclePermutationTest extends TestCase
{
    use AssertsEventOrderingInvariance;
    use RefreshDatabase;

    private const LEGAL_PAYMENT_STATUSES = ['pending', 'succeeded', 'failed', 'canceled', 'disputed', 'refunded'];

    private const LEGAL_METHOD_STATES = ['unknown', 'attached', 'detached'];

    private Workspace $workspace;

    private ClientCompany $company;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.webhook_secret' => 'whsec_synthetic']);
        if (! Route::has('svc.billing.stripe.webhook')) {
            require base_path('routes/billing.php');
        }
        $owner = User::factory()->create(['email' => 'stripe-permutation-owner@synthetic.test']);
        $this->workspace = Workspace::query()->create(['name' => 'Stripe Permutation', 'slug' => 'stripe-permutation']);
        $this->workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Stripe Permutation Client', 'slug' => 'stripe-permutation-client',
        ]);
    }

    /**
     * Three events for one payment: an initial "processing" delivery, a
     * later "failed" delivery, and a delivery that is malformed (its amount
     * does not match the pending payment) and so must be refused every time,
     * in every position, never merely because it happened to arrive after
     * something with a larger provider timestamp.
     *
     * The malformed event's own timestamp (300) is set higher than either
     * real event's (100, 200) specifically so it is never skipped as
     * "already superseded" before its amount is even checked - the
     * permutation test would otherwise miss most of its own orderings.
     */
    public function test_a_malformed_delivery_is_refused_in_every_position_while_the_valid_events_stay_order_independent(): void
    {
        $paymentId = 'pi_permutation_a';
        $processing = $this->paymentEvent('evt_perm_a_processing', 'payment_intent.processing', $paymentId, 100);
        $poison = $this->paymentEvent('evt_perm_a_poison', 'payment_intent.succeeded', $paymentId, 300, [
            'amount' => 999, 'amount_received' => 999,
        ]);
        $failed = $this->paymentEvent('evt_perm_a_failed', 'payment_intent.payment_failed', $paymentId, 200);

        $events = [
            ['label' => 'processing', 'ts' => 100, 'poison' => false, 'payload' => $processing],
            ['label' => 'poison', 'ts' => 300, 'poison' => true, 'payload' => $poison],
            ['label' => 'failed', 'ts' => 200, 'poison' => false, 'payload' => $failed],
        ];

        $signature = $this->assertEventOrderingInvariance(
            events: $events,
            reset: fn () => $this->resetPaymentScenario($paymentId, 'pending'),
            deliver: fn (array $event) => $this->deliver($event['payload']),
            assertLegalState: function () use ($paymentId): void {
                $this->assertContains($this->paymentStatus($paymentId), self::LEGAL_PAYMENT_STATUSES);
                $this->assertDatabaseHas('client_stripe_events', [
                    'stripe_event_id' => 'evt_perm_a_poison',
                    'status' => 'failed',
                ]);
            },
            fingerprintTables: ['client_invoice_payments', 'client_invoices', 'client_company_activity'],
            mustNotWrite: function (array $event, array $deliveredSoFar): bool {
                if ($event['poison']) {
                    return true;
                }
                $maxTs = $this->maxNonPoisonTs($deliveredSoFar);

                return $event['ts'] <= $maxTs;
            },
            stateSignature: fn () => $this->paymentSignature($paymentId),
        );

        // The malformed event never applies, in any order, so the two valid
        // events converge on whichever carries the larger timestamp (failed
        // at 200 beats processing at 100) - exactly as if the malformed one
        // had never been sent at all.
        $this->assertSame('failed|200', $signature);
    }

    /**
     * A cancellation is meant to be terminal: once observed, an earlier
     * "processing" or "failed" delivery for the same payment must never
     * resurrect it, regardless of the order the three arrive in.
     */
    public function test_a_cancellation_is_terminal_against_every_ordering_of_earlier_events(): void
    {
        $paymentId = 'pi_permutation_b';
        $events = [
            ['ts' => 100, 'payload' => $this->paymentEvent('evt_perm_b_processing', 'payment_intent.processing', $paymentId, 100)],
            ['ts' => 200, 'payload' => $this->paymentEvent('evt_perm_b_failed', 'payment_intent.payment_failed', $paymentId, 200)],
            ['ts' => 300, 'payload' => $this->paymentEvent('evt_perm_b_canceled', 'payment_intent.canceled', $paymentId, 300)],
        ];

        $signature = $this->assertEventOrderingInvariance(
            events: $events,
            reset: fn () => $this->resetPaymentScenario($paymentId, 'pending'),
            deliver: fn (array $event) => $this->deliver($event['payload']),
            assertLegalState: fn () => $this->assertContains($this->paymentStatus($paymentId), self::LEGAL_PAYMENT_STATUSES),
            fingerprintTables: ['client_invoice_payments', 'client_invoices', 'client_company_activity'],
            mustNotWrite: fn (array $event, array $deliveredSoFar): bool => $event['ts'] <= $this->maxTs($deliveredSoFar),
            stateSignature: fn () => $this->paymentSignature($paymentId),
        );

        $this->assertSame('canceled|300', $signature);
    }

    /**
     * A payment already succeeded outside the webhook flow (its provider
     * timestamp baseline is unset), then two progressively larger partial
     * refunds and a stale failure arrive in every order. `succeeded` blocks
     * `failed` outright (StripeWebhookService::isStalePaymentTransition), so
     * the failure must never apply no matter when it is delivered; the two
     * refunds must still converge on whichever carries the larger timestamp.
     */
    public function test_refund_progression_converges_and_a_stale_failure_never_applies(): void
    {
        $paymentId = 'pi_permutation_c';
        $chargeId = 'ch_permutation_c';
        $failed = ['ts' => 100, 'always_refused' => true, 'payload' => $this->paymentEvent('evt_perm_c_failed', 'payment_intent.payment_failed', $paymentId, 100)];
        $refund400 = ['ts' => 200, 'always_refused' => false, 'payload' => $this->refundEvent('evt_perm_c_refund_400', $chargeId, $paymentId, 400, 200)];
        $refund700 = ['ts' => 300, 'always_refused' => false, 'payload' => $this->refundEvent('evt_perm_c_refund_700', $chargeId, $paymentId, 700, 300)];

        $signature = $this->assertEventOrderingInvariance(
            events: [$failed, $refund400, $refund700],
            reset: fn () => $this->resetPaymentScenario($paymentId, 'succeeded'),
            deliver: fn (array $event) => $this->deliver($event['payload']),
            assertLegalState: fn () => $this->assertContains($this->paymentStatus($paymentId), self::LEGAL_PAYMENT_STATUSES),
            fingerprintTables: ['client_invoice_payments', 'client_invoices', 'client_company_activity'],
            mustNotWrite: function (array $event, array $deliveredSoFar): bool {
                if ($event['always_refused']) {
                    return true;
                }
                $refundsSoFar = array_filter($deliveredSoFar, fn (array $e): bool => ! $e['always_refused']);

                return $event['ts'] <= $this->maxTs(array_values($refundsSoFar));
            },
            stateSignature: fn () => $this->paymentStatus($paymentId).'|'.$this->refundedAmount($paymentId),
        );

        $this->assertSame('succeeded|700', $signature);
    }

    /**
     * A payment method is attached, then attached again with a smaller
     * provider timestamp (a stale/duplicate "attached" webhook), then
     * detached at the largest timestamp of the three. The routing ledger
     * (stripe_payment_method_states) is order-independent by design: its
     * state and provider_created_at converge on the same value regardless
     * of delivery order. The tenant-scoped client_stripe_payment_methods
     * row is deliberately NOT asserted order-independent here: a detach
     * delivered before any attach cannot resolve a tenant to act on (see
     * StripePaymentMethodService::detach), so whether a method row ever
     * gets created is a timing artifact of when the tenant became known,
     * not a claim the design makes - the migration that introduced this
     * router table documents exactly that trade-off.
     */
    public function test_payment_method_routing_state_converges_regardless_of_attach_detach_order(): void
    {
        $providerId = 'pm_permutation_d';
        $customerId = 'cus_permutation_d';
        $events = [
            ['ts' => 100, 'payload' => $this->attachEvent('evt_perm_d_attach_1', $providerId, $customerId, 100)],
            ['ts' => 200, 'payload' => $this->attachEvent('evt_perm_d_attach_2', $providerId, $customerId, 200)],
            ['ts' => 300, 'payload' => $this->detachEvent('evt_perm_d_detach', $providerId, 300)],
        ];

        $signature = $this->assertEventOrderingInvariance(
            events: $events,
            reset: fn () => $this->resetMethodScenario($providerId, $customerId),
            deliver: fn (array $event) => $this->deliver($event['payload']),
            assertLegalState: fn () => $this->assertContains($this->methodRoutingState($providerId), self::LEGAL_METHOD_STATES),
            fingerprintTables: ['stripe_payment_method_states', 'client_stripe_payment_methods', 'client_company_activity'],
            mustNotWrite: fn (array $event, array $deliveredSoFar): bool => $event['ts'] <= $this->maxTs($deliveredSoFar),
            stateSignature: fn () => $this->methodRoutingSignature($providerId),
        );

        $this->assertSame('detached|300', $signature);
    }

    /** @param list<array{ts: int}> $delivered */
    private function maxTs(array $delivered): int
    {
        $max = -1;
        foreach ($delivered as $event) {
            $max = max($max, $event['ts']);
        }

        return $max;
    }

    /** @param list<array{ts: int, poison: bool}> $delivered */
    private function maxNonPoisonTs(array $delivered): int
    {
        $max = -1;
        foreach ($delivered as $event) {
            if ($event['poison']) {
                continue;
            }
            $max = max($max, $event['ts']);
        }

        return $max;
    }

    /** @return TestResponse<Response> */
    private function deliver(string $payload): TestResponse
    {
        return $this->webhookPost($payload, $this->signature($payload));
    }

    /** @param array<string, mixed> $overrides */
    private function paymentEvent(string $eventId, string $type, string $paymentId, int $createdAt, array $overrides = []): string
    {
        $object = array_merge([
            'id' => $paymentId,
            'amount' => 1000,
            'amount_received' => 1000,
            'currency' => 'usd',
        ], $overrides);

        return json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'created' => $createdAt,
            'data' => ['object' => $object],
        ], JSON_THROW_ON_ERROR);
    }

    private function refundEvent(string $eventId, string $chargeId, string $paymentId, int $amountRefunded, int $createdAt): string
    {
        return json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'charge.refunded',
            'created' => $createdAt,
            'data' => ['object' => [
                'id' => $chargeId,
                'payment_intent' => $paymentId,
                'amount_refunded' => $amountRefunded,
                'currency' => 'usd',
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function attachEvent(string $eventId, string $providerId, string $customerId, int $createdAt): string
    {
        return json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_method.attached',
            'created' => $createdAt,
            'data' => ['object' => [
                'id' => $providerId,
                'customer' => $customerId,
                'type' => 'card',
                'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2032],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function detachEvent(string $eventId, string $providerId, int $createdAt): string
    {
        return json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_method.detached',
            'created' => $createdAt,
            'data' => ['object' => ['id' => $providerId]],
        ], JSON_THROW_ON_ERROR);
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

    /**
     * Clears every row the previous ordering left behind and creates one
     * fresh pending-or-succeeded payment on a fresh invoice, all under the
     * scenario's fixed workspace/company. Event ids and provider payment
     * identifiers are reused unchanged across orderings, so the ledger and
     * payment tables must be empty before each one starts.
     */
    private function resetPaymentScenario(string $paymentId, string $initialStatus): void
    {
        DB::table('client_company_activity')->delete();
        DB::table('client_stripe_events')->delete();
        DB::table('client_invoice_payments')->delete();
        DB::table('client_invoice_lines')->delete();
        DB::table('client_invoices')->delete();

        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($this->workspace, $this->company, [
            'invoice_number' => 'INV-PERMUTATION', 'currency' => 'USD',
        ], [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $service->issue($invoice, $this->workspace);
        $service->applyPayment($invoice, [
            'amount' => 1000, 'currency' => 'USD', 'method' => 'stripe', 'status' => $initialStatus,
            'provider' => 'stripe', 'provider_payment_identifier' => $paymentId,
        ], $this->workspace);
    }

    private function resetMethodScenario(string $providerId, string $customerId): void
    {
        DB::table('client_company_activity')->delete();
        DB::table('client_stripe_events')->delete();
        DB::table('stripe_payment_method_states')->delete();
        DB::table('client_stripe_payment_methods')->delete();
        DB::table('client_stripe_customers')->delete();

        ClientStripeCustomer::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'stripe_customer_id' => $customerId,
        ]);
    }

    private function paymentStatus(string $paymentId): string
    {
        return (string) DB::table('client_invoice_payments')
            ->where('provider_payment_identifier', $paymentId)
            ->value('status');
    }

    private function refundedAmount(string $paymentId): int
    {
        return (int) DB::table('client_invoice_payments')
            ->where('provider_payment_identifier', $paymentId)
            ->value('refunded_amount');
    }

    private function paymentSignature(string $paymentId): string
    {
        $row = DB::table('client_invoice_payments')->where('provider_payment_identifier', $paymentId)->first();
        if ($row === null) {
            return 'missing';
        }

        return $row->status.'|'.$row->provider_event_created_at;
    }

    private function methodRoutingState(string $providerId): string
    {
        $hash = hash('sha256', $providerId);

        return (string) (DB::table('stripe_payment_method_states')->where('provider_id_hash', $hash)->value('state') ?? 'unknown');
    }

    private function methodRoutingSignature(string $providerId): string
    {
        $hash = hash('sha256', $providerId);
        $row = DB::table('stripe_payment_method_states')->where('provider_id_hash', $hash)->first();
        if ($row === null) {
            return 'unknown|0';
        }

        return $row->state.'|'.$row->provider_created_at;
    }
}
