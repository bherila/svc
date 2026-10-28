<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesStripeApi;
use Tests\TestCase;

final class StripeAuthoritativeDisputeTest extends TestCase
{
    use FakesStripeApi;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $page = [];

    private ?\Closure $whileReading = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-01-20 12:00:00');
        config(['services.stripe.webhook_secret' => 'whsec_authoritative_dispute_synthetic']);
        if (! Route::has('svc.billing.stripe.webhook')) {
            require base_path('routes/billing.php');
        }
        $this->page = self::stripeList('/v1/disputes', []);
        $this->fakeStripeApi(function (string $method, string $path, array $params): array {
            $this->assertSame('get', $method);
            $this->assertSame('/v1/disputes', $path);
            if ($this->whileReading !== null) {
                $callback = $this->whileReading;
                $this->whileReading = null;
                $callback();
            }

            return [$this->page];
        });
    }

    protected function tearDown(): void
    {
        $this->restoreStripeApi();
        parent::tearDown();
    }

    public function test_a_distinct_won_dispute_does_not_release_the_current_open_dispute(): void
    {
        [$workspace, $invoice, $payment] = $this->payment();
        app(InvoiceLifecycleService::class)->setPaymentDisputed($payment, 100, $workspace);
        $open = self::dispute('du_synthetic_open', 'needs_response', 100);
        $won = self::dispute('du_synthetic_won', 'won', 200);
        $this->page = self::stripeList('/v1/disputes', [$open, $won]);
        $this->deliver('evt_synthetic_distinct_win', 'charge.dispute.closed', $won)->assertOk();

        $this->assertSame('disputed', $payment->fresh()->status);
        $this->assertSame(100, $payment->fresh()->disputed_amount);
        $this->assertSame(100, $invoice->fresh()->balance_amount);
        $this->assertSame('disputed', $invoice->fresh()->status);
        $this->assertCount(1, $this->stripeCalls);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_synthetic_distinct_win', 'status' => 'processed', 'workspace_id' => $workspace->id]);
        $this->deliver('evt_synthetic_distinct_win', 'charge.dispute.closed', $won)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertCount(1, $this->stripeCalls);
    }

    public function test_a_stale_event_reads_the_current_group_without_rewinding_event_metadata(): void
    {
        [$workspace, $invoice, $payment] = $this->payment();
        app(InvoiceLifecycleService::class)->setPaymentDisputed($payment, 100, $workspace);
        $payment->forceFill(['provider_event_created_at' => 300, 'provider_event_id' => 'evt_synthetic_latest'])->save();
        $won = self::dispute('du_synthetic_current', 'won', 100);
        $this->page = self::stripeList('/v1/disputes', [$won]);
        $this->deliver('evt_synthetic_stale_open', 'charge.dispute.created', [...$won, 'status' => 'needs_response'], 100)->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame('succeeded', $fresh->status);
        $this->assertNull($fresh->disputed_amount);
        $this->assertSame(0, $invoice->fresh()->balance_amount);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(300, $fresh->provider_event_created_at);
        $this->assertSame('evt_synthetic_latest', $fresh->provider_event_id);
    }

    public function test_a_payment_changed_during_the_read_is_retried_without_a_partial_business_write(): void
    {
        [$workspace, $invoice, $payment] = $this->payment();
        $this->page = self::stripeList('/v1/disputes', [self::dispute('du_synthetic_open', 'needs_response', 100)]);
        $afterConcurrentWrite = [];
        $this->whileReading = function () use ($workspace, $payment, &$afterConcurrentWrite): void {
            app(InvoiceLifecycleService::class)->setPaymentDisputed($payment, 700, $workspace);
            $afterConcurrentWrite = $this->businessRows();
        };
        $this->deliver('evt_synthetic_concurrent', 'charge.dispute.created', self::dispute('du_synthetic_open', 'needs_response', 100))->assertStatus(503);
        $this->assertSame($afterConcurrentWrite, $this->businessRows());
        $this->assertSame(700, $invoice->fresh()->balance_amount);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_synthetic_concurrent', 'status' => 'received', 'error_summary' => null]);
    }

    public function test_an_unfinished_provider_list_refuses_without_changing_the_payment(): void
    {
        [, , $payment] = $this->payment();
        $before = $this->businessRows();
        $this->page = [...self::stripeList('/v1/disputes', []), 'has_more' => true];
        $this->deliver('evt_synthetic_unfinished', 'charge.dispute.created', self::dispute('du_synthetic_open', 'needs_response', 100))->assertStatus(503);
        $this->assertSame($before, $this->businessRows());
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_synthetic_unfinished', 'status' => 'received']);
    }

    public function test_an_empty_group_does_not_invent_a_win_or_record_money_event_metadata(): void
    {
        [$workspace, , $payment] = $this->payment();
        app(InvoiceLifecycleService::class)->setPaymentDisputed($payment, 100, $workspace);
        $before = $this->businessRows();
        $this->deliver('evt_synthetic_empty', 'charge.dispute.closed', self::dispute('du_synthetic_open', 'won', 100))->assertOk();
        $this->assertSame($before, $this->businessRows());
    }

    #[DataProvider('disputeEvents')]
    public function test_every_dispute_notification_uses_the_complete_current_group(string $type): void
    {
        [, , $payment] = $this->payment();
        $current = self::dispute('du_synthetic_current', 'needs_response', 100);
        $this->page = self::stripeList('/v1/disputes', [$current]);
        $this->deliver('evt_synthetic_each_type', $type, [...$current, 'status' => 'won'])->assertOk();
        $this->assertSame('disputed', $payment->fresh()->status);
        $this->assertSame(100, $payment->fresh()->disputed_amount);
        $this->assertCount(1, $this->stripeCalls);
    }

    public static function disputeEvents(): iterable
    {
        foreach (['charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed', 'charge.dispute.funds_withdrawn', 'charge.dispute.funds_reinstated'] as $type) {
            yield $type => [$type];
        }
    }

    public function test_a_legacy_charge_payment_uses_its_charge_filter_including_expanded_references(): void
    {
        [, , $payment] = $this->payment();
        $payment->update(['provider_payment_identifier' => 'ch_synthetic_dispute']);
        $current = self::dispute('du_synthetic_current', 'needs_response', 100);
        $this->page = self::stripeList('/v1/disputes', [$current]);
        $this->deliver('evt_synthetic_legacy', 'charge.dispute.created', [...$current, 'charge' => ['id' => 'ch_synthetic_dispute', 'payment_intent' => ['id' => 'pi_synthetic_dispute']]])->assertOk();
        $this->assertSame(100, $payment->fresh()->disputed_amount);
        $this->assertSame('ch_synthetic_dispute', $this->stripeCalls[0]['params']['charge'] ?? null);
        $this->assertArrayNotHasKey('payment_intent', $this->stripeCalls[0]['params']);
    }

    public function test_an_unknown_payment_is_acknowledged_without_any_provider_request(): void
    {
        $this->deliver('evt_synthetic_unknown', 'charge.dispute.created', self::dispute('du_synthetic_current', 'needs_response', 100))->assertOk();
        $this->assertSame([], $this->stripeCalls);
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_synthetic_unknown', 'status' => 'processed', 'workspace_id' => null]);
    }

    /** @return array{Workspace, ClientInvoice, ClientInvoicePayment} */
    private function payment(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic authoritative dispute', 'slug' => 'synthetic-authoritative-dispute']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic dispute company', 'slug' => 'synthetic-dispute']);
        $invoices = app(InvoiceLifecycleService::class);
        $invoice = $invoices->createDraft($workspace, $company, ['invoice_number' => 'SYNTHETIC-DISPUTE', 'currency' => 'USD'], [
            ['type' => 'service', 'description' => 'Synthetic service', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0],
        ]);
        $invoices->issue($invoice, $workspace);
        $payment = $invoices->applyPayment($invoice, ['amount' => 1000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'succeeded',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_dispute', 'received_on' => '2030-01-10'], $workspace);

        return [$workspace, $invoice, $payment];
    }

    /** @return array<string, mixed> */
    private static function dispute(string $id, string $status, int $amount): array
    {
        return ['id' => $id, 'object' => 'dispute', 'payment_intent' => 'pi_synthetic_dispute', 'charge' => 'ch_synthetic_dispute', 'status' => $status, 'amount' => $amount, 'currency' => 'usd', 'balance_transactions' => []];
    }

    /** @return list<list<array<string, mixed>>> */
    private function businessRows(): array
    {
        $rows = [];
        foreach (['client_invoice_payments', 'client_invoices', 'client_company_activity', 'payment_reconciliations'] as $table) {
            $rows[] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $object
     * @return TestResponse<Response>
     */
    private function deliver(string $id, string $type, array $object, int $created = 200): TestResponse
    {
        $payload = json_encode(['id' => $id, 'object' => 'event', 'type' => $type, 'created' => $created, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_authoritative_dispute_synthetic');

        return $this->call('POST', '/api/webhooks/stripe', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], content: $payload);
    }
}
