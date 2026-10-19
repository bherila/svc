<?php

namespace Tests\Feature\Finance;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ProcessorPayout;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Finance\ProcessorSettlementRecorder;
use App\Support\Finance\PayoutFacts;
use App\Support\Finance\PayoutStatus;
use App\Support\Finance\PayoutType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesStripeApi;
use Tests\TestCase;

/**
 * Stripe payouts and each payment's settlement (S7): `payout.paid`,
 * `payout.failed` and `payout.canceled` are verified like every Stripe event,
 * recorded once per event id, and store the payout for each workspace whose
 * payments its balance transactions name; `payment_intent.succeeded` also
 * reads the charge's time and fee. Stripe is answered in-process.
 */
final class StripePayoutWebhookTest extends TestCase
{
    use FakesStripeApi;
    use RefreshDatabase;

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
        $this->restoreStripeApi();
        parent::tearDown();
    }

    public function test_a_paid_automatic_payout_is_stored_for_its_payments_workspace_and_settles_them(): void
    {
        [$workspace, $company] = $this->tenant('paid');
        $payment = $this->stripePayment($workspace, $company, 'pi_synthetic_paid');
        $this->fakeStripeApi(fn (string $method, string $path): array => $path === '/v1/balance_transactions'
            ? [self::stripeList($path, [
                self::stripeChargeEntry('ch_synthetic_paid', 'pi_synthetic_paid', 1000, 59),
                ['id' => 'txn_payout', 'object' => 'balance_transaction', 'type' => 'payout', 'amount' => -941, 'fee' => 0, 'currency' => 'usd', 'source' => 'po_synthetic_paid'],
            ])]
            : self::stripeError(404));
        $event = $this->event('evt_payout_paid', 'payout.paid', self::stripePayout('po_synthetic_paid', 941), 1000);

        $this->webhook($event)->assertOk()->assertJsonPath('duplicate', false);

        $payout = ProcessorPayout::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(['stripe', 'po_synthetic_paid', 941, 'USD', '2025-10-20', 'automatic', 'paid', 1_000_000, 1],
            [$payout->provider, $payout->provider_payout_identifier, $payout->amount, $payout->currency, $payout->arrival_date?->toDateString(), $payout->type, $payout->status, $payout->provider_updated_at_ms, $payout->lock_version]);
        $fresh = $payment->fresh();
        $this->assertSame('po_synthetic_paid', $fresh?->provider_payout_identifier);
        $this->assertSame('2025-10-20', $fresh?->provider_payout_arrival_date?->toDateString());
        $this->assertSame(59, $fresh?->provider_fee_amount);
        $this->assertSame('2025-10-09T08:53:20+00:00', $fresh?->provider_charged_at?->toIso8601String());
        $this->assertSame($payment->lock_version + 1, $fresh?->lock_version);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_payout_paid', 'status' => 'processed', 'workspace_id' => $workspace->id]);
        // The payout's balance transactions, with their sources expanded.
        $this->assertCount(1, $this->stripeCalls);
        $this->assertSame('po_synthetic_paid', $this->stripeCalls[0]['params']['payout']);
        $this->assertSame(['data.source'], $this->stripeCalls[0]['params']['expand']);

        // The same event again is a replay: nothing is read or written.
        $this->webhook($event)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertCount(1, $this->stripeCalls);
        $this->assertSame($fresh?->lock_version, $payment->fresh()?->lock_version);
        $this->assertSame(1, $payout->fresh()?->lock_version);
    }

    public function test_a_payout_reaches_each_workspace_it_names_and_writes_only_that_workspaces_payments(): void
    {
        [$first, $firstCompany] = $this->tenant('first');
        [$second, $secondCompany] = $this->tenant('second');
        [$refunded, $refundedCompany] = $this->tenant('refunded');
        [$unrelated] = $this->tenant('unrelated');
        $a = $this->stripePayment($first, $firstCompany, 'pi_synthetic_a');
        $b = $this->stripePayment($second, $secondCompany, 'pi_synthetic_b');
        $c = $this->stripePayment($refunded, $refundedCompany, 'pi_synthetic_c');
        $this->fakeStripeApi(fn (string $method, string $path): array => [self::stripeList($path, [
            self::stripeChargeEntry('ch_synthetic_a', 'pi_synthetic_a', 1000, 59),
            self::stripeChargeEntry('ch_synthetic_b', 'pi_synthetic_b', 1000, 61),
            // A refund of a payment settled in an earlier payout.
            self::stripeRefundEntry('re_synthetic_c', 'ch_synthetic_c', 'pi_synthetic_c', 300),
            // A charge svc never recorded.
            self::stripeChargeEntry('ch_synthetic_foreign', 'pi_synthetic_foreign', 500, 20),
        ])]);

        $this->webhook($this->event('evt_payout_shared', 'payout.paid', self::stripePayout('po_synthetic_shared', 2100), 1000))->assertOk();

        foreach ([$first, $second, $refunded] as $workspace) {
            $this->assertSame(2100, ProcessorPayout::query()->where('workspace_id', $workspace->id)->sole()->amount);
        }
        $this->assertSame(0, ProcessorPayout::query()->where('workspace_id', $unrelated->id)->count());
        $this->assertSame([59, 'po_synthetic_shared'], [$a->fresh()?->provider_fee_amount, $a->fresh()?->provider_payout_identifier]);
        $this->assertSame([61, 'po_synthetic_shared'], [$b->fresh()?->provider_fee_amount, $b->fresh()?->provider_payout_identifier]);
        // The refunded payment settled elsewhere: its own facts are untouched.
        $this->assertNull($c->fresh()?->provider_payout_identifier);
        $this->assertSame($c->lock_version, $c->fresh()?->lock_version);
        // Several workspaces: the event's ledger names none of them.
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_payout_shared', 'status' => 'processed', 'workspace_id' => null]);
    }

    public function test_a_failure_reported_after_payment_sticks_and_an_older_report_does_not_undo_it(): void
    {
        [$workspace, $company] = $this->tenant('failed');
        $this->stripePayment($workspace, $company, 'pi_synthetic_failed');
        $this->fakeStripeApi(fn (string $method, string $path): array => [self::stripeList($path, [self::stripeChargeEntry('ch_synthetic_failed', 'pi_synthetic_failed', 1000, 59)])]);

        $this->webhook($this->event('evt_failed_paid', 'payout.paid', self::stripePayout('po_synthetic_failed', 941), 1000))->assertOk();
        $paid = ProcessorPayout::query()->where('workspace_id', $workspace->id)->sole();
        $this->webhook($this->event('evt_failed_failed', 'payout.failed', self::stripePayout('po_synthetic_failed', 941, 'failed'), 2000))->assertOk();
        $failed = $paid->fresh();
        $this->assertSame([PayoutStatus::Failed->value, 2], [$failed?->status, $failed?->lock_version]);

        // A paid report sent before the failure, delivered after it.
        $this->webhook($this->event('evt_failed_late_paid', 'payout.paid', self::stripePayout('po_synthetic_failed', 941), 1500))->assertOk();
        $this->assertSame([PayoutStatus::Failed->value, 2], [$failed?->fresh()?->status, $failed?->fresh()?->lock_version]);

        // A cancellation of another payout is applied like a failure.
        $this->webhook($this->event('evt_canceled', 'payout.canceled', self::stripePayout('po_synthetic_canceled', 941, 'canceled'), 2500))->assertOk();
        $this->assertSame(PayoutStatus::Canceled->value, ProcessorPayout::query()->where('workspace_id', $workspace->id)->where('provider_payout_identifier', 'po_synthetic_canceled')->value('status'));
    }

    public function test_a_manual_payout_reads_no_members_and_reaches_only_a_row_already_stored(): void
    {
        [$workspace] = $this->tenant('manual');
        $this->fakeStripeApi(fn (): array => self::stripeError(400));

        $this->webhook($this->event('evt_manual', 'payout.paid', self::stripePayout('po_synthetic_manual', 5000, automatic: false), 1000))->assertOk();
        $this->assertSame(0, ProcessorPayout::query()->count());
        $this->assertSame([], $this->stripeCalls);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_manual', 'status' => 'processed', 'workspace_id' => null]);

        // An instant payout already stored for the workspace is brought up to date.
        app(ProcessorSettlementRecorder::class)->recordPayout($workspace, new PayoutFacts('stripe', 'po_synthetic_instant', 4000, 'USD', null, PayoutType::Instant, PayoutStatus::InTransit, 500));
        $this->webhook($this->event('evt_instant', 'payout.failed', self::stripePayout('po_synthetic_instant', 4000, 'failed', false, 'instant'), 1000))->assertOk();
        $stored = ProcessorPayout::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame([PayoutType::Instant->value, PayoutStatus::Failed->value, '2025-10-20'], [$stored->type, $stored->status, $stored->arrival_date?->toDateString()]);
        $this->assertSame([], $this->stripeCalls);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_instant', 'workspace_id' => $workspace->id]);
    }

    public function test_a_negative_payout_is_stored_signed_and_settles_no_payment(): void
    {
        [$workspace, $company] = $this->tenant('negative');
        $payment = $this->stripePayment($workspace, $company, 'pi_synthetic_negative');
        $this->fakeStripeApi(fn (string $method, string $path): array => [self::stripeList($path, [self::stripeRefundEntry('re_synthetic_negative', 'ch_synthetic_negative', 'pi_synthetic_negative', 500)])]);

        $this->webhook($this->event('evt_negative', 'payout.paid', self::stripePayout('po_synthetic_negative', -500), 1000))->assertOk();

        $this->assertSame(-500, ProcessorPayout::query()->where('workspace_id', $workspace->id)->sole()->amount);
        $this->assertNull($payment->fresh()?->provider_payout_identifier);
    }

    public function test_a_fee_in_another_currency_is_not_kept_as_the_payments_fee(): void
    {
        [$workspace, $company] = $this->tenant('currency');
        $payment = $this->stripePayment($workspace, $company, 'pi_synthetic_currency');
        $this->fakeStripeApi(fn (string $method, string $path): array => [self::stripeList($path, [self::stripeChargeEntry('ch_synthetic_currency', 'pi_synthetic_currency', 920, 40, currency: 'eur')])]);

        $this->webhook($this->event('evt_currency', 'payout.paid', [...self::stripePayout('po_synthetic_currency', 880), 'currency' => 'eur'], 1000))->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame('po_synthetic_currency', $fresh?->provider_payout_identifier);
        $this->assertNull($fresh?->provider_fee_amount);
        $this->assertSame('EUR', ProcessorPayout::query()->where('workspace_id', $workspace->id)->sole()->currency);
    }

    public function test_stripe_that_cannot_be_read_is_retried_and_nothing_is_written_until_it_can(): void
    {
        [$workspace, $company] = $this->tenant('retry');
        $payment = $this->stripePayment($workspace, $company, 'pi_synthetic_retry');
        $available = false;
        $this->fakeStripeApi(function (string $method, string $path) use (&$available): array {
            return $available ? [self::stripeList($path, [self::stripeChargeEntry('ch_synthetic_retry', 'pi_synthetic_retry', 1000, 59)])] : self::stripeError(500);
        });
        $event = $this->event('evt_retry', 'payout.paid', self::stripePayout('po_synthetic_retry', 941), 1000);

        $this->webhook($event)->assertStatus(503);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_retry', 'status' => 'received']);
        $this->assertSame(0, ProcessorPayout::query()->count());
        $this->assertNull($payment->fresh()?->provider_payout_identifier);

        // Stripe's redelivery of the same event is processed once Stripe answers.
        $available = true;
        $this->webhook($event)->assertOk()->assertJsonPath('duplicate', false);
        $this->assertSame('po_synthetic_retry', $payment->fresh()?->provider_payout_identifier);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_retry', 'status' => 'processed']);
    }

    public function test_an_unreadable_payout_is_recorded_as_a_failed_event(): void
    {
        $this->fakeStripeApi(fn (): array => self::stripeError(400));
        $event = $this->event('evt_unreadable', 'payout.paid', ['id' => 'po_synthetic_unreadable', 'object' => 'payout', 'currency' => 'usd', 'status' => 'paid'], 1000);

        $this->webhook($event)->assertStatus(409);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_unreadable', 'status' => 'failed', 'error_summary' => 'The Stripe payout has no readable amount.']);
        $this->assertSame(0, ProcessorPayout::query()->count());
    }

    public function test_a_payout_event_with_a_bad_signature_is_refused_before_anything_is_recorded(): void
    {
        $this->fakeStripeApi(fn (string $method, string $path): array => [self::stripeList($path, [])]);
        $event = $this->event('evt_forged', 'payout.paid', self::stripePayout('po_synthetic_forged', 941), 1000);

        $this->postWebhook($event, 't=1,v1=forged')->assertStatus(400);

        $this->assertDatabaseCount('client_stripe_events', 0);
        $this->assertSame(0, ProcessorPayout::query()->count());
        $this->assertSame([], $this->stripeCalls);
    }

    public function test_a_succeeded_payment_reads_its_charge_time_and_fee(): void
    {
        [$workspace, $company] = $this->tenant('charge');
        $invoice = $this->issuedInvoice($workspace, $company);
        $this->fakeStripeApi(fn (string $method, string $path): array => $path === '/v1/charges'
            ? [self::stripeList($path, [
                // An earlier attempt that failed is not the payment.
                ['id' => 'ch_synthetic_retry', 'object' => 'charge', 'status' => 'failed', 'created' => 1_760_000_100, 'balance_transaction' => null],
                ['id' => 'ch_synthetic_charge', 'object' => 'charge', 'status' => 'succeeded', 'created' => 1_760_000_000,
                    'balance_transaction' => ['id' => 'txn_synthetic_charge', 'object' => 'balance_transaction', 'fee' => 59, 'currency' => 'usd']],
            ])]
            : self::stripeError(404));

        $this->webhook($this->succeeded('evt_charge', 'pi_synthetic_charge', $invoice->public_id, $workspace->public_id))->assertOk();

        $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(59, $payment->provider_fee_amount);
        $this->assertSame('2025-10-09T08:53:20+00:00', $payment->provider_charged_at?->toIso8601String());
        $this->assertNull($payment->provider_payout_identifier);
        $this->assertSame([['method' => 'get', 'path' => '/v1/charges', 'params' => ['payment_intent' => 'pi_synthetic_charge', 'limit' => 10, 'expand' => ['data.balance_transaction']]]], $this->stripeCalls);
    }

    public function test_a_charge_that_cannot_be_read_leaves_the_payment_recorded(): void
    {
        Log::spy();
        [$workspace, $company] = $this->tenant('charge-down');
        $invoice = $this->issuedInvoice($workspace, $company);
        $this->fakeStripeApi(fn (): array => self::stripeError(500));

        $this->webhook($this->succeeded('evt_charge_down', 'pi_synthetic_charge_down', $invoice->public_id, $workspace->public_id))->assertOk()->assertJsonPath('duplicate', false);

        $payment = ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame('succeeded', $payment->status);
        $this->assertNull($payment->provider_charged_at);
        $this->assertSame('paid', $invoice->fresh()?->status);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Stripe charge settlement') && $context['payment'] === $payment->public_id)->once();
    }

    public function test_without_a_secret_key_no_charge_is_read(): void
    {
        [$workspace, $company] = $this->tenant('unconfigured');
        $invoice = $this->issuedInvoice($workspace, $company);
        $this->fakeStripeApi(fn (): array => self::stripeError(500));
        config(['services.stripe.secret_key' => null]);

        $this->webhook($this->succeeded('evt_unconfigured', 'pi_synthetic_unconfigured', $invoice->public_id, $workspace->public_id))->assertOk();

        $this->assertSame([], $this->stripeCalls);
        $this->assertNull(ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->sole()->provider_charged_at);
    }

    /** @return array{0: Workspace, 1: ClientCompany} */
    private function tenant(string $suffix): array
    {
        $owner = User::factory()->create(['email' => 'payout-owner-'.$suffix.'@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Payout Workspace '.$suffix, 'slug' => 'payout-workspace-'.$suffix]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Payout Client '.$suffix, 'slug' => 'payout-client-'.$suffix]);

        return [$workspace, $company];
    }

    private function issuedInvoice(Workspace $workspace, ClientCompany $company): ClientInvoice
    {
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, ['invoice_number' => 'INV-'.Str::upper(Str::random(8)), 'currency' => 'USD'],
            [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $service->issue($invoice, $workspace);

        return $invoice;
    }

    private function stripePayment(Workspace $workspace, ClientCompany $company, string $intent): ClientInvoicePayment
    {
        return app(InvoiceLifecycleService::class)->applyPayment($this->issuedInvoice($workspace, $company), [
            'amount' => 1000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'succeeded',
            'provider' => 'stripe', 'provider_payment_identifier' => $intent,
        ], $workspace);
    }

    private function succeeded(string $eventId, string $intent, string $invoicePublicId, string $workspacePublicId): string
    {
        return $this->event($eventId, 'payment_intent.succeeded', [
            'id' => $intent, 'object' => 'payment_intent', 'amount' => 1000, 'amount_received' => 1000, 'currency' => 'usd',
            'metadata' => ['invoice_public_id' => $invoicePublicId, 'workspace_public_id' => $workspacePublicId],
        ], 100);
    }

    /** @param array<string, mixed> $object */
    private function event(string $id, string $type, array $object, int $created): string
    {
        return json_encode(['id' => $id, 'object' => 'event', 'type' => $type, 'created' => $created, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
    }

    /** @return TestResponse<Response> */
    private function webhook(string $payload): TestResponse
    {
        $timestamp = time();

        return $this->postWebhook($payload, 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_synthetic'));
    }

    /** @return TestResponse<Response> */
    private function postWebhook(string $payload, string $signature): TestResponse
    {
        return $this->call('POST', '/api/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload);
    }
}
