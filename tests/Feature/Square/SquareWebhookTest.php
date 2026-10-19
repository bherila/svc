<?php

namespace Tests\Feature\Square;

use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoicePayment;
use App\Models\InvoiceSquareLink;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Finance\PaymentReconciliationService;
use App\Services\Square\SquareLinkService;
use App\Services\Square\SquarePaymentReconciler;
use App\Services\Square\SquareWebhookReceipts;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Finance\ReconciliationReviewReason;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesSquarePaymentSync;
use Tests\TestCase;

final class SquareWebhookTest extends TestCase
{
    use FakesSquarePaymentSync;
    use RefreshDatabase;

    public function test_opaque_invoice_hints_are_accepted_without_broadening_payment_hints(): void
    {
        $this->paymentFixture();
        // Unlinked hints are acknowledged without payment or mapping changes.
        foreach (['_synthetic_legacy_invoice', 'SyntheticLegacyInvoice=='] as $index => $id) {
            $this->deliver(['event_id' => 'evt_synthetic_opaque_'.$index, 'type' => 'invoice.updated', 'data' => ['id' => $id]])->assertOk();
            $this->deliver(['event_id' => 'evt_synthetic_payment_'.$index, 'type' => 'payment.updated', 'data' => ['id' => $id]])->assertBadRequest();
        }
        Http::assertNothingSent();
        $this->assertSame(0, ClientInvoicePayment::query()->count());
    }

    public function test_signature_is_required_before_parsing_or_work(): void
    {
        $this->paymentFixture();
        foreach ([null, 'fixture-signature-key'] as $key) {
            config(['services.square.webhook_signature_key' => $key]);
            $this->call('POST', '/api/webhooks/square', server: ['CONTENT_TYPE' => 'application/json'], content: '{bad-json')->assertForbidden();
        }
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('square_webhook_receipts')->count());
        $this->assertSame(0, ClientInvoicePayment::query()->count());
    }

    public function test_oversized_unsigned_payload_is_refused_before_signature_hashing_or_work(): void
    {
        $this->paymentFixture();
        $this->call('POST', '/api/webhooks/square', server: ['CONTENT_TYPE' => 'application/json'], content: str_repeat('x', 1_048_577))->assertStatus(413);
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('square_webhook_receipts')->count());
        $this->assertSame(0, ClientInvoicePayment::query()->count());
    }

    public function test_signed_invoice_hint_refetches_and_duplicate_event_does_no_work(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        Queue::fake();
        $event = ['event_id' => 'evt_fixture_1', 'type' => 'invoice.payment_made', 'data' => ['id' => 'inv_fixture_1', 'object' => ['invoice' => ['status' => 'UNPAID', 'amount' => 1]]]];
        $this->deliver($event)->assertOk();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(12_345, $invoice->payments()->firstOrFail()->amount);
        $this->assertSame(3, count(Http::recorded()));
        $this->deliver($event)->assertOk();
        $this->assertSame(3, count(Http::recorded()));
        $this->assertSame(1, DB::table('square_webhook_receipts')->where('workspace_id', $workspace->id)->whereNotNull('completed_at')->count());
        Queue::assertNothingPushed();
    }

    public function test_payment_and_refund_events_refetch_using_only_locator_hints(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $this->deliver(['event_id' => 'evt_fixture_payment', 'type' => 'payment.updated', 'data' => ['id' => 'pay_fixture_1']])->assertOk();
        $this->squarePayments['pay_fixture_1']['refunded_money'] = ['amount' => 345, 'currency' => 'USD'];
        $this->deliver(['event_id' => 'evt_fixture_refund', 'type' => 'refund.updated', 'data' => ['id' => 'refund_fixture_1', 'object' => ['refund' => ['payment_id' => 'pay_fixture_1', 'amount_money' => ['amount' => 12_345, 'currency' => 'USD']]]]])->assertOk();
        $this->assertSame(345, $invoice->fresh()->balance_amount);
        $this->assertSame(1, $invoice->payments()->count());
    }

    public function test_refund_after_a_full_reconciliation_is_recorded_and_flags_the_allocation(): void
    {
        [$workspace, $owner, , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $payment = $invoice->payments()->firstOrFail();
        $allocation = app(PaymentReconciliationService::class)->upsert($workspace, $payment, $owner, [
            'external_system_slug' => 'finance', 'external_transaction_uuid' => (string) Str::uuid(), 'allocated_amount' => 12_345, 'currency' => 'USD',
        ]);

        $this->squarePayments['pay_fixture_1']['refunded_money'] = ['amount' => 345, 'currency' => 'USD'];
        $this->deliver(['event_id' => 'evt_fixture_reconciled_refund', 'type' => 'refund.updated', 'data' => ['object' => ['refund' => ['payment_id' => 'pay_fixture_1']]]])->assertOk();

        $this->assertSame(345, $payment->fresh()->refunded_amount);
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertTrue($allocation->fresh()->is_active);
        $this->assertSame(12_345, $allocation->fresh()->allocated_amount);
        $this->assertSame(ReconciliationReviewReason::ProcessorRefund, $allocation->fresh()->review_reason);

        $this->squarePayments['pay_fixture_1']['refunded_money'] = ['amount' => 12_345, 'currency' => 'USD'];
        $this->deliver(['event_id' => 'evt_fixture_reconciled_full_refund', 'type' => 'refund.updated', 'data' => ['object' => ['refund' => ['payment_id' => 'pay_fixture_1']]]])->assertOk();
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame(12_345, $invoice->fresh()->balance_amount);
    }

    public function test_dispute_events_refetch_the_dispute_and_move_the_payment(): void
    {
        [$workspace, $owner, , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $payment = $invoice->payments()->firstOrFail();
        $allocation = app(PaymentReconciliationService::class)->upsert($workspace, $payment, $owner, [
            'external_system_slug' => 'finance', 'external_transaction_uuid' => (string) Str::uuid(), 'allocated_amount' => 12_345, 'currency' => 'USD',
        ]);

        // The notification's own state is ignored; the dispute is re-fetched.
        $this->squareDisputes['dispute_fixture_1'] = self::squareDispute('dispute_fixture_1', 'pay_fixture_1', 'EVIDENCE_REQUIRED', 5_000);
        $this->deliver(['event_id' => 'evt_fixture_dispute_created', 'type' => 'dispute.created', 'data' => ['type' => 'dispute', 'id' => 'dispute_fixture_1', 'object' => ['dispute' => ['state' => 'WON']]]])->assertOk();

        // Square holds only the 5,000 it contests; the rest still pays the invoice.
        $this->assertSame('disputed', $payment->fresh()->status);
        $this->assertSame(5_000, $payment->fresh()->disputed_amount);
        $this->assertSame(5_000, $invoice->fresh()->balance_amount);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertSame(ReconciliationReviewReason::ProcessorDispute, $allocation->fresh()->review_reason);
        $this->assertTrue($allocation->fresh()->is_active);

        // A partial win: the disputed amount, not the whole payment, is what was contested.
        $this->squareDisputes['dispute_fixture_1']['state'] = 'WON';
        $this->deliver(['event_id' => 'evt_fixture_dispute_won', 'type' => 'dispute.state.updated', 'data' => ['type' => 'dispute', 'id' => 'dispute_fixture_1']])->assertOk();

        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->disputed_amount);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, $invoice->payments()->count());
    }

    public function test_an_older_dispute_read_cannot_undo_a_newer_one(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $payment = $invoice->payments()->firstOrFail();
        $this->squareDisputes['dispute_fixture_7'] = [...self::squareDispute('dispute_fixture_7', 'pay_fixture_1', 'EVIDENCE_REQUIRED'), 'updated_at' => '2030-02-02T10:00:00.100Z'];
        $this->deliver(['event_id' => 'evt_fixture_stale_open', 'type' => 'dispute.created', 'data' => ['type' => 'dispute', 'id' => 'dispute_fixture_7']])->assertOk();
        $this->squareDisputes['dispute_fixture_7'] = [...self::squareDispute('dispute_fixture_7', 'pay_fixture_1', 'WON'), 'updated_at' => '2030-02-02T11:00:00.200Z'];
        $this->deliver(['event_id' => 'evt_fixture_stale_won', 'type' => 'dispute.state.updated', 'data' => ['type' => 'dispute', 'id' => 'dispute_fixture_7']])->assertOk();
        $this->assertSame('succeeded', $payment->fresh()->status);

        // A request that read the dispute before it was won reaches the
        // payment last; Square's own update time says it is older.
        $this->squareDisputes['dispute_fixture_7'] = [...self::squareDispute('dispute_fixture_7', 'pay_fixture_1', 'EVIDENCE_REQUIRED'), 'updated_at' => '2030-02-02T10:00:00.100Z'];
        $this->deliver(['event_id' => 'evt_fixture_stale_late', 'type' => 'dispute.state.updated', 'data' => ['type' => 'dispute', 'id' => 'dispute_fixture_7']])->assertOk();

        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, DB::table('square_webhook_receipts')->where('workspace_id', $workspace->id)->where('event_hash', hash('sha256', 'evt_fixture_stale_late'))->whereNotNull('completed_at')->count());
    }

    public function test_a_dispute_without_a_readable_update_time_is_released_for_retry(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $this->squareDisputes['dispute_fixture_8'] = [...self::squareDispute('dispute_fixture_8', 'pay_fixture_1', 'EVIDENCE_REQUIRED'), 'updated_at' => 'yesterday'];
        $this->deliver(['event_id' => 'evt_fixture_bad_time', 'type' => 'dispute.created', 'data' => ['type' => 'dispute', 'id' => 'dispute_fixture_8']])->assertStatus(503);
        $this->assertSame('succeeded', $invoice->payments()->firstOrFail()->status);
    }

    public function test_a_won_dispute_on_an_invoice_voided_meanwhile_is_recorded_without_reviving_it(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $payment = $invoice->payments()->firstOrFail();
        $this->squareDisputes['dispute_fixture_9'] = self::squareDispute('dispute_fixture_9', 'pay_fixture_1', 'EVIDENCE_REQUIRED');
        $this->deliver(['event_id' => 'evt_fixture_void_open', 'type' => 'dispute.created', 'data' => ['type' => 'dispute', 'id' => 'dispute_fixture_9']])->assertOk();
        app(InvoiceLifecycleService::class)->void($invoice->fresh(), $workspace, 'Synthetic void during dispute');
        $this->assertSame('void', $invoice->fresh()->status);

        $this->squareDisputes['dispute_fixture_9'] = [...$this->squareDisputes['dispute_fixture_9'], 'state' => 'WON', 'updated_at' => '2030-02-02T11:00:00Z'];
        $this->deliver(['event_id' => 'evt_fixture_void_won', 'type' => 'dispute.state.updated', 'data' => ['type' => 'dispute', 'id' => 'dispute_fixture_9']])->assertOk();

        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame('void', $invoice->fresh()->status);
        $received = ClientCompanyActivity::query()->where('action', 'invoice.payment_received')->where('subject_public_id', $payment->public_id)->latest('id')->firstOrFail();
        $this->assertSame('restored_on_void_invoice', $received->payload['review'] ?? null);
    }

    public function test_a_won_dispute_after_the_invoice_was_paid_again_is_restored_not_retried_forever(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $payment = $invoice->payments()->firstOrFail();
        $this->squareDisputes['dispute_fixture_6'] = self::squareDispute('dispute_fixture_6', 'pay_fixture_1', 'EVIDENCE_REQUIRED');
        $this->deliver(['event_id' => 'evt_fixture_repaid_dispute', 'type' => 'dispute.created', 'data' => ['type' => 'dispute', 'id' => 'dispute_fixture_6']])->assertOk();
        app(InvoiceLifecycleService::class)->applyPayment($invoice->fresh(), [
            'amount' => 12_345, 'currency' => 'USD', 'method' => 'wire', 'status' => 'succeeded', 'idempotency_key' => 'synthetic-repaid-square',
        ], $workspace);

        foreach (['WON', 'INQUIRY_CLOSED'] as $index => $state) {
            if ($index === 1) {
                $this->squareDisputes['dispute_fixture_6']['state'] = 'EVIDENCE_REQUIRED';
                $this->deliver(['event_id' => 'evt_fixture_repaid_reopened', 'type' => 'dispute.state.updated', 'data' => ['type' => 'dispute', 'id' => 'dispute_fixture_6']])->assertOk();
                $this->assertSame('disputed', $payment->fresh()->status);
            }
            $this->squareDisputes['dispute_fixture_6']['state'] = $state;
            $this->deliver(['event_id' => 'evt_fixture_repaid_'.$state, 'type' => 'dispute.state.updated', 'data' => ['type' => 'dispute', 'id' => 'dispute_fixture_6']])->assertOk();
            $this->assertSame('succeeded', $payment->fresh()->status, $state);
            $this->assertSame('paid', $invoice->fresh()->status, $state);
        }
        $received = ClientCompanyActivity::query()->where('action', 'invoice.payment_received')->where('subject_public_id', $payment->public_id)->latest('id')->firstOrFail();
        $this->assertSame(12_345, $received->payload['overpaid_amount'] ?? null);
    }

    public function test_a_lost_or_accepted_dispute_keeps_the_payment_disputed(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        foreach (['INQUIRY_EVIDENCE_REQUIRED', 'PROCESSING', 'LOST', 'ACCEPTED'] as $index => $state) {
            $this->squareDisputes['dispute_fixture_2'] = self::squareDispute('dispute_fixture_2', 'pay_fixture_1', $state);
            $this->deliver(['event_id' => 'evt_fixture_dispute_state_'.$index, 'type' => 'dispute.state.updated', 'data' => ['type' => 'dispute', 'id' => 'dispute_fixture_2']])->assertOk();
            $this->assertSame('disputed', $invoice->payments()->firstOrFail()->status, $state);
        }
    }

    public function test_a_closed_inquiry_restores_the_payment(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $this->squareDisputes['dispute_fixture_3'] = self::squareDispute('dispute_fixture_3', 'pay_fixture_1', 'INQUIRY_PROCESSING');
        $this->deliver(['event_id' => 'evt_fixture_inquiry_open', 'type' => 'dispute.created', 'data' => ['type' => 'dispute', 'id' => 'dispute_fixture_3']])->assertOk();
        $this->assertSame('disputed', $invoice->payments()->firstOrFail()->status);

        $this->squareDisputes['dispute_fixture_3']['state'] = 'INQUIRY_CLOSED';
        $this->deliver(['event_id' => 'evt_fixture_inquiry_closed', 'type' => 'dispute.state.updated', 'data' => ['type' => 'dispute', 'id' => 'dispute_fixture_3']])->assertOk();
        $this->assertSame('succeeded', $invoice->payments()->firstOrFail()->status);
    }

    public function test_a_dispute_on_an_unrecorded_payment_changes_nothing(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        $this->squareDisputes['dispute_fixture_4'] = self::squareDispute('dispute_fixture_4', 'pay_fixture_unknown', 'EVIDENCE_REQUIRED');
        $this->deliver(['event_id' => 'evt_fixture_unknown_dispute', 'type' => 'dispute.created', 'data' => ['type' => 'dispute', 'id' => 'dispute_fixture_4']])->assertOk();

        $this->assertSame(0, $invoice->payments()->count());
        $this->assertSame(1, DB::table('square_webhook_receipts')->where('workspace_id', $workspace->id)->whereNotNull('completed_at')->count());
    }

    public function test_an_unreadable_dispute_is_released_for_retry(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        $event = ['event_id' => 'evt_fixture_unreadable_dispute', 'type' => 'dispute.created', 'data' => ['type' => 'dispute', 'id' => 'dispute_fixture_5']];
        $cases = [
            'an unknown state' => ['state' => 'SOMETHING_NEW'],
            'another currency' => ['amount_money' => ['amount' => 100, 'currency' => 'EUR']],
            'more than the payment' => ['amount_money' => ['amount' => 12_346, 'currency' => 'USD']],
            'a different dispute' => ['id' => 'dispute_fixture_other'],
        ];
        foreach ($cases as $label => $override) {
            $this->squareDisputes['dispute_fixture_5'] = [...self::squareDispute('dispute_fixture_5', 'pay_fixture_1', 'EVIDENCE_REQUIRED'), ...$override];
            $this->deliver($event)->assertStatus(503);
            $this->assertSame('succeeded', $invoice->payments()->firstOrFail()->status, $label);
        }
        $this->deliver(['event_id' => 'evt_fixture_bad_dispute_hint', 'type' => 'dispute.created', 'data' => ['type' => 'dispute', 'id' => '../payments/pay_fixture_1']])->assertBadRequest();
    }

    public function test_failed_refetch_releases_the_event_for_retry(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        $event = ['event_id' => 'evt_fixture_retry', 'type' => 'invoice.updated', 'data' => ['id' => 'inv_fixture_1']];
        $this->squarePayments['pay_fixture_1']['currency'] = 'EUR';
        $this->squarePayments['pay_fixture_1']['amount_money']['currency'] = 'EUR';
        $this->deliver($event)->assertStatus(503);
        $this->assertSame(0, $invoice->payments()->count());
        $this->squarePayments['pay_fixture_1']['amount_money']['currency'] = 'USD';
        $this->deliver($event)->assertOk();
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_refund_hint_corrects_the_recorded_payment_after_unlinking(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->update(['active_invoice_slot' => null, 'active_square_invoice_slot' => null]);
        $this->squarePayments['pay_fixture_1']['refunded_money'] = ['amount' => 12_345, 'currency' => 'USD'];
        $this->deliver(['event_id' => 'evt_fixture_retired_refund', 'type' => 'refund.updated', 'data' => ['object' => ['refund' => ['payment_id' => 'pay_fixture_1']]]])->assertOk();
        $this->assertSame(12_345, $invoice->fresh()->balance_amount);
        $this->assertSame('refunded', $invoice->payments()->firstOrFail()->status);
    }

    public function test_refund_after_repointing_stays_on_the_original_recorded_payment(): void
    {
        [$workspace, $owner, , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        app(SquareLinkService::class)->link($workspace, $invoice->fresh(), $owner, AgentApiVersion::for($invoice->fresh()), new SquareInvoiceReference('inv_fixture_other', '000002-R-0001', 'USD', SquareMatchMethod::Manual), true);
        $this->squarePayments['pay_fixture_1']['refunded_money'] = ['amount' => 345, 'currency' => 'USD'];
        $this->deliver(['event_id' => 'evt_fixture_repointed_refund', 'type' => 'refund.updated', 'data' => ['object' => ['refund' => ['payment_id' => 'pay_fixture_1']]]])->assertOk();
        $this->assertSame(345, $invoice->fresh()->balance_amount);
        $this->assertSame('inv_fixture_other', InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->whereNotNull('active_invoice_slot')->firstOrFail()->square_invoice_id);
        $this->assertSame(1, $invoice->payments()->count());
    }

    public function test_historical_invoice_hints_recover_refunds_without_importing_new_payments(): void
    {
        [$workspace, , , $invoice] = $this->paymentFixture();
        app(SquarePaymentReconciler::class)->invoice($workspace, 'inv_fixture_1');
        InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->update(['active_invoice_slot' => null, 'active_square_invoice_slot' => null]);
        $this->squarePayments['pay_fixture_2'] = $this->squarePayments['pay_fixture_1'];
        $this->squarePayments['pay_fixture_2']['id'] = 'pay_fixture_2';
        $this->paymentOrder['tenders'][] = ['id' => 'pay_fixture_2'];
        $this->squarePayments['pay_fixture_1']['refunded_money'] = ['amount' => 12_345, 'currency' => 'USD'];
        $this->deliver(['event_id' => 'evt_fixture_historical_invoice', 'type' => 'invoice.updated', 'data' => ['id' => 'inv_fixture_1']])->assertOk();
        $this->assertSame(12_345, $invoice->fresh()->balance_amount);
        $this->assertSame(1, $invoice->payments()->count());
    }

    public function test_machine_route_has_no_session_authentication_or_csrf_middleware(): void
    {
        $this->paymentFixture();
        $route = app('router')->getRoutes()->getByName('svc.billing.square.webhook');
        $middleware = app('router')->gatherRouteMiddleware($route);
        foreach ($middleware as $item) {
            $this->assertStringNotContainsString('Authenticate', $item);
            $this->assertStringNotContainsString('Session', $item);
            $this->assertStringNotContainsString('Csrf', $item);
        }
        $this->deliver(['event_id' => 'evt_fixture_no_auth', 'type' => 'invoice.updated', 'data' => ['id' => 'inv_fixture_1']])->assertOk();
    }

    public function test_an_inflight_claim_requests_retry_and_an_expired_claim_can_be_recovered(): void
    {
        [$workspace] = $this->paymentFixture();
        $receipts = app(SquareWebhookReceipts::class);
        $claim = $receipts->claim($workspace, 'evt_fixture_lease', 'invoice.updated');
        $this->assertIsString($claim);
        $event = ['event_id' => 'evt_fixture_lease', 'type' => 'invoice.updated', 'data' => ['id' => 'inv_fixture_1']];
        $this->deliver($event)->assertStatus(409);
        Http::assertNothingSent();
        Date::setTestNow('2030-02-02 12:06:00');
        $this->deliver($event)->assertOk();
        // A slow predecessor cannot clear the successor's completed receipt.
        $receipts->finish($workspace, 'evt_fixture_lease', $claim, false);
        $this->assertSame(1, DB::table('square_webhook_receipts')->where('workspace_id', $workspace->id)->whereNotNull('completed_at')->count());
    }

    /** @param array<string, mixed> $event */
    private function deliver(array $event): TestResponse
    {
        $raw = json_encode($event, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $signature = base64_encode(hash_hmac('sha256', 'https://svc.example.test/api/webhooks/square'.$raw, 'fixture-signature-key', true));

        return $this->call('POST', '/api/webhooks/square', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature], content: $raw);
    }
}
