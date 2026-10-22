<?php

namespace Tests\Feature\Finance;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ProcessorMovement;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesSquare;
use Tests\Concerns\FakesStripeApi;
use Tests\TestCase;

final class SyncProcessorMovementsCommandTest extends TestCase
{
    use FakesSquare;
    use FakesStripeApi;
    use RefreshDatabase;

    private bool $withDispute = false;

    private ?string $failIdentifier = null;

    private ?string $deferIdentifier = null;

    /** @var list<string> */
    private array $payoutIds = ['po_synthetic_sync'];

    /** @var array<string, int> */
    private array $payoutFailures = [];

    private ?string $deferredPayout = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-02-01 12:00:00');
        config(['cache.default' => 'array', 'services.square.access_token' => null, 'services.stripe.secret_key' => null]);
        Cache::flush();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->restoreStripeApi();
        parent::tearDown();
    }

    public function test_preview_is_default_and_an_applied_explicit_payment_replays_without_duplicates(): void
    {
        [$workspace, $payment] = $this->fixture('one');
        $before = $payment->fresh()->getAttributes();
        $this->stripe();
        $options = ['--provider' => 'stripe', '--workspace' => $workspace->public_id, '--payment' => $payment->public_id];
        $preview = $this->runSync($options);
        $this->assertSame(0, $preview['exit']);
        $this->assertFalse($preview['applied']);
        $this->assertSame(1, $preview['providers']['stripe']['created']);
        $this->assertDatabaseCount('processor_movements', 0);
        $first = $this->runSync([...$options, '--apply' => true]);
        $this->assertTrue($first['completed']);
        $this->assertSame(1, $first['providers']['stripe']['created']);
        $second = $this->runSync([...$options, '--apply' => true]);
        $this->assertSame(1, $second['providers']['stripe']['unchanged']);
        $this->assertDatabaseCount('processor_movements', 1);
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $encoded = json_encode($first, JSON_THROW_ON_ERROR);
        foreach ([$workspace->public_id, $payment->public_id, $payment->provider_payment_identifier, 'acct_synthetic_sync', 're_pi_synthetic_one'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
    }

    public function test_applied_stripe_batches_rotate_and_reset_only_after_the_known_payment_end(): void
    {
        [$workspace, $first] = $this->fixture('one');
        [, $second] = $this->fixture('two', $workspace);
        $this->stripe();
        $options = ['--provider' => 'stripe', '--workspace' => $workspace->public_id, '--limit' => 1, '--apply' => true];
        $one = $this->runSync($options);
        $this->assertSame(1, $one['exit']);
        $this->assertFalse($one['completed']);
        $this->assertSame('record_limit', $one['providers']['stripe']['note']);
        $this->assertSame([$first->id], ProcessorMovement::query()->pluck('client_invoice_payment_id')->all());
        $two = $this->runSync($options);
        $this->assertSame(0, $two['exit']);
        $this->assertTrue($two['completed']);
        $this->assertSame([$first->id, $second->id], ProcessorMovement::query()->orderBy('id')->pluck('client_invoice_payment_id')->all());
        $three = $this->runSync($options);
        $this->assertSame(1, $three['providers']['stripe']['unchanged']);
        $read = array_values(array_filter($this->stripeCalls, static fn (array $call): bool => $call['path'] === '/v1/refunds'));
        $this->assertSame(['pi_synthetic_one', 'pi_synthetic_two', 'pi_synthetic_one'], array_column(array_column($read, 'params'), 'payment_intent'));
    }

    public function test_preview_does_not_advance_the_applied_rotation_cursor(): void
    {
        [$workspace, $first] = $this->fixture('one');
        $this->fixture('two', $workspace);
        $this->stripe();
        $options = ['--provider' => 'stripe', '--workspace' => $workspace->public_id, '--limit' => 1];
        $this->runSync($options);
        $this->runSync($options);
        $this->assertDatabaseCount('processor_movements', 0);
        $this->runSync([...$options, '--apply' => true]);
        $this->assertSame([$first->id], ProcessorMovement::query()->pluck('client_invoice_payment_id')->all());
    }

    #[DataProvider('stripeIncompleteKinds')]
    public function test_a_failed_or_deferred_payment_never_starves_later_payments_and_is_retried_next_cycle(string $kind): void
    {
        [$workspace] = $this->fixture('one');
        [, $later] = $this->fixture('two', $workspace);
        $this->stripe();
        $options = ['--provider' => 'stripe', '--workspace' => $workspace->public_id, '--apply' => true];
        $this->failIdentifier = $kind === 'failure' ? 'pi_synthetic_one' : null;
        $this->deferIdentifier = $kind === 'deferred' ? 'pi_synthetic_one' : null;
        $first = $this->runSync($options);
        $this->assertSame(1, $first['exit']);
        $this->assertFalse($first['completed']);
        $this->assertSame(2, $first['providers']['stripe']['records_read']);
        $this->assertSame(1, $first['providers']['stripe']['created']);
        $this->assertSame(1, $first['providers']['stripe'][$kind === 'failure' ? 'refused' : 'deferred']);
        $this->assertSame([$later->id], ProcessorMovement::query()->pluck('client_invoice_payment_id')->all());
        $again = $this->runSync($options);
        $this->assertSame(1, $again['exit']);
        $this->assertSame(1, $again['providers']['stripe'][$kind === 'failure' ? 'refused' : 'deferred']);
        $this->assertSame(1, $again['providers']['stripe']['unchanged']);
        $this->failIdentifier = $this->deferIdentifier = null;
        $recovered = $this->runSync($options);
        $this->assertSame(0, $recovered['exit']);
        $this->assertTrue($recovered['completed']);
        $this->assertDatabaseCount('processor_movements', 2);
    }

    public static function stripeIncompleteKinds(): iterable
    {
        yield 'refused provider record' => ['failure'];
        yield 'incomplete movement facts' => ['deferred'];
    }

    public function test_a_clean_last_batch_does_not_erase_an_earlier_incomplete_record_in_the_same_cycle(): void
    {
        [$workspace] = $this->fixture('one');
        [, $later] = $this->fixture('two', $workspace);
        $this->stripe();
        $this->deferIdentifier = 'pi_synthetic_one';
        $options = ['--provider' => 'stripe', '--workspace' => $workspace->public_id, '--limit' => 1, '--apply' => true];
        $this->assertSame(1, $this->runSync($options)['exit']);
        $last = $this->runSync($options);
        $this->assertSame(1, $last['providers']['stripe']['created']);
        $this->assertSame(1, $last['exit']);
        $this->assertFalse($last['completed']);
        $this->assertSame([$later->id], ProcessorMovement::query()->pluck('client_invoice_payment_id')->all());
        $this->assertSame(1, $this->runSync($options)['providers']['stripe']['deferred']);
    }

    public function test_a_record_finishing_exactly_at_the_call_limit_advances_before_the_next_actual_denial(): void
    {
        [$workspace, $first] = $this->fixture('one');
        [, $second] = $this->fixture('two', $workspace);
        $this->stripe();
        $options = ['--provider' => 'stripe', '--workspace' => $workspace->public_id, '--apply' => true];
        $this->runSync([...$options, '--max-calls' => 3]);
        $this->assertSame([$first->id], ProcessorMovement::query()->pluck('client_invoice_payment_id')->all());
        $resumed = $this->runSync($options);
        $this->assertSame(1, $resumed['providers']['stripe']['created']);
        $this->assertSame([$first->id, $second->id], ProcessorMovement::query()->orderBy('id')->pluck('client_invoice_payment_id')->all());
    }

    public function test_a_single_call_budget_refuses_without_dispute_or_movement_writes(): void
    {
        [$workspace, $payment] = $this->fixture('one');
        $this->stripe();
        $before = $payment->fresh()->getAttributes();
        $report = $this->runSync(['--provider' => 'stripe', '--workspace' => $workspace->public_id, '--max-calls' => 1, '--apply' => true]);
        $this->assertSame(1, $report['exit']);
        $this->assertFalse($report['completed']);
        $this->assertSame(1, $report['calls_used']);
        $this->assertCount(1, $this->stripeCalls);
        $this->assertDatabaseCount('processor_movements', 0);
        $this->assertSame($before, $payment->fresh()->getAttributes());
    }

    public function test_activation_recovers_a_missed_dispute_using_the_same_provider_snapshot(): void
    {
        [$workspace, $payment] = $this->fixture('recovery', issued: true);
        $this->withDispute = true;
        $this->stripe();
        $report = $this->runSync(['--provider' => 'stripe', '--workspace' => $workspace->public_id, '--payment' => $payment->public_id, '--apply' => true]);
        $this->assertSame(0, $report['exit']);
        $this->assertSame(2, $report['providers']['stripe']['created']);
        $this->assertSame('disputed', $payment->fresh()->status);
        $this->assertSame(100, $payment->fresh()->disputed_amount);
        $this->assertSame(1000, $payment->fresh()->amount);
        $this->assertSame(100, $payment->invoice->fresh()->balance_amount);
        $this->assertCount(1, array_filter($this->stripeCalls, static fn (array $call): bool => $call['path'] === '/v1/disputes'));
    }

    public function test_shared_budget_failure_retains_only_the_last_completed_stripe_position(): void
    {
        [$workspace, $first] = $this->fixture('one');
        [, $second] = $this->fixture('two', $workspace);
        $this->stripe();
        $options = ['--provider' => 'stripe', '--workspace' => $workspace->public_id, '--apply' => true];
        $stopped = $this->runSync([...$options, '--max-calls' => 3]);
        $this->assertSame(1, $stopped['exit']);
        $this->assertSame(1, $stopped['providers']['stripe']['records_read']);
        $this->assertSame(3, $stopped['calls_used']);
        $this->assertSame([$first->id], ProcessorMovement::query()->pluck('client_invoice_payment_id')->all());
        $resumed = $this->runSync($options);
        $this->assertSame(0, $resumed['exit']);
        $this->assertSame(1, $resumed['providers']['stripe']['records_read']);
        $this->assertSame([$first->id, $second->id], ProcessorMovement::query()->orderBy('id')->pluck('client_invoice_payment_id')->all());
    }

    public function test_an_explicit_payment_does_not_advance_the_scoped_or_global_rotation(): void
    {
        [$workspace, $first] = $this->fixture('one');
        [, $second] = $this->fixture('two', $workspace);
        $this->stripe();
        $this->runSync(['--provider' => 'stripe', '--workspace' => $workspace->public_id, '--payment' => $second->public_id, '--apply' => true]);
        $regular = $this->runSync(['--provider' => 'stripe', '--workspace' => $workspace->public_id, '--limit' => 1, '--apply' => true]);
        $this->assertSame(1, $regular['providers']['stripe']['created']);
        $this->assertSame([$second->id, $first->id], ProcessorMovement::query()->orderBy('id')->pluck('client_invoice_payment_id')->all());
        $global = $this->runSync(['--provider' => 'stripe', '--limit' => 1, '--apply' => true]);
        $this->assertSame(1, $global['providers']['stripe']['unchanged']);
        $read = array_values(array_filter($this->stripeCalls, static fn (array $call): bool => $call['path'] === '/v1/refunds'));
        $this->assertSame('pi_synthetic_one', $read[2]['params']['payment_intent']);
    }

    public function test_unconfigured_providers_are_explicitly_skipped_without_requests(): void
    {
        $report = $this->runSync();
        $this->assertSame(0, $report['exit']);
        $this->assertTrue($report['completed']);
        $this->assertSame('not_configured', $report['providers']['stripe']['note']);
        $this->assertSame('not_configured', $report['providers']['square']['note']);
        Http::assertNothingSent();
        $this->assertSame([], $this->stripeCalls);
    }

    public function test_square_gets_the_exact_time_range_and_default_preview_never_writes(): void
    {
        [$workspace] = $this->fixture('square', provider: 'square');
        $this->square($workspace);
        $options = ['--provider' => 'square', '--workspace' => $workspace->public_id, '--since' => '2029-12-01', '--until' => '2030-01-15'];
        $preview = $this->runSync($options);
        $this->assertSame(0, $preview['exit']);
        $this->assertSame(1, $preview['providers']['square']['created']);
        $this->assertDatabaseCount('processor_movements', 0);
        Http::assertSent(static fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/v2/payouts'
            && $request['begin_time'] === '2029-12-01T00:00:00Z' && $request['end_time'] === '2030-01-15T00:00:00Z');
        $first = $this->runSync([...$options, '--apply' => true]);
        $this->assertTrue($first['completed']);
        $this->assertSame(1, $this->runSync([...$options, '--apply' => true])['providers']['square']['unchanged']);
        $this->assertDatabaseCount('processor_movements', 1);
    }

    public function test_square_record_cap_is_incomplete_and_never_silently_runs_the_next_payout(): void
    {
        [$workspace] = $this->fixture('square', provider: 'square');
        $this->square($workspace);
        $this->payoutIds[] = 'po_synthetic_second';
        $report = $this->runSync(['--provider' => 'square', '--limit' => 1, '--apply' => true]);
        $this->assertSame(1, $report['exit']);
        $this->assertSame('record_limit', $report['providers']['square']['note']);
        $this->assertSame(1, $report['providers']['square']['records_read']);
        $this->assertDatabaseCount('processor_movements', 1);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/po_synthetic_second'));
    }

    public function test_limits_are_per_provider_so_a_completed_stripe_cap_does_not_skip_square(): void
    {
        [$workspace] = $this->fixture('one');
        $this->fixture('two', $workspace);
        $this->fixture('square', $workspace, 'square');
        $this->stripe();
        $this->square($workspace);
        $report = $this->runSync(['--limit' => 1, '--apply' => true]);
        $this->assertSame(1, $report['providers']['stripe']['records_read']);
        $this->assertSame(1, $report['providers']['square']['records_read']);
        $this->assertDatabaseCount('processor_movements', 2);
    }

    #[DataProvider('squareIncompleteKinds')]
    public function test_an_incomplete_square_payout_does_not_starve_later_payouts_and_retries_on_the_next_run(string $kind): void
    {
        [$workspace] = $this->fixture('square', provider: 'square');
        $this->payoutIds[] = 'po_synthetic_second';
        $this->square($workspace);
        if ($kind === 'refused') {
            $this->payoutFailures['po_synthetic_sync'] = 404;
        } else {
            $this->deferredPayout = 'po_synthetic_sync';
        }
        $options = ['--provider' => 'square', '--apply' => true];
        $first = $this->runSync($options);
        $this->assertSame(1, $first['exit']);
        $this->assertFalse($first['completed']);
        $this->assertSame(2, $first['providers']['square']['records_read']);
        $this->assertSame(1, $first['providers']['square']['deferred']);
        $this->assertSame(1, $first['providers']['square']['created']);
        $this->assertDatabaseCount('processor_movements', 1);
        $again = $this->runSync($options);
        $this->assertSame(1, $again['exit']);
        $this->assertSame(1, $again['providers']['square']['deferred']);
        $this->assertSame(1, $again['providers']['square']['unchanged']);
    }

    public static function squareIncompleteKinds(): iterable
    {
        yield 'record-specific refusal' => ['refused'];
        yield 'unbound facts' => ['deferred'];
    }

    public function test_square_infrastructure_failure_stops_without_running_later_payouts(): void
    {
        [$workspace] = $this->fixture('square', provider: 'square');
        $this->payoutIds[] = 'po_synthetic_second';
        $this->payoutFailures['po_synthetic_sync'] = 503;
        $this->square($workspace);
        $report = $this->runSync(['--provider' => 'square', '--apply' => true]);
        $this->assertSame(1, $report['exit']);
        $this->assertSame('sync_incomplete', $report['providers']['square']['note']);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/po_synthetic_second'));
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_unknown_or_foreign_explicit_payment_never_falls_back_to_a_global_scan(): void
    {
        [$workspace] = $this->fixture('one');
        [, $foreign] = $this->fixture('foreign');
        $this->stripe();
        foreach ([(string) Str::uuid(), $foreign->public_id] as $payment) {
            $report = $this->runSync(['--provider' => 'stripe', '--workspace' => $workspace->public_id, '--payment' => $payment, '--apply' => true]);
            $this->assertSame(2, $report['exit']);
            $this->assertSame('invalid_options', $report['reason']);
        }
        $this->assertSame([], $this->stripeCalls);
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_strict_option_bounds_and_payment_provider_are_refused_before_requests(): void
    {
        foreach ([['--limit' => 0], ['--limit' => 201], ['--max-calls' => 0], ['--max-calls' => 1001], ['--provider' => 'other'],
            ['--workspace' => 'synthetic-slug'], ['--payment' => (string) Str::uuid()], ['--provider' => 'square', '--payment' => (string) Str::uuid()],
            ['--since' => '2029-02-30'], ['--since' => '2030-01-10', '--until' => '2030-01-01']] as $options) {
            $report = $this->runSync($options);
            $this->assertSame(2, $report['exit']);
            $this->assertSame('invalid_options', $report['reason']);
        }
        Http::assertNothingSent();
    }

    public function test_an_existing_command_lock_refuses_an_overlapping_manual_run(): void
    {
        $lock = Cache::lock('finance:processor-movements:sync', 900);
        $this->assertTrue($lock->get());
        try {
            $report = $this->runSync(['--apply' => true]);
            $this->assertSame(1, $report['exit']);
            $this->assertFalse($report['completed']);
            $this->assertSame('already_running', $report['reason']);
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
    }

    public function test_the_two_daily_provider_schedules_are_staggered_and_overlap_guarded(): void
    {
        $events = array_values(array_filter(app(Schedule::class)->events(), static fn ($event): bool => str_contains($event->command ?? '', 'svc:finance:sync-processor-movements')));
        $this->assertCount(2, $events);
        $this->assertStringContainsString('--provider=stripe --apply', $events[0]->command);
        $this->assertStringContainsString('--provider=square --apply', $events[1]->command);
        $this->assertNotSame($events[0]->expression, $events[1]->expression);
        foreach ($events as $event) {
            $this->assertTrue($event->withoutOverlapping);
            $this->assertSame(30, $event->expiresAt);
        }
    }

    public function test_the_daily_provider_schedules_are_withheld_until_scheduled_apply_is_enabled(): void
    {
        $events = array_values(array_filter(app(Schedule::class)->events(), static fn ($event): bool => str_contains($event->command ?? '', 'svc:finance:sync-processor-movements')));
        $this->assertCount(2, $events);

        $this->assertFalse(config('svc.processor_movements.scheduled_apply'));
        foreach ($events as $event) {
            $this->assertFalse($event->filtersPass($this->app));
        }

        config(['svc.processor_movements.scheduled_apply' => true]);
        foreach ($events as $event) {
            $this->assertTrue($event->filtersPass($this->app));
        }
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function runSync(array $options = []): array
    {
        $exit = Artisan::call('svc:finance:sync-processor-movements', [...$options, '--format' => 'json']);
        $data = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        return [...$data, 'exit' => $exit];
    }

    /** @return array{Workspace, ClientInvoicePayment} */
    private function fixture(string $suffix, ?Workspace $workspace = null, string $provider = 'stripe', bool $issued = false): array
    {
        $workspace ??= Workspace::query()->create(['name' => 'Synthetic sync workspace', 'slug' => 'synthetic-sync-'.$suffix, 'timezone' => 'UTC']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic sync company', 'slug' => 'synthetic-sync-'.$suffix]);
        if ($issued) {
            $service = app(InvoiceLifecycleService::class);
            $invoice = $service->createDraft($workspace, $company, ['invoice_number' => 'SYN-SYNC-'.$suffix, 'currency' => 'USD'], [
                ['type' => 'service', 'description' => 'Synthetic sync service', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0],
            ]);
            $service->issue($invoice, $workspace);
            $payment = $service->applyPayment($invoice, ['amount' => 1000, 'currency' => 'USD', 'method' => 'stripe', 'status' => 'succeeded',
                'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_'.$suffix, 'received_on' => '2029-12-01'], $workspace);

            return [$workspace, $payment];
        }
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYN-SYNC-'.$suffix, 'currency' => 'USD']);
        $payment = ClientInvoicePayment::query()->create(['workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'provider' => $provider,
            'provider_payment_identifier' => ($provider === 'stripe' ? 'pi_' : 'payment_').'synthetic_'.$suffix, 'status' => 'succeeded', 'method' => $provider, 'amount' => 1000, 'currency' => 'USD']);

        return [$workspace, $payment];
    }

    private function stripe(): void
    {
        $this->fakeStripeApi(function (string $method, string $path, array $params): array {
            $this->assertSame('get', $method);
            if ($path === '/v1/account') {
                return [['id' => 'acct_synthetic_sync', 'object' => 'account']];
            }
            $id = $params['payment_intent'] ?? null;
            if ($id === $this->failIdentifier && $this->failIdentifier !== null) {
                return self::stripeError(400);
            }
            if ($path === '/v1/disputes') {
                $disputes = [];
                if ($this->withDispute && is_string($id)) {
                    $entry = ['id' => 'txn_dispute_'.$id, 'object' => 'balance_transaction', 'type' => 'adjustment', 'amount' => -100,
                        'fee' => 0, 'net' => -100, 'currency' => 'usd', 'created' => 1893457800, 'source' => 'du_'.$id];
                    $disputes[] = ['id' => 'du_'.$id, 'object' => 'dispute', 'payment_intent' => $id, 'charge' => 'ch_'.$id,
                        'status' => 'needs_response', 'amount' => 100, 'currency' => 'usd', 'balance_transactions' => [$entry]];
                }

                return [self::stripeList($path, $disputes)];
            }
            if ($path === '/v1/refunds' && is_string($id)) {
                $entry = ['id' => 'txn_'.$id, 'object' => 'balance_transaction', 'type' => 'refund', 'amount' => -100, 'fee' => 0, 'net' => -100,
                    'currency' => 'usd', 'created' => 1893457800, 'source' => 're_'.$id];
                $refund = ['id' => 're_'.$id, 'object' => 'refund', 'payment_intent' => $id, 'charge' => 'ch_'.$id, 'status' => 'succeeded', 'amount' => 100,
                    'currency' => 'usd', 'balance_transaction' => $id === $this->deferIdentifier ? null : $entry, 'failure_balance_transaction' => null];

                return [self::stripeList($path, [$refund])];
            }

            return self::stripeError(404);
        });
    }

    private function square(Workspace $workspace): void
    {
        $this->connectSquare($workspace, ['max_attempts' => 1]);
        Http::fake(function (Request $request) {
            $this->assertSame('GET', $request->method());
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v2/locations') {
                return Http::response(['locations' => [self::squareLocation()]]);
            }
            if ($path === '/v2/payouts') {
                return Http::response(['payouts' => array_map(static fn (string $id): array => ['id' => $id, 'location_id' => 'LOCFIXTURE01'], $this->payoutIds)]);
            }
            $id = explode('/', $path)[3] ?? '';
            if (in_array($id, $this->payoutIds, true)) {
                if (isset($this->payoutFailures[$id])) {
                    return Http::response(['errors' => [['category' => 'INVALID_REQUEST_ERROR', 'code' => 'NOT_FOUND']]], $this->payoutFailures[$id]);
                }
                if (str_ends_with($path, '/payout-entries')) {
                    return Http::response(['payout_entries' => [['id' => 'entry_'.$id, 'payout_id' => $id, 'type' => 'REFUND', 'effective_at' => '2030-01-02T12:00:00Z',
                        'gross_amount_money' => ['amount' => -100, 'currency' => 'USD'], 'fee_amount_money' => ['amount' => 0, 'currency' => 'USD'], 'net_amount_money' => ['amount' => -100, 'currency' => 'USD'],
                        'type_refund_details' => ['payment_id' => $id === $this->deferredPayout ? 'payment_unknown_synthetic' : 'payment_synthetic_square', 'refund_id' => 'refund_'.$id]]]]);
                }

                return Http::response(['payout' => ['id' => $id, 'location_id' => 'LOCFIXTURE01']]);
            }
            $this->fail('An unexpected Square request was issued.');
        });
    }
}
