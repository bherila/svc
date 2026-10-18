<?php

namespace Tests\Feature\Square;

use App\Models\ClientInvoicePayment;
use App\Models\InvoiceSquareLink;
use App\Services\Square\SquareLinkService;
use App\Services\Square\SquarePaymentReconciler;
use App\Services\Square\SquareWebhookReceipts;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
