<?php

namespace Tests\Feature\Finance;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\ProcessorSettlementRecorder;
use App\Services\Square\SquarePaymentReconciler;
use App\Services\Square\SquarePendingSettlements;
use App\Services\Square\SquareSettlementSync;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use App\Support\Finance\PaymentSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\FakesSquarePaymentSync;
use Tests\TestCase;

final class SquarePendingSettlementTest extends TestCase
{
    use FakesSquarePaymentSync;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $payout = [];

    private int $fee = 390;

    public function test_a_completed_payout_webhook_is_recovered_when_reconciliation_later_creates_its_payment(): void
    {
        [$workspace, , , $invoice] = $this->fixture();
        $this->deliver('evt_fixture_pending')->assertOk();
        $this->assertSame(1, DB::table('square_webhook_receipts')->whereNotNull('completed_at')->count());
        $this->assertSame(0, ClientInvoicePayment::query()->count());

        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');

        $payment = $invoice->payments()->sole();
        $this->assertSame(['po_fixture_pending', '2030-02-03', 390], [$payment->provider_payout_identifier, $payment->provider_payout_arrival_date?->toDateString(), $payment->provider_fee_amount]);
        $this->assertSame('2030-02-01T16:00:00+00:00', $payment->provider_charged_at?->toIso8601String());
        $this->assertSame(12_345, $payment->amount);
        $this->assertSame(0, DB::table('square_pending_settlements')->count());
        $revision = $payment->lock_version;
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $this->deliver('evt_fixture_pending')->assertOk();
        $this->assertSame(1, $invoice->payments()->count());
        $this->assertSame($revision, $payment->refresh()->lock_version);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_newer_waiting_payout_facts_win_before_the_payment_exists(): void
    {
        [$workspace, , , $invoice] = $this->fixture();
        $this->deliver('evt_fixture_original')->assertOk();
        $this->payout['arrival_date'] = '2030-02-05';
        $this->payout['updated_at'] = '2030-02-04T09:00:00Z';
        $this->fee = 395;
        $this->deliver('evt_fixture_newer')->assertOk();
        $this->payout['arrival_date'] = '2030-02-03';
        $this->payout['updated_at'] = '2030-02-02T09:00:00Z';
        $this->fee = 300;
        $this->deliver('evt_fixture_older')->assertOk();

        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');

        $payment = $invoice->payments()->sole();
        $this->assertSame(['po_fixture_pending', '2030-02-05', 395, 1_896_426_000_000], [$payment->provider_payout_identifier, $payment->provider_payout_arrival_date?->toDateString(), $payment->provider_fee_amount, $payment->provider_payout_reported_at_ms]);
    }

    public function test_a_dry_run_payout_does_not_leave_facts_for_later_reconciliation(): void
    {
        [$workspace, , , $invoice] = $this->fixture();
        app(SquareSettlementSync::class)->payout($workspace, 'po_fixture_pending', false);

        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');

        $payment = $invoice->payments()->sole();
        $this->assertNull($payment->provider_payout_identifier);
        $this->assertNull($payment->provider_payout_arrival_date);
        $this->assertSame(388, $payment->provider_fee_amount);
    }

    public function test_delivery_is_tenant_scoped_and_consumption_rolls_back_with_payment_changes(): void
    {
        [$workspace, , , $invoice] = $this->fixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $pending = app(SquarePendingSettlements::class);
        $facts = PaymentSettlement::payout('po_fixture_pending', '2030-02-03', 2000, null, 390, 'USD');
        $pending->remember($workspace, 'pay_fixture_1', $facts);
        $other = Workspace::query()->create(['name' => 'Synthetic other payout workspace', 'slug' => 'synthetic-other-payout']);
        $pending->remember($other, 'pay_fixture_1', $facts);
        $this->assertFalse($pending->deliver($other, 'pay_fixture_1'));
        $this->assertSame(2, DB::table('square_pending_settlements')->count());
        try {
            DB::transaction(function () use ($pending, $workspace): void {
                $this->assertTrue($pending->deliver($workspace, 'pay_fixture_1'));
                throw new RuntimeException('Synthetic rollback');
            });
            $this->fail('The synthetic rollback did not occur.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic rollback', $exception->getMessage());
        }
        $this->assertNull($invoice->payments()->sole()->provider_payout_identifier);
        $this->assertSame(2, DB::table('square_pending_settlements')->count());
        $this->assertTrue($pending->deliver($workspace, 'pay_fixture_1'));
        $this->assertSame([$other->id], DB::table('square_pending_settlements')->pluck('workspace_id')->all());
    }

    public function test_dry_delivery_and_an_older_pending_report_preserve_newer_payment_facts(): void
    {
        [$workspace, , , $invoice] = $this->fixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $pending = app(SquarePendingSettlements::class);
        $facts = PaymentSettlement::payout('po_fixture_pending', '2030-02-03', 2000, null, 390, 'USD');
        $pending->remember($workspace, 'pay_fixture_1', $facts);
        $this->assertTrue($pending->deliver($workspace, 'pay_fixture_1', false));
        $this->assertNull($invoice->payments()->sole()->provider_payout_identifier);
        $this->assertSame(1, DB::table('square_pending_settlements')->count());
        app(ProcessorSettlementRecorder::class)->recordPaymentSettlement($workspace, 'square', ['pay_fixture_1'],
            PaymentSettlement::payout('po_fixture_newest', '2030-02-06', 3000, null, 400, 'USD'));
        $before = $invoice->payments()->sole()->getRawOriginal();
        $this->assertFalse($pending->deliver($workspace, 'pay_fixture_1'));
        $this->assertSame($before, $invoice->payments()->sole()->getRawOriginal());
        $this->assertSame(0, DB::table('square_pending_settlements')->count());
    }

    public function test_pending_delivery_takes_payment_before_pending_in_the_existing_outer_transaction(): void
    {
        [$workspace, , , $invoice] = $this->fixture();
        $this->deliver('evt_fixture_order')->assertOk();
        $refetched = app(SquarePaymentReconciler::class)->refetch($workspace, 'inv_fixture_1');
        $this->assertNotNull($refetched);
        LockOrderRecorder::start();
        try {
            DB::transaction(fn () => app(SquarePaymentReconciler::class)->apply($workspace, $refetched));
            $sequences = LockOrderRecorder::sequences();
            $sequence = array_values(array_unique($sequences[0], SORT_REGULAR));
            $paymentAt = array_search(LockResource::ClientInvoicePayment, $sequence, true);
            $pendingAt = array_search(LockResource::SquarePendingSettlement, $sequence, true);
            $this->assertIsInt($paymentAt);
            $this->assertIsInt($pendingAt);
            $this->assertGreaterThan($paymentAt, $pendingAt);
            $this->assertSame('po_fixture_pending', $invoice->payments()->sole()->provider_payout_identifier);
        } finally {
            LockOrderRecorder::stop();
        }
    }

    /** @return array{Workspace, User, ClientCompany, ClientInvoice} */
    private function fixture(): array
    {
        $fixture = $this->paymentFixture();
        $this->payout = [
            'id' => 'po_fixture_pending', 'status' => 'PAID', 'location_id' => 'LOCFIXTURE01',
            'created_at' => '2030-02-02T08:00:00Z', 'updated_at' => '2030-02-02T09:00:00Z',
            'amount_money' => ['amount' => 11_955, 'currency' => 'USD'],
            'destination' => ['type' => 'BANK_ACCOUNT', 'id' => 'bank:fixture'],
            'version' => 2, 'type' => 'BATCH', 'arrival_date' => '2030-02-03',
        ];
        Http::swap(new Factory(app('events')));
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));

            return Http::response(match ($path) {
                '/v2/payouts/po_fixture_pending' => ['payout' => $this->payout],
                '/v2/payouts/po_fixture_pending/payout-entries' => ['payout_entries' => [[
                    'payout_id' => 'po_fixture_pending', 'type' => 'CHARGE',
                    'gross_amount_money' => ['amount' => 12_345, 'currency' => 'USD'],
                    'fee_amount_money' => ['amount' => $this->fee, 'currency' => 'USD'],
                    'type_charge_details' => ['payment_id' => 'pay_fixture_1'],
                ]]],
                '/v2/invoices/inv_fixture_1' => ['invoice' => $this->paymentInvoice],
                '/v2/orders/order_fixture_1' => ['order' => $this->paymentOrder],
                '/v2/payments/pay_fixture_1' => ['payment' => $this->squarePayments['pay_fixture_1']],
                default => [],
            });
        });

        return $fixture;
    }

    /** @return TestResponse<Response> */
    private function deliver(string $id): TestResponse
    {
        $raw = json_encode(['event_id' => $id, 'type' => 'payout.paid', 'data' => ['id' => 'po_fixture_pending']], JSON_THROW_ON_ERROR);
        $signature = base64_encode(hash_hmac('sha256', 'https://svc.example.test/api/webhooks/square'.$raw, 'fixture-signature-key', true));

        return $this->call('POST', '/api/webhooks/square', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature], content: $raw);
    }
}
