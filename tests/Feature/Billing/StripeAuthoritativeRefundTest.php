<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesStripeApi;
use Tests\TestCase;

final class StripeAuthoritativeRefundTest extends TestCase
{
    use FakesStripeApi;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $refunds = [];

    private ?\Closure $whileReading = null;

    private int $refundReadStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-01-20 12:00:00');
        config(['services.stripe.webhook_secret' => 'whsec_authoritative_synthetic']);
        if (! Route::has('svc.billing.stripe.webhook')) {
            require base_path('routes/billing.php');
        }
        $this->fakeStripeApi(function (string $method, string $path, array $params): array {
            $this->assertSame('get', $method);
            $this->assertSame('/v1/refunds', $path);
            $this->assertSame('ch_authoritative_fixture', $params['charge'] ?? null);
            $this->assertSame(100, $params['limit'] ?? null);
            if ($this->whileReading !== null) {
                $whileReading = $this->whileReading;
                $this->whileReading = null;
                $whileReading();
            }

            return $this->refundReadStatus === 200
                ? [self::stripeList('/v1/refunds', $this->refunds)]
                : self::stripeError($this->refundReadStatus);
        });
    }

    protected function tearDown(): void
    {
        $this->restoreStripeApi();
        parent::tearDown();
    }

    public function test_a_later_charge_refunded_does_not_restore_a_failed_refunds_stale_aggregate(): void
    {
        [, $payment, $invoice] = $this->payment();
        $this->refunds = [self::refund('re_failed_fixture', 400, 'pending')];
        $this->deliver('evt_first_refund_fixture', 'charge.refunded', self::charge(400), 100)->assertOk();
        $this->assertSame(400, $payment->fresh()->refunded_amount);

        $this->refunds = [self::refund('re_failed_fixture', 400, 'failed')];
        $this->deliver('evt_failed_refund_fixture', 'refund.failed', self::refund('re_failed_fixture', 400, 'failed'), 200)->assertOk();
        $this->assertSame(0, $payment->fresh()->refunded_amount);

        $this->refunds[] = self::refund('re_standing_fixture', 300, 'succeeded');
        $this->deliver('evt_later_refund_fixture', 'charge.refunded', self::charge(700), 300)->assertOk();
        $this->assertSame(300, $payment->fresh()->refunded_amount);
        $this->assertSame(300, $invoice->fresh()->balance_amount);
        $this->assertCount(3, $this->stripeCalls);
    }

    public function test_a_refund_list_read_before_a_payment_change_is_refused_and_retry_reads_afresh(): void
    {
        [$workspace, $payment, $invoice] = $this->payment();
        $this->refunds = [self::refund('re_older_fixture', 300, 'succeeded')];
        $this->whileReading = static function () use ($workspace, $payment): void {
            app(InvoiceLifecycleService::class)->setRefundedAmount($payment, 700, $workspace);
        };

        $this->deliver('evt_racing_refund_fixture', 'charge.refunded', self::charge(300), 300)->assertStatus(503);
        $this->assertSame(700, $payment->fresh()->refunded_amount);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_racing_refund_fixture', 'status' => 'received']);
        $this->refunds = [self::refund('re_current_fixture', 700, 'succeeded')];
        $this->deliver('evt_racing_refund_fixture', 'charge.refunded', self::charge(300), 300)->assertOk();
        $this->assertSame(700, $payment->fresh()->refunded_amount);
        $this->assertSame(700, $invoice->fresh()->balance_amount);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_racing_refund_fixture', 'status' => 'processed']);
    }

    public function test_an_unavailable_refund_list_is_retryable_and_writes_no_payment_correction(): void
    {
        [, $payment, $invoice] = $this->payment();
        $this->refundReadStatus = 503;
        $before = $payment->fresh()->getAttributes();
        $this->deliver('evt_unavailable_refund_fixture', 'charge.refunded', self::charge(400), 100)->assertStatus(503);
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertSame(0, $invoice->fresh()->balance_amount);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_unavailable_refund_fixture', 'status' => 'received']);
    }

    public function test_an_old_notification_uses_current_refunds_without_rewinding_the_latest_event(): void
    {
        [, $payment] = $this->payment();
        $this->refunds = [self::refund('re_first_fixture', 100, 'succeeded')];
        $this->deliver('evt_newer_refund_fixture', 'charge.refunded', self::charge(100), 500)->assertOk();
        $this->refunds = [self::refund('re_current_fixture', 300, 'succeeded')];
        $this->deliver('evt_older_refund_fixture', 'charge.refunded', self::charge(100), 100)->assertOk();
        $this->assertSame(300, $payment->fresh()->refunded_amount);
        $this->assertSame(500, $payment->fresh()->provider_event_created_at);
        $this->assertSame('evt_newer_refund_fixture', $payment->fresh()->provider_event_id);
    }

    public function test_every_refund_page_is_read_before_the_current_total_is_applied(): void
    {
        [, $payment, $invoice] = $this->payment();
        $this->fakeStripeApi(function (string $method, string $path, array $params): array {
            $this->assertSame('get', $method);
            $this->assertSame('/v1/refunds', $path);
            $this->assertSame('ch_authoritative_fixture', $params['charge'] ?? null);
            if (! isset($params['starting_after'])) {
                return [[...self::stripeList('/v1/refunds', [self::refund('re_failed_fixture', 400, 'failed')]), 'has_more' => true]];
            }
            $this->assertSame('re_failed_fixture', $params['starting_after']);

            return [self::stripeList('/v1/refunds', [self::refund('re_standing_fixture', 300, 'succeeded')])];
        });
        $this->deliver('evt_paged_refund_fixture', 'charge.refunded', self::charge(700), 100)->assertOk();
        $this->assertSame(300, $payment->fresh()->refunded_amount);
        $this->assertSame(300, $invoice->fresh()->balance_amount);
        $this->assertCount(2, $this->stripeCalls);
    }

    public function test_a_later_refund_page_failure_applies_none_of_the_partial_total(): void
    {
        [, $payment, $invoice] = $this->payment();
        $before = $payment->fresh()->getAttributes();
        $this->fakeStripeApi(function (string $method, string $path, array $params): array {
            $this->assertSame('get', $method);
            $this->assertSame('/v1/refunds', $path);
            if (! isset($params['starting_after'])) {
                return [[...self::stripeList('/v1/refunds', [self::refund('re_first_fixture', 300, 'succeeded')]), 'has_more' => true]];
            }

            return self::stripeError(503);
        });
        $this->deliver('evt_incomplete_refund_fixture', 'charge.refunded', self::charge(400), 100)->assertStatus(503);
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertSame(0, $invoice->fresh()->balance_amount);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_incomplete_refund_fixture', 'status' => 'received']);
    }

    /** @param list<array<string, mixed>> $pages */
    #[DataProvider('incompleteRefundPages')]
    public function test_an_incomplete_or_ambiguous_refund_snapshot_is_retryable_without_any_correction(array $pages): void
    {
        [$workspace, $payment, $invoice] = $this->payment();
        app(InvoiceLifecycleService::class)->setRefundedAmount($payment, 700, $workspace);
        $before = $payment->fresh()->getAttributes();
        $reads = 0;
        $this->fakeStripeApi(static function () use ($pages, &$reads): array {
            // A final empty page also bounds the unfixed SDK iterator in the cycle cases.
            return [$pages[$reads++] ?? self::stripeList('/v1/refunds', [])];
        });

        $this->deliver('evt_ambiguous_refund_fixture', 'charge.refunded', self::charge(700), 300)->assertStatus(503);

        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertSame(700, $invoice->fresh()->balance_amount);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_ambiguous_refund_fixture', 'status' => 'received']);
    }

    /** @return iterable<string, array{list<array<string, mixed>>}> */
    public static function incompleteRefundPages(): iterable
    {
        $first = self::refund('re_first_fixture', 100, 'succeeded');
        $second = self::refund('re_second_fixture', 100, 'succeeded');
        $firstPage = [...self::stripeList('/v1/refunds', [$first]), 'has_more' => true];
        yield 'empty nonterminal page' => [[$firstPage, [...self::stripeList('/v1/refunds', []), 'has_more' => true]]];
        yield 'duplicate refund id' => [[$firstPage, self::stripeList('/v1/refunds', [$first])]];
        yield 'cyclic last refund id' => [[$firstPage, [...self::stripeList('/v1/refunds', [$second]), 'has_more' => true], $firstPage]];
        yield 'oversized page' => [[self::stripeList('/v1/refunds', array_fill(0, 101, $first))]];
        $missingCompletion = self::stripeList('/v1/refunds', [$first]);
        unset($missingCompletion['has_more']);
        yield 'missing completion flag' => [[$missingCompletion]];
        yield 'nonboolean completion flag' => [[['object' => 'list', 'url' => '/v1/refunds', 'has_more' => 'false', 'data' => [$first]]]];
        yield 'negative refund amount' => [[self::stripeList('/v1/refunds', [[...$first, 'amount' => -100]])]];
        yield 'string refund amount' => [[self::stripeList('/v1/refunds', [[...$first, 'amount' => '100']])]];
        yield 'missing refund amount' => [[self::stripeList('/v1/refunds', [array_diff_key($first, ['amount' => true])])]];
        yield 'unrelated charge' => [[self::stripeList('/v1/refunds', [[...$first, 'charge' => 'ch_other_fixture']])]];
        yield 'unreadable refund status' => [[self::stripeList('/v1/refunds', [[...$first, 'status' => null]])]];
    }

    public function test_a_refund_snapshot_with_more_than_the_page_bound_is_retryable_without_a_partial_sum(): void
    {
        [$workspace, $payment, $invoice] = $this->payment();
        app(InvoiceLifecycleService::class)->setRefundedAmount($payment, 700, $workspace);
        $before = $payment->fresh()->getAttributes();
        $reads = 0;
        $this->fakeStripeApi(static function () use (&$reads): array {
            $reads++;

            return [$reads <= 100
                ? [...self::stripeList('/v1/refunds', [self::refund('re_page_fixture_'.$reads, 1, 'succeeded')]), 'has_more' => true]
                : self::stripeList('/v1/refunds', [])];
        });

        $this->deliver('evt_page_bound_refund_fixture', 'charge.refunded', self::charge(700), 300)->assertStatus(503);

        $this->assertSame(100, $reads);
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertSame(700, $invoice->fresh()->balance_amount);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_page_bound_refund_fixture', 'status' => 'received']);
    }

    public function test_the_last_permitted_refund_page_can_complete_the_snapshot(): void
    {
        [, $payment, $invoice] = $this->payment();
        $reads = 0;
        $this->fakeStripeApi(static function () use (&$reads): array {
            $reads++;

            return [[...self::stripeList('/v1/refunds', [self::refund('re_page_fixture_'.$reads, 1, 'succeeded')]), 'has_more' => $reads < 100]];
        });

        $this->deliver('evt_final_page_refund_fixture', 'charge.refunded', self::charge(100), 300)->assertOk();

        $this->assertSame(100, $reads);
        $this->assertSame(100, $payment->fresh()->refunded_amount);
        $this->assertSame(100, $invoice->fresh()->balance_amount);
    }

    public function test_an_unknown_string_refund_status_still_conservatively_withholds_its_amount(): void
    {
        [, $payment] = $this->payment();
        $this->refunds = [self::refund('re_new_status_fixture', 100, 'future_status')];

        $this->deliver('evt_new_status_refund_fixture', 'charge.refunded', self::charge(100), 300)->assertOk();

        $this->assertSame(100, $payment->fresh()->refunded_amount);
    }

    /** @return array{Workspace, ClientInvoicePayment, ClientInvoice} */
    private function payment(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic refund workspace', 'slug' => 'synthetic-refund-workspace']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic refund company', 'slug' => 'synthetic-refund-company']);
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, ['invoice_number' => 'SYNTHETIC-REFUND-001', 'currency' => 'USD'], [
            ['type' => 'service', 'description' => 'Synthetic service', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0],
        ]);
        $service->issue($invoice, $workspace);
        $payment = $service->applyPayment($invoice, ['amount' => 1000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'succeeded',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_authoritative_fixture', 'received_on' => '2030-01-10'], $workspace);

        return [$workspace, $payment, $invoice];
    }

    /** @return array<string, mixed> */
    private static function charge(int $aggregate): array
    {
        return ['id' => 'ch_authoritative_fixture', 'payment_intent' => 'pi_authoritative_fixture', 'amount_refunded' => $aggregate, 'currency' => 'usd'];
    }

    /** @return array<string, mixed> */
    private static function refund(string $id, int $amount, string $status): array
    {
        return ['id' => $id, 'object' => 'refund', 'charge' => 'ch_authoritative_fixture', 'payment_intent' => 'pi_authoritative_fixture', 'amount' => $amount, 'currency' => 'usd', 'status' => $status];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return TestResponse<Response>
     */
    private function deliver(string $id, string $type, array $object, int $created): TestResponse
    {
        $payload = json_encode(['id' => $id, 'object' => 'event', 'type' => $type, 'created' => $created, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_authoritative_synthetic');

        return $this->call('POST', '/api/webhooks/stripe', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], content: $payload);
    }
}
