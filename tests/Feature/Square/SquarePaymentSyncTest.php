<?php

namespace Tests\Feature\Square;

use App\Models\ClientInvoicePayment;
use App\Models\Workspace;
use App\Services\Square\SquarePaymentReconciler;
use App\Services\Square\SquareRequestFailed;
use App\Support\Concurrency\LockOrderRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesSquarePaymentSync;
use Tests\TestCase;

final class SquarePaymentSyncTest extends TestCase
{
    use FakesSquarePaymentSync;
    use RefreshDatabase;

    public function test_sweep_records_payments_and_recovers_partial_and_full_refunds_without_webhooks(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        Queue::fake();
        config(['services.square.webhook_signature_key' => null]);
        $this->artisan('svc:square:reconcile-payments')->assertSuccessful();
        $this->assertSame('paid', $invoice->fresh()->status);
        $payment = $invoice->payments()->firstOrFail();
        $this->assertSame('square', $payment->provider);
        $this->assertSame('pay_fixture_1', $payment->provider_payment_identifier);
        $this->assertSame('2030-02-01', $payment->received_on->toDateString());
        $this->squarePayments['pay_fixture_1']['refunded_money'] = ['amount' => 345, 'currency' => 'USD'];
        $this->artisan('svc:square:reconcile-payments')->assertSuccessful();
        $this->assertSame(345, $invoice->fresh()->balance_amount);
        $this->squarePayments['pay_fixture_1']['refunded_money']['amount'] = 12_345;
        $this->artisan('svc:square:reconcile-payments')->assertSuccessful();
        $this->artisan('svc:square:reconcile-payments')->assertSuccessful();
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame(12_345, $invoice->fresh()->balance_amount);
        $this->assertSame(1, $invoice->payments()->count());
        Queue::assertNothingPushed();
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }

    public function test_partial_payments_leave_the_remaining_balance_and_later_complete_it(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        $this->squarePayments['pay_fixture_1']['amount_money']['amount'] = 6000;
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertSame(6345, $invoice->fresh()->balance_amount);
        $this->squarePayments['pay_fixture_2'] = $this->squarePayments['pay_fixture_1'];
        $this->squarePayments['pay_fixture_2']['id'] = 'pay_fixture_2';
        $this->squarePayments['pay_fixture_2']['amount_money']['amount'] = 6345;
        $this->paymentOrder['tenders'][] = ['id' => 'pay_fixture_2'];
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(2, $invoice->payments()->count());
    }

    public function test_a_slower_refund_observation_cannot_restore_money(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        $this->squarePayments['pay_fixture_1']['refunded_money'] = ['amount' => 6000, 'currency' => 'USD'];
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $this->squarePayments['pay_fixture_1']['refunded_money']['amount'] = 3000;
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $this->assertSame(6000, $invoice->fresh()->balance_amount);
    }

    public function test_an_unlinked_or_foreign_invoice_is_not_fetched_or_written(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_foreign');
        Http::assertNothingSent();
        $other = Workspace::query()->create(['name' => 'Synthetic other sync workspace', 'slug' => 'synthetic-other-sync-workspace']);
        try {
            app(SquarePaymentReconciler::class)->invoice($other, 'inv_fixture_1');
            $this->fail('A foreign workspace was reconciled.');
        } catch (SquareRequestFailed $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertSame(0, ClientInvoicePayment::query()->count());
    }

    public function test_money_transactions_follow_the_existing_lock_order(): void
    {
        [$workspace] = $this->paymentFixture();
        $this->squarePayments['pay_fixture_1']['refunded_money'] = ['amount' => 345, 'currency' => 'USD'];
        LockOrderRecorder::start();
        try {
            app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
            foreach (LockOrderRecorder::sequences() as $sequence) {
                $seen = [];
                $rank = -1;
                foreach ($sequence as $lock) {
                    if (! isset($seen[$lock->value])) {
                        $this->assertGreaterThanOrEqual($rank, $lock->rank());
                        $rank = $lock->rank();
                        $seen[$lock->value] = true;
                    }
                }
            }
        } finally {
            LockOrderRecorder::stop();
        }
    }
}
