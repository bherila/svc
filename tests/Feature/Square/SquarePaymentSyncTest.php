<?php

namespace Tests\Feature\Square;

use App\Models\ClientInvoicePayment;
use App\Models\InvoiceSquareLink;
use App\Models\Workspace;
use App\Services\Square\SquareLinkService;
use App\Services\Square\SquarePaymentReconciler;
use App\Services\Square\SquarePaymentRecorder;
use App\Services\Square\SquareRequestFailed;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use App\Support\Square\SquarePaymentFacts;
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
        $this->artisan('svc:square:reconcile-payments', ['--apply' => true])->assertSuccessful();
        $this->assertSame('paid', $invoice->fresh()->status);
        $payment = $invoice->payments()->firstOrFail();
        $this->assertSame('square', $payment->provider);
        $this->assertSame('pay_fixture_1', $payment->provider_payment_identifier);
        $this->assertSame('2030-02-01', $payment->received_on->toDateString());
        $this->squarePayments['pay_fixture_1']['refunded_money'] = ['amount' => 345, 'currency' => 'USD'];
        $this->artisan('svc:square:reconcile-payments', ['--apply' => true])->assertSuccessful();
        $this->assertSame(345, $invoice->fresh()->balance_amount);
        $this->squarePayments['pay_fixture_1']['refunded_money']['amount'] = 12_345;
        $this->artisan('svc:square:reconcile-payments', ['--apply' => true])->assertSuccessful();
        $this->artisan('svc:square:reconcile-payments', ['--apply' => true])->assertSuccessful();
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

    public function test_sweep_recovers_refunds_after_the_invoice_link_has_been_retired(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->update(['active_invoice_slot' => null, 'active_square_invoice_slot' => null]);
        $this->squarePayments['pay_fixture_1']['refunded_money'] = ['amount' => 345, 'currency' => 'USD'];
        $this->artisan('svc:square:reconcile-payments', ['--apply' => true])->assertSuccessful();
        $this->assertSame(345, $invoice->fresh()->balance_amount);
        $this->assertSame(1, $invoice->payments()->count());
    }

    public function test_existing_refunds_cannot_cross_workspace_boundaries(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $other = Workspace::query()->create(['name' => 'Synthetic foreign refund', 'slug' => 'synthetic-foreign-refund']);
        $this->squarePayments['pay_fixture_1']['refunded_money'] = ['amount' => 345, 'currency' => 'USD'];
        $facts = SquarePaymentFacts::from($this->squarePayments['pay_fixture_1'], 'pay_fixture_1');
        $this->assertFalse(app(SquarePaymentRecorder::class)->refundExisting($other, $facts));
        $this->assertSame([], iterator_to_array(app(SquarePaymentReconciler::class)->recordedPayments($other)));
        $this->assertSame(0, $invoice->fresh()->balance_amount);
    }

    public function test_sweep_stops_on_shared_adapter_failure_but_continues_for_a_missing_record(): void
    {
        [$workspace, $owner, , $invoice] = $this->paymentFixture();
        config(['services.square.max_attempts' => 1]);
        foreach (['second', 'third'] as $suffix) {
            $other = $invoice->replicate(['public_id']);
            $other->forceFill(['invoice_number' => 'SYNTHETIC-'.$suffix])->save();
            app(SquareLinkService::class)->link($workspace, $other, $owner, AgentApiVersion::for($other), new SquareInvoiceReference('inv_fixture_'.$suffix, '000002-R-0001', 'USD', SquareMatchMethod::Manual), false);
        }
        foreach ([401, 403, 429, 503] as $status) {
            $this->squareInvoiceFailureStatus = $status;
            $before = count(Http::recorded());
            $this->artisan('svc:square:reconcile-payments', ['--apply' => true])->assertFailed();
            $this->assertSame(1, count(Http::recorded()) - $before, 'The shared failure must stop further adapter calls.');
        }
        $this->squareInvoiceFailureStatus = 404;
        $before = count(Http::recorded());
        $this->artisan('svc:square:reconcile-payments', ['--apply' => true])->assertFailed();
        $this->assertSame(3, count(Http::recorded()) - $before);
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
