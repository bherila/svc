<?php

namespace Tests\Feature\Square;

use App\Models\ClientInvoicePayment;
use App\Services\Square\SquarePaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /** @param array<string, mixed> $event */
    private function deliver(array $event): TestResponse
    {
        $raw = json_encode($event, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $signature = base64_encode(hash_hmac('sha256', 'https://svc.example.test/api/webhooks/square'.$raw, 'fixture-signature-key', true));

        return $this->call('POST', '/api/webhooks/square', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature], content: $raw);
    }
}
