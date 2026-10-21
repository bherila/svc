<?php

namespace Tests\Feature\Finance;

use App\Models\ClientCompany;
use App\Models\ClientInvoicePayment;
use App\Models\ProcessorMovement;
use App\Models\Workspace;
use App\Services\Finance\StripeProcessorMovementSync;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\StripeEventMustBeRetried;
use App\Support\Finance\ProcessorReadBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesStripeApi;
use Tests\TestCase;

final class StripeProcessorMovementSyncTest extends TestCase
{
    use FakesStripeApi;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $refunds = [];

    private bool $incomplete = false;

    /** @var list<array<string, mixed>> */
    private array $disputes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-01-20 12:00:00');
        $this->fakeStripeApi(function (string $method, string $path, array $params): array {
            $this->assertSame('get', $method);
            return match ($path) {
                '/v1/account' => [['id' => 'acct_synthetic_movements', 'object' => 'account']],
                '/v1/disputes' => [self::stripeList($path, $this->disputes)],
                '/v1/refunds' => [[...self::stripeList($path, isset($params['starting_after']) ? [] : $this->refunds), 'has_more' => $this->incomplete]],
                default => self::stripeError(404),
            };
        });
    }

    protected function tearDown(): void
    {
        $this->restoreStripeApi();
        parent::tearDown();
    }

    public function test_actual_debit_and_failed_refund_credit_are_recorded_once_without_changing_the_payment(): void
    {
        [$workspace, $payment] = $this->fixture();
        $this->refunds = [$this->refund()];
        $before = $payment->fresh()->getAttributes();
        $sync = app(StripeProcessorMovementSync::class);
        $preview = $sync->sync($workspace, $payment->id);
        $this->assertSame(2, $preview['created']);
        $this->assertDatabaseCount('processor_movements', 0);
        $first = $sync->sync($workspace, $payment->id, true);
        $this->assertSame(2, $first['created']);
        $this->assertSame(0, $first['deferred']);
        $this->assertSame([-100, 100], ProcessorMovement::query()->orderBy('id')->pluck('principal_minor')->all());
        $this->assertSame(['2029-12-31', '2030-01-02'], ProcessorMovement::query()->orderBy('id')->get()->map(fn ($row) => $row->occurred_on->toDateString())->all());
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $second = $sync->sync($workspace, $payment->id, true);
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['unchanged']);
        $this->assertDatabaseCount('processor_movements', 2);
    }

    public function test_a_successfully_decoded_incomplete_later_page_records_nothing(): void
    {
        [$workspace, $payment] = $this->fixture();
        $this->refunds = [$this->refund()];
        $this->incomplete = true;
        try {
            app(StripeProcessorMovementSync::class)->sync($workspace, $payment->id, true);
            $this->fail('Incomplete provider history must be refused.');
        } catch (StripeEventMustBeRetried $failure) {
            $this->assertSame('Stripe processor movements could not be read completely; retry the sync.', $failure->getMessage());
            $this->assertNull($failure->getPrevious());
        }
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_an_unpublished_balance_entry_is_reported_as_deferred_without_inventing_money(): void
    {
        [$workspace, $payment] = $this->fixture();
        $refund = $this->refund();
        $refund['balance_transaction'] = null;
        $refund['failure_balance_transaction'] = null;
        $refund['status'] = 'succeeded';
        $this->refunds = [$refund];
        $result = app(StripeProcessorMovementSync::class)->sync($workspace, $payment->id, true);
        $this->assertSame(1, $result['deferred']);
        $this->assertSame(0, $result['created']);
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_a_refund_for_another_payment_cannot_be_bound_by_the_callers_filter(): void
    {
        [$workspace, $payment] = $this->fixture();
        $refund = $this->refund();
        $refund['payment_intent'] = 'pi_synthetic_foreign';
        $this->refunds = [$refund];
        $this->expectException(StripeEventMustBeRetried::class);
        app(StripeProcessorMovementSync::class)->sync($workspace, $payment->id, true);
    }

    public function test_a_shared_call_budget_refuses_before_the_next_provider_request_without_writes(): void
    {
        [$workspace, $payment] = $this->fixture();
        $this->refunds = [$this->refund()];
        $budget = new ProcessorReadBudget(1);
        try {
            app(StripeProcessorMovementSync::class)->sync($workspace, $payment->id, true, budget: $budget);
            $this->fail('A spent shared provider budget must stop the group.');
        } catch (StripeEventMustBeRetried) {
            $this->assertCount(1, $this->stripeCalls);
            $this->assertSame(1, $budget->used());
            $this->assertDatabaseCount('processor_movements', 0);
        }
    }

    public function test_opted_in_recovery_and_movements_share_one_complete_dispute_read(): void
    {
        [$workspace, $payment] = $this->fixture();
        $entry = [...$this->refund()['balance_transaction'], 'id' => 'txn_synthetic_dispute', 'source' => 'du_synthetic_movements', 'type' => 'adjustment'];
        $this->disputes = [['id' => 'du_synthetic_movements', 'object' => 'dispute', 'payment_intent' => 'pi_synthetic_movements',
            'charge' => 'ch_synthetic_movements', 'status' => 'needs_response', 'amount' => 100, 'currency' => 'usd', 'balance_transactions' => [$entry]]];
        $result = app(StripeProcessorMovementSync::class)->sync($workspace, $payment->id, true, recoverDisputes: true);
        $this->assertSame(1, $result['created']);
        $this->assertSame('disputed', $payment->fresh()->status);
        $this->assertSame(100, $payment->fresh()->disputed_amount);
        $this->assertSame(1000, $payment->fresh()->amount);
        $this->assertSame(100, $payment->invoice->fresh()->balance_amount);
        $this->assertCount(1, array_filter($this->stripeCalls, static fn (array $call): bool => $call['path'] === '/v1/disputes'));
    }

    /** @return array{Workspace, ClientInvoicePayment} */
    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic movement workspace', 'slug' => 'synthetic-movement-workspace', 'timezone' => 'America/Los_Angeles']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic movement client', 'slug' => 'synthetic-movement-client']);
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, ['invoice_number' => 'SYN-MOVEMENT', 'currency' => 'USD'], [
            ['type' => 'service', 'description' => 'Synthetic movement service', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0],
        ]);
        $service->issue($invoice, $workspace);
        $payment = $service->applyPayment($invoice, ['amount' => 1000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'succeeded',
            'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_movements', 'received_on' => '2029-12-01'], $workspace);
        return [$workspace, $payment];
    }

    /** @return array<string, mixed> */
    private function refund(): array
    {
        $entry = ['id' => 'txn_synthetic_refund', 'object' => 'balance_transaction', 'type' => 'refund', 'amount' => -100,
            'fee' => 0, 'net' => -100, 'currency' => 'usd', 'created' => 1893457800, 'source' => 're_synthetic_movements'];
        $credit = [...$entry, 'id' => 'txn_synthetic_refund_failure', 'type' => 'refund_failure', 'amount' => 100, 'net' => 100, 'created' => 1893585600];
        return ['id' => 're_synthetic_movements', 'object' => 'refund', 'payment_intent' => 'pi_synthetic_movements',
            'charge' => 'ch_synthetic_movements', 'amount' => 100, 'currency' => 'usd', 'status' => 'failed',
            'balance_transaction' => $entry, 'failure_balance_transaction' => $credit];
    }
}
