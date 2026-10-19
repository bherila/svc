<?php

namespace Tests\Feature\Finance;

use App\Models\ClientCompany;
use App\Models\ClientInvoicePayment;
use App\Models\ProcessorPayout;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Finance\ProcessorSettlementRecorder;
use App\Services\Square\SquarePaymentReconciler;
use App\Support\Finance\PayoutFacts;
use App\Support\Finance\PayoutStatus;
use App\Support\Finance\PayoutType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesSquare;
use Tests\TestCase;

/**
 * Square payouts and each payment's settlement (S7). A signed `payout.*`
 * webhook is only the hint of which payout to read: svc reads it and its
 * entries from the read-only Payouts API, stores it for the connected
 * workspace, and records on each charged payment its payout, arrival date and
 * fee. Reconciling a payment records its capture time and Square's fee so far.
 * Square is answered in-process; every id and amount is synthetic.
 */
final class SquarePayoutSyncTest extends TestCase
{
    use FakesSquare;
    use RefreshDatabase;

    /** @var array<string, array<string, mixed>> */
    private array $payouts = [];

    /** @var array<string, list<list<array<string, mixed>>>> payout id => pages of entries */
    private array $entries = [];

    /** @var array<string, array<string, mixed>> */
    private array $payments = [];

    public function test_a_signed_payout_hint_reads_the_payout_and_settles_its_payments(): void
    {
        [$workspace, $payment] = $this->fixture();
        $event = $this->hint('evt_fixture_payout_paid', 'payout.paid', 'po_fixture_1');

        $this->deliver($event)->assertOk();

        $payout = ProcessorPayout::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(['square', 'po_fixture_1', 11_957, 'USD', '2030-02-03', 'automatic', 'paid'],
            [$payout->provider, $payout->provider_payout_identifier, $payout->amount, $payout->currency, $payout->arrival_date?->toDateString(), $payout->type, $payout->status]);
        $this->assertSame(1_896_253_200_000, $payout->provider_updated_at_ms);
        $fresh = $payment->fresh();
        $this->assertSame(['po_fixture_1', '2030-02-03', 388], [$fresh?->provider_payout_identifier, $fresh?->provider_payout_arrival_date?->toDateString(), $fresh?->provider_fee_amount]);
        // The payout itself, then its entries: reads only.
        $this->assertSame(['GET /v2/payouts/po_fixture_1', 'GET /v2/payouts/po_fixture_1/payout-entries'], $this->sent());
        $this->assertSame(1, DB::table('square_webhook_receipts')->where('workspace_id', $workspace->id)->whereNotNull('completed_at')->count());

        // The same event again does no work.
        $this->deliver($event)->assertOk();
        $this->assertCount(2, Http::recorded());
        $this->assertSame($fresh?->lock_version, $payment->fresh()?->lock_version);
    }

    public function test_a_failure_is_read_again_and_moves_the_payout_and_a_stale_read_never_undoes_it(): void
    {
        [$workspace] = $this->fixture();
        $this->deliver($this->hint('evt_fixture_paid', 'payout.paid', 'po_fixture_1'))->assertOk();
        $paid = ProcessorPayout::query()->where('workspace_id', $workspace->id)->sole();

        $this->payouts['po_fixture_1'] = [...$this->payouts['po_fixture_1'], 'status' => 'FAILED', 'updated_at' => '2030-02-04T09:00:00Z'];
        $this->deliver($this->hint('evt_fixture_failed', 'payout.failed', 'po_fixture_1'))->assertOk();
        $this->assertSame([PayoutStatus::Failed->value, $paid->lock_version + 1], [$paid->fresh()?->status, $paid->fresh()?->lock_version]);

        // A read Square answered before the failure, arriving after it.
        $this->payouts['po_fixture_1'] = [...$this->payouts['po_fixture_1'], 'status' => 'SENT', 'updated_at' => '2030-02-02T08:30:00Z'];
        $this->deliver($this->hint('evt_fixture_sent', 'payout.sent', 'po_fixture_1'))->assertOk();
        $this->assertSame([PayoutStatus::Failed->value, $paid->lock_version + 1], [$paid->fresh()?->status, $paid->fresh()?->lock_version]);
    }

    public function test_a_payout_whose_entries_span_pages_settles_every_payment_and_a_simple_withdrawal_is_signed(): void
    {
        [$workspace, $payment, $company] = $this->fixture();
        $second = $this->squarePaymentRow($workspace, $company, 'pay_fixture_2');
        $this->payouts['po_fixture_2'] = [...$this->payout('po_fixture_2'), 'type' => 'SIMPLE', 'amount_money' => ['amount' => -2_500, 'currency' => 'USD']];
        $this->entries['po_fixture_2'] = [
            [$this->charge('po_fixture_2', 'pay_fixture_1', 300), ['payout_id' => 'po_fixture_2', 'type' => 'FEE', 'gross_amount_money' => ['amount' => -25, 'currency' => 'USD']]],
            // Square may leave the fee out; it is then gross less net.
            [[...$this->charge('po_fixture_2', 'pay_fixture_2', 0), 'fee_amount_money' => null, 'net_amount_money' => ['amount' => 11_900, 'currency' => 'USD']]],
        ];

        $this->deliver($this->hint('evt_fixture_simple', 'payout.sent', 'po_fixture_2'))->assertOk();

        $payout = ProcessorPayout::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame([-2_500, PayoutType::Instant->value, PayoutStatus::Paid->value], [$payout->amount, $payout->type, $payout->status]);
        $this->assertSame(300, $payment->fresh()?->provider_fee_amount);
        $this->assertSame([445, 'po_fixture_2'], [$second->fresh()?->provider_fee_amount, $second->fresh()?->provider_payout_identifier]);
        $this->assertSame('cursor-fixture-1', $this->lastQuery('cursor'));
    }

    public function test_an_unreadable_payout_is_retried_and_stores_nothing(): void
    {
        [$workspace, $payment] = $this->fixture();
        $this->payouts['po_fixture_1']['status'] = 'IN_LIMBO';

        $this->deliver($this->hint('evt_fixture_unreadable', 'payout.paid', 'po_fixture_1'))->assertStatus(503);

        $this->assertSame(0, ProcessorPayout::query()->count());
        $this->assertNull($payment->fresh()?->provider_payout_identifier);
        $this->assertSame(0, DB::table('square_webhook_receipts')->where('workspace_id', $workspace->id)->whereNotNull('completed_at')->count());

        // An unreadable entry stores nothing either: entries are read first.
        $this->payouts['po_fixture_1']['status'] = 'PAID';
        $this->entries['po_fixture_1'][0][] = ['payout_id' => 'po_fixture_1', 'type' => 'CHARGE', 'type_charge_details' => ['payment_id' => 'pay fixture']];
        $this->deliver($this->hint('evt_fixture_unreadable', 'payout.paid', 'po_fixture_1'))->assertStatus(503);
        $this->assertSame(0, ProcessorPayout::query()->count());
    }

    public function test_a_payout_hint_with_a_bad_signature_or_a_malformed_id_does_no_work(): void
    {
        $this->fixture();
        $raw = (string) json_encode($this->hint('evt_fixture_forged', 'payout.paid', 'po_fixture_1'));
        $this->call('POST', '/api/webhooks/square', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => 'forged'], content: $raw)->assertForbidden();
        $this->deliver($this->hint('evt_fixture_malformed', 'payout.paid', 'po fixture/1'))->assertBadRequest();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('square_webhook_receipts')->count());
        $this->assertSame(0, ProcessorPayout::query()->count());
    }

    public function test_reconciling_a_payment_records_its_capture_time_and_fee_without_overwriting_the_payouts(): void
    {
        [$workspace, $payment] = $this->fixture();
        $reconciler = app(SquarePaymentReconciler::class);

        $reconciler->payment($workspace, 'pay_fixture_1');
        $charged = $payment->fresh();
        $this->assertSame(['2030-02-01T16:00:00+00:00', 388, null], [$charged?->provider_charged_at?->toIso8601String(), $charged?->provider_fee_amount, $charged?->provider_payout_identifier]);
        $this->assertSame($payment->lock_version + 1, $charged?->lock_version);

        // Reading it again changes nothing.
        $reconciler->payment($workspace, 'pay_fixture_1');
        $this->assertSame($charged?->lock_version, $payment->fresh()?->lock_version);

        // The payout's own fee stands against a later charge-time figure.
        $this->entries['po_fixture_1'] = [[$this->charge('po_fixture_1', 'pay_fixture_1', 390)]];
        $this->deliver($this->hint('evt_fixture_fee', 'payout.paid', 'po_fixture_1'))->assertOk();
        $reconciler->payment($workspace, 'pay_fixture_1');
        $this->assertSame([390, 'po_fixture_1'], [$payment->fresh()?->provider_fee_amount, $payment->fresh()?->provider_payout_identifier]);
    }

    public function test_a_processing_fee_svc_cannot_read_leaves_the_fee_unknown(): void
    {
        [$workspace, $payment] = $this->fixture();
        $this->payments['pay_fixture_1']['processing_fee'] = [['amount_money' => ['amount' => 388, 'currency' => 'EUR']]];

        app(SquarePaymentReconciler::class)->payment($workspace, 'pay_fixture_1');

        $this->assertNull($payment->fresh()?->provider_fee_amount);
        $this->assertNotNull($payment->fresh()?->provider_charged_at);
    }

    public function test_a_payout_already_stored_with_a_newer_report_is_left_alone(): void
    {
        [$workspace] = $this->fixture();
        app(ProcessorSettlementRecorder::class)->recordPayout($workspace, new PayoutFacts('square', 'po_fixture_1', 11_957, 'USD', '2030-02-03', PayoutType::Automatic, PayoutStatus::Failed, 1_896_300_000_000));

        $this->deliver($this->hint('evt_fixture_older', 'payout.paid', 'po_fixture_1'))->assertOk();

        $this->assertSame([PayoutStatus::Failed->value, 1], [ProcessorPayout::query()->sole()->status, ProcessorPayout::query()->sole()->lock_version]);
    }

    /** @return array{0: Workspace, 1: ClientInvoicePayment, 2: ClientCompany} */
    private function fixture(): array
    {
        Date::setTestNow('2030-02-02 12:00:00');
        config(['app.url' => 'https://svc.example.test']);
        Http::preventStrayRequests();
        $workspace = Workspace::query()->create(['name' => 'Synthetic payout workspace', 'slug' => 'synthetic-payout-workspace']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic payout company', 'slug' => 'synthetic-payout-company']);
        $this->connectSquare($workspace, ['webhook_signature_key' => 'fixture-signature-key']);
        $payment = $this->squarePaymentRow($workspace, $company, 'pay_fixture_1');
        $this->payouts['po_fixture_1'] = $this->payout('po_fixture_1');
        $this->entries['po_fixture_1'] = [[
            $this->charge('po_fixture_1', 'pay_fixture_1', 388),
            ['id' => 'poe_fixture_fee', 'payout_id' => 'po_fixture_1', 'type' => 'FEE', 'gross_amount_money' => ['amount' => -10, 'currency' => 'USD']],
        ]];
        foreach (['pay_fixture_1', 'pay_fixture_2'] as $id) {
            $this->payments[$id] = [...self::squarePayment($id), 'card_details' => [...self::squarePayment($id)['card_details'], 'card_payment_timeline' => ['captured_at' => '2030-02-01T16:00:00Z']]];
        }
        Http::fake(function (Request $request) {
            $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));
            if (preg_match('~^/v2/payouts/([^/]+)/payout-entries$~', $path, $match) === 1) {
                $pages = $this->entries[$match[1]] ?? [[]];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = isset($query['cursor']) ? (int) substr((string) $query['cursor'], strlen('cursor-fixture-')) : 0;
                $next = $page + 1 < count($pages) ? 'cursor-fixture-'.($page + 1) : null;

                return Http::response(array_filter(['payout_entries' => $pages[$page], 'cursor' => $next], static fn (mixed $value): bool => $value !== null));
            }
            if (preg_match('~^/v2/payouts/([^/]+)$~', $path, $match) === 1) {
                return isset($this->payouts[$match[1]]) ? Http::response(['payout' => $this->payouts[$match[1]]]) : Http::response(['errors' => [['category' => 'INVALID_REQUEST_ERROR', 'code' => 'NOT_FOUND']]], 404);
            }
            if (preg_match('~^/v2/payments/([^/]+)$~', $path, $match) === 1) {
                return Http::response(['payment' => $this->payments[$match[1]] ?? null]);
            }

            return Http::response([], 404);
        });

        return [$workspace, $payment, $company];
    }

    private function squarePaymentRow(Workspace $workspace, ClientCompany $company, string $id): ClientInvoicePayment
    {
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, ['invoice_number' => 'SYN-'.$id, 'currency' => 'USD'],
            [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 12_345, 'tax_amount' => 0]]);
        $service->issue($invoice, $workspace);

        return $service->applyPayment($invoice, ['amount' => 12_345, 'currency' => 'USD', 'method' => 'square', 'status' => 'succeeded', 'provider' => 'square', 'provider_payment_identifier' => $id], $workspace);
    }

    /** @return array<string, mixed> */
    private function payout(string $id): array
    {
        return [
            'id' => $id, 'status' => 'PAID', 'location_id' => 'LOCFIXTURE01', 'created_at' => '2030-02-02T08:00:00Z', 'updated_at' => '2030-02-02T09:00:00Z',
            'amount_money' => ['amount' => 11_957, 'currency' => 'USD'], 'destination' => ['type' => 'BANK_ACCOUNT', 'id' => 'bact:fixture'],
            'version' => 2, 'type' => 'BATCH', 'arrival_date' => '2030-02-03',
        ];
    }

    /** @return array<string, mixed> */
    private function charge(string $payout, string $payment, int $fee): array
    {
        return [
            'id' => 'poe_'.$payment, 'payout_id' => $payout, 'effective_at' => '2030-02-01T16:00:00Z', 'type' => 'CHARGE',
            'gross_amount_money' => ['amount' => 12_345, 'currency' => 'USD'], 'fee_amount_money' => ['amount' => $fee, 'currency' => 'USD'],
            'net_amount_money' => ['amount' => 12_345 - $fee, 'currency' => 'USD'], 'type_charge_details' => ['payment_id' => $payment],
        ];
    }

    /** @return array<string, mixed> */
    private function hint(string $eventId, string $type, string $payoutId): array
    {
        // The payload's own payout fields are ignored: svc reads the payout.
        return ['event_id' => $eventId, 'type' => $type, 'data' => ['type' => 'payout', 'id' => $payoutId, 'object' => ['payout' => ['id' => $payoutId, 'status' => 'PAID', 'amount_money' => ['amount' => 1, 'currency' => 'USD']]]]];
    }

    /** @return list<string> */
    private function sent(): array
    {
        return array_map(static fn (array $pair): string => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH), Http::recorded()->all());
    }

    private function lastQuery(string $name): ?string
    {
        $last = Http::recorded()->last();
        parse_str((string) parse_url($last[0]->url(), PHP_URL_QUERY), $query);

        return isset($query[$name]) ? (string) $query[$name] : null;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return TestResponse<Response>
     */
    private function deliver(array $event): TestResponse
    {
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        $signature = base64_encode(hash_hmac('sha256', 'https://svc.example.test/api/webhooks/square'.$raw, 'fixture-signature-key', true));

        return $this->call('POST', '/api/webhooks/square', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature], content: $raw);
    }
}
