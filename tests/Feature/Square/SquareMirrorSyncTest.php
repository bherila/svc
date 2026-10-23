<?php

namespace Tests\Feature\Square;

use App\Models\SquareCustomerSnapshot;
use App\Models\SquareInvoiceSnapshot;
use App\Models\SquareLocationSnapshot;
use App\Models\SquareSyncRun;
use App\Models\Workspace;
use App\Services\Square\Mirror\SquareMirrorReadService;
use App\Services\Square\Mirror\SquareMirrorRepository;
use App\Services\Square\Mirror\SquareMirrorSync;
use App\Services\Square\Mirror\SquareSnapshotMapper;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\CapturesTenantOwnedWrites;
use Tests\Concerns\FakesSquare;
use Tests\Concerns\ServesSyntheticSquareAccount;
use Tests\TestCase;

/**
 * The read-only Square mirror sync (#438), against Http::fake and synthetic
 * records only.
 */
final class SquareMirrorSyncTest extends TestCase
{
    use CapturesTenantOwnedWrites;
    use FakesSquare;
    use RefreshDatabase;
    use ServesSyntheticSquareAccount;

    private Workspace $workspace;

    /** @var list<array{string, array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        Date::setTestNow('2026-10-12 18:00:00');
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = [$event->message, $event->context];
        });
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic mirror workspace', 'slug' => 'synthetic-mirror']);
        $this->connectSquare($this->workspace);
    }

    protected function tearDown(): void
    {
        foreach ($this->logged as [$message, $context]) {
            $this->assertStringNotContainsString(self::SQUARE_TOKEN, $message.json_encode($context));
        }
        parent::tearDown();
    }

    public function test_opaque_invoice_ids_survive_complete_pagination_and_repeat_runs(): void
    {
        $ids = ['inv:0-synthetic_normal', '_synthetic_legacy_invoice', 'SyntheticLegacyInvoice=='];
        $mapper = app(SquareSnapshotMapper::class);
        foreach ($ids as $id) {
            $this->assertSame($id, $mapper->invoice(self::squareInvoice($id))['square_invoice_id'] ?? null);
        }
        $this->serveSquareAccount(invoicePages: [
            [self::squareInvoice($ids[0]), self::squareInvoice($ids[1])],
            [self::squareInvoice($ids[2])],
        ]);
        $freshness = app(SquareMirrorReadService::class);
        $this->assertNull($freshness->freshness($this->workspace)['last_synced_at']);
        $first = $this->sync();
        $this->assertNotNull($freshness->freshness($this->workspace)['last_synced_at']);
        $this->assertSame([SquareSyncRun::COMPLETED, 3, 3, false], [$first->status, $first->invoices_seen, $first->invoices_changed, $first->truncated]);
        $before = SquareInvoiceSnapshot::query()->where('workspace_id', $this->workspace->id)->orderBy('id')->get()->toArray();
        $fingerprints = SquareInvoiceSnapshot::query()->orderBy('id')->pluck('fingerprint')->all();
        $this->assertSame($ids, array_column($before, 'square_invoice_id'));
        Date::setTestNow('2026-10-12 18:15:00');
        $second = $this->sync();
        $this->assertSame([SquareSyncRun::COMPLETED, 3, 0], [$second->status, $second->invoices_seen, $second->invoices_changed]);
        $this->assertSame($fingerprints, SquareInvoiceSnapshot::query()->orderBy('id')->pluck('fingerprint')->all());
        $this->assertSame(3, SquareInvoiceSnapshot::query()->count());
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/v2/invoices') && ($request->data()['cursor'] ?? null) === 'cursor-2');

        $lastSynced = $freshness->freshness($this->workspace)['last_synced_at'];
        Date::setTestNow('2026-10-12 18:30:00');
        // A bad later page must not retire the padded invoice absent from this run.
        $this->serveSquareAccount(invoicePages: [[self::squareInvoice($ids[0])], [self::squareInvoice('synthetic/invalid')]]);
        $failed = $this->sync();
        $this->assertSame([SquareSyncRun::FAILED, 'UNREADABLE', 1], [$failed->status, $failed->error_code, $failed->invoices_seen]);
        $this->assertSame(3, SquareInvoiceSnapshot::query()->count());
        $this->assertSame(0, SquareInvoiceSnapshot::onlyTrashed()->count());
        $this->assertSame($lastSynced, $freshness->freshness($this->workspace)['last_synced_at']);
        $this->assertContains(['Square mirror sync failed', ['stage' => 'invoices', 'reason' => 'MAPPING_REJECTED']], $this->logged);
    }

    public function test_failed_invoice_reads_preserve_snapshots_and_freshness_with_bounded_diagnostics(): void
    {
        $body = ['invoices' => [self::squareInvoice()]];
        $transport = false;
        Http::fake(function (Request $request) use (&$body, &$transport) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v2/locations') {
                return Http::response(['locations' => [self::squareLocation()]]);
            }
            if ($path === '/v2/customers') {
                return Http::response([]);
            }
            if ($transport) {
                throw new ConnectionException('synthetic private transport detail');
            }

            return Http::response($body);
        });
        $this->assertSame(SquareSyncRun::COMPLETED, $this->sync()->status);
        $before = SquareInvoiceSnapshot::query()->sole()->toArray();
        $freshness = app(SquareMirrorReadService::class);
        $lastSynced = $freshness->freshness($this->workspace)['last_synced_at'];
        Date::setTestNow('2026-10-12 18:30:00');
        foreach ([
            'MALFORMED_JSON' => '{synthetic invalid json',
            'MALFORMED_RECORDS' => ['invoices' => ['synthetic invalid record']],
            'MAPPING_REJECTED' => ['invoices' => [self::squareInvoice('synthetic/invalid')]],
            'INVALID_CURSOR' => ['invoices' => [], 'cursor' => ['synthetic-invalid']],
            'REPEATED_CURSOR' => ['invoices' => [], 'cursor' => 'synthetic-repeated'],
            'TRANSPORT' => [],
        ] as $reason => $response) {
            $body = $response;
            $transport = $reason === 'TRANSPORT';
            $run = $this->sync();
            $this->assertSame(SquareSyncRun::FAILED, $run->status);
            $this->assertSame($transport ? 'SERVICE_UNAVAILABLE' : 'UNREADABLE', $run->error_code);
            $this->assertSame($before, SquareInvoiceSnapshot::query()->sole()->toArray());
            $this->assertSame(0, SquareInvoiceSnapshot::onlyTrashed()->count());
            $this->assertSame($lastSynced, $freshness->freshness($this->workspace)['last_synced_at']);
            $this->assertContains(['Square mirror sync failed', ['stage' => 'invoices', 'reason' => $reason]], $this->logged);
        }
        $diagnostics = array_values(array_filter($this->logged, static fn (array $entry): bool => $entry[0] === 'Square mirror sync failed'));
        foreach ($diagnostics as [, $context]) {
            $this->assertSame(['stage', 'reason'], array_keys($context));
        }
    }

    public function test_payment_resolution_reads_bounded_pages_when_history_is_already_resolved(): void
    {
        $mapper = app(SquareSnapshotMapper::class);
        $repository = app(SquareMirrorRepository::class);
        $now = CarbonImmutable::parse('2030-01-01');
        $rows = [];
        for ($index = 0; $index < 12; $index++) {
            $invoice = self::squareInvoice('inv_fixture_payment_history_'.$index);
            $invoice['status'] = 'PAID';
            $invoice['payment_requests'][0]['total_completed_amount_money'] = ['amount' => 12_345, 'currency' => 'USD'];
            $rows[] = $mapper->invoice($invoice);
        }
        $repository->storeInvoices($this->workspace, $rows, $now);
        foreach (array_slice($rows, 0, 10) as $row) {
            $repository->storePaymentIds($this->workspace, $row['square_invoice_id'], SquareSnapshotMapper::paymentIdsBasis('PAID', 12_345, $row['square_version']), []);
        }
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'square_invoice_snapshots')) {
                $queries[] = $query->sql;
            }
        });
        $due = $repository->invoicesNeedingPaymentIds($this->workspace, 2);
        $this->assertSame(['inv_fixture_payment_history_10', 'inv_fixture_payment_history_11'], array_column($due, 'square_invoice_id'));
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/limit [1-2](?: offset [0-9]+)?$/i', $sql, 'The requested lookup budget also bounds each database page.');
        }
    }

    public function test_snapshot_storage_accepts_the_full_square_id_contract(): void
    {
        $id = static fn (string $prefix): string => $prefix.str_repeat('x', 192 - strlen($prefix));
        $locationId = $id('LOCFIXTURE');
        $customerId = $id('cust_fixture_');
        $invoiceId = 'inv_fixture_'.str_repeat('x', 191 - strlen('inv_fixture_'));
        $orderId = $id('order_fixture_');
        $subscriptionId = $id('subscription_fixture_');
        $mapper = app(SquareSnapshotMapper::class);
        $repository = app(SquareMirrorRepository::class);
        $now = CarbonImmutable::parse('2040-01-01');
        $invoice = self::squareInvoice($invoiceId);
        $invoice['location_id'] = $locationId;
        $invoice['order_id'] = $orderId;
        $invoice['primary_recipient']['customer_id'] = $customerId;
        $invoice['subscription_id'] = $subscriptionId;
        $this->assertSame(1, $repository->storeLocations($this->workspace, [$mapper->location(array_replace(self::squareLocation(), ['id' => $locationId]))], $now));
        $this->assertSame(1, $repository->storeCustomers($this->workspace, [$mapper->customer(self::squareCustomer($customerId))], $now));
        $this->assertSame(1, $repository->storeInvoices($this->workspace, [$mapper->invoice($invoice)], $now));
        $this->assertSame($locationId, SquareLocationSnapshot::query()->where('workspace_id', $this->workspace->id)->sole()->square_location_id);
        $this->assertSame($customerId, SquareCustomerSnapshot::query()->where('workspace_id', $this->workspace->id)->sole()->square_customer_id);
        $this->assertSame([$invoiceId, $locationId, $orderId, $customerId, $subscriptionId], array_values(SquareInvoiceSnapshot::query()->where('workspace_id', $this->workspace->id)->sole()->only(['square_invoice_id', 'square_location_id', 'square_order_id', 'square_customer_id', 'square_subscription_id'])));
    }

    public function test_a_sync_mirrors_locations_customers_and_invoices_with_their_series_and_payment_ids(): void
    {
        $this->serveSquareAccount();

        $run = $this->sync();

        $this->assertSame(SquareSyncRun::COMPLETED, $run->status);
        $this->assertSame(['locations_seen' => 1, 'customers_seen' => 2, 'customers_changed' => 2, 'invoices_seen' => 3, 'invoices_changed' => 3, 'payments_resolved' => 1], $run->only(['locations_seen', 'customers_seen', 'customers_changed', 'invoices_seen', 'invoices_changed', 'payments_resolved']));
        $this->assertSame('2026-09-16', $run->api_version);
        $this->assertSame('2026-10-02 15:00:00', $run->high_water_mark?->format('Y-m-d H:i:s'));
        $this->assertNull($run->error_code);
        $this->assertFalse($run->truncated);

        $this->assertSame(['LOCFIXTURE01'], SquareLocationSnapshot::query()->pluck('square_location_id')->all());
        $customer = SquareCustomerSnapshot::query()->where('square_customer_id', 'cust_fixture_a')->sole();
        $this->assertSame(['Fixture Holdings', 'avery@example.test', 3], [$customer->company_name, $customer->email_address, $customer->square_version]);

        $recurring = SquareInvoiceSnapshot::query()->where('square_invoice_id', 'inv:0-fixture1')->sole();
        $this->assertSame([
            'square_location_id' => 'LOCFIXTURE01',
            'square_order_id' => 'order_fixture_1',
            'square_customer_id' => 'cust_fixture_a',
            'invoice_number' => '000001-R-0001',
            'series_key' => '000001',
            'series_occurrence' => 1,
            'recipient_name' => 'Fixture Holdings',
            'status' => 'UNPAID',
            'currency' => 'USD',
            'total_amount' => 12_345,
            'paid_amount' => 0,
            'next_payment_amount' => 12_345,
            'public_url' => 'https://squareupsandbox.com/pay-invoice/inv:0-fixture1',
            'autopay' => true,
            'payment_ids' => null,
        ], $recurring->only(['square_location_id', 'square_order_id', 'square_customer_id', 'invoice_number', 'series_key', 'series_occurrence', 'recipient_name', 'status', 'currency', 'total_amount', 'paid_amount', 'next_payment_amount', 'public_url', 'autopay', 'payment_ids']));
        $this->assertSame('2026-10-15', $recurring->due_on?->toDateString());
        $this->assertSame('2026-10-01 15:00:00', $recurring->scheduled_at?->format('Y-m-d H:i:s'));
        $this->assertSame([['uid' => 'rem-fixture-1', 'relative_scheduled_days' => -1, 'status' => 'PENDING', 'sent_at' => null]], $recurring->payment_requests[0]['reminders']);

        $paid = SquareInvoiceSnapshot::query()->where('square_invoice_id', 'inv:0-fixture2')->sole();
        $this->assertSame(['PAID', 12_345, ['pay_fixture_1'], '000001', 2], [$paid->status, $paid->paid_amount, $paid->payment_ids, $paid->series_key, $paid->series_occurrence]);
        $oneOff = SquareInvoiceSnapshot::query()->where('square_invoice_id', 'inv:0-fixture3')->sole();
        $this->assertSame([null, null, false], [$oneOff->series_key, $oneOff->series_occurrence, $oneOff->autopay]);

        // Only the allow-listed fields reach the database.
        $stored = json_encode([DB::table('square_invoice_snapshots')->get(), DB::table('square_customer_snapshots')->get(), DB::table('square_location_snapshots')->get()], JSON_THROW_ON_ERROR);
        foreach (['ccof:fixture-card', 'Synthetic Way', 'Private operator note', 'sq-1-fixturefingerprint', '411111', 'MERCHANTFIXTURE', 'TMFIXTURE', 'att-fixture'] as $private) {
            $this->assertStringNotContainsString($private, $stored);
        }

        // Every page is logged with the Square-Version it was read under.
        $batches = array_values(array_filter($this->logged, static fn (array $entry): bool => $entry[0] === 'Square mirror sync batch'));
        $this->assertSame(['locations', 'customers', 'invoices'], array_column(array_column($batches, 1), 'resource'));
        $this->assertSame(['2026-09-16'], array_values(array_unique(array_column(array_column($batches, 1), 'square_version'))));

        // Read-only: every request was a read Square's allow-list holds.
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET' && $request->header('Square-Version') === ['2026-09-16']);
        Http::assertNotSent(static fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_an_unchanged_account_writes_nothing_but_when_each_row_was_last_seen(): void
    {
        $this->serveSquareAccount();
        $this->sync();
        $before = SquareInvoiceSnapshot::query()->orderBy('id')->get(['square_invoice_id', 'synced_at', 'updated_at'])->toArray();
        $locationsBefore = SquareLocationSnapshot::query()->orderBy('id')->get(['square_location_id', 'synced_at', 'updated_at'])->toArray();
        $customersBefore = SquareCustomerSnapshot::query()->orderBy('id')->get(['square_customer_id', 'synced_at', 'updated_at'])->toArray();

        Date::setTestNow('2026-10-12 18:15:00');
        $second = $this->sync();

        $this->assertSame([0, 0, 0], [$second->invoices_changed, $second->customers_changed, $second->payments_resolved]);
        $this->assertSame(3, $second->invoices_seen);
        $this->assertSame($before, SquareInvoiceSnapshot::query()->orderBy('id')->get(['square_invoice_id', 'synced_at', 'updated_at'])->toArray());
        $this->assertSame($locationsBefore, SquareLocationSnapshot::query()->orderBy('id')->get(['square_location_id', 'synced_at', 'updated_at'])->toArray());
        $this->assertSame($customersBefore, SquareCustomerSnapshot::query()->orderBy('id')->get(['square_customer_id', 'synced_at', 'updated_at'])->toArray());
        $this->assertSame(['2026-10-12 18:15:00'], SquareInvoiceSnapshot::query()->pluck('last_seen_at')->map(static fn ($at): string => $at->format('Y-m-d H:i:s'))->unique()->values()->all());
        $this->assertSame(3, SquareInvoiceSnapshot::query()->count());
        $this->assertSame(2, SquareSyncRun::query()->where('status', SquareSyncRun::COMPLETED)->count());
    }

    public function test_a_changed_invoice_is_rewritten_and_its_payment_ids_read_again(): void
    {
        $this->serveSquareAccount();
        $this->sync();

        $paidNow = self::squareInvoice('inv:0-fixture1', '000001-R-0001');
        $paidNow['version'] = 3;
        $paidNow['status'] = 'PAID';
        $paidNow['payment_requests'][0]['total_completed_amount_money'] = ['amount' => 12_345, 'currency' => 'USD'];
        $this->serveSquareAccount(invoices: [$paidNow, ...array_slice($this->syntheticInvoices(), 1)]);
        $run = $this->sync();

        $this->assertSame([1, 1], [$run->invoices_changed, $run->payments_resolved]);
        $snapshot = SquareInvoiceSnapshot::query()->where('square_invoice_id', 'inv:0-fixture1')->sole();
        $this->assertSame(['PAID', 3, 12_345, ['pay_fixture_1']], [$snapshot->status, $snapshot->square_version, $snapshot->paid_amount, $snapshot->payment_ids]);
    }

    /**
     * A refund and an equal recapture between two syncs (#489) end with the
     * invoice paid in full again: status and paid amount are what they were,
     * but Square advanced the invoice's version and the order holds a new
     * tender. The version is what sends the order to be read again.
     */
    public function test_a_refund_and_an_equal_recapture_between_syncs_reads_the_order_again(): void
    {
        $this->serveSquareAccount();
        $this->sync();
        $this->assertSame(['pay_fixture_1'], SquareInvoiceSnapshot::query()->where('square_invoice_id', 'inv:0-fixture2')->sole()->payment_ids);

        $recaptured = [
            ['id' => 'tender_fixture_1', 'type' => 'CARD', 'amount_money' => ['amount' => 12_345, 'currency' => 'USD'], 'payment_id' => 'pay_fixture_1', 'card_details' => ['status' => 'CAPTURED']],
            ['id' => 'tender_fixture_9', 'type' => 'CARD', 'amount_money' => ['amount' => 12_345, 'currency' => 'USD'], 'payment_id' => 'pay_fixture_9', 'card_details' => ['status' => 'CAPTURED']],
        ];
        $this->squareOrderOverrides = ['tenders' => $recaptured];
        // The same invoice as before: nothing says the tenders changed.
        $this->sync();
        $this->assertSame(['pay_fixture_1'], SquareInvoiceSnapshot::query()->where('square_invoice_id', 'inv:0-fixture2')->sole()->payment_ids);

        [$unpaid, $paid, $oneOff] = $this->syntheticInvoices();
        $paid['version'] = 5;
        $paid['updated_at'] = '2026-10-03T09:00:00Z';
        $this->serveSquareAccount(invoices: [$unpaid, $paid, $oneOff]);
        $run = $this->sync();

        $snapshot = SquareInvoiceSnapshot::query()->where('square_invoice_id', 'inv:0-fixture2')->sole();
        $this->assertSame(['PAID', 12_345, 5, ['pay_fixture_1', 'pay_fixture_9']], [$snapshot->status, $snapshot->paid_amount, $snapshot->square_version, $snapshot->payment_ids]);
        $this->assertSame(['PAID|12345|5|t3', 1], [$snapshot->getAttribute('payment_ids_basis'), $run->payments_resolved]);
    }

    public function test_a_paid_order_showing_no_settled_tender_is_retried_not_taken_as_paymentless(): void
    {
        $this->squareOrderOverrides = ['tenders' => [['id' => 'tender_fixture_1', 'payment_id' => 'pay_fixture_1', 'card_details' => ['status' => 'AUTHORIZED']]]];
        $this->serveSquareAccount();
        $this->sync();

        $snapshot = SquareInvoiceSnapshot::query()->where('square_invoice_id', 'inv:0-fixture2')->sole();
        $this->assertSame(['PAID|12345|2|t3|p', null], [$snapshot->getAttribute('payment_ids_basis'), $snapshot->payment_ids]);

        $this->squareOrderOverrides = [];
        $this->sync();
        $this->assertSame(['pay_fixture_1'], SquareInvoiceSnapshot::query()->where('square_invoice_id', 'inv:0-fixture2')->sole()->payment_ids);
    }

    public function test_an_older_copy_never_replaces_a_newer_one(): void
    {
        $mapper = app(SquareSnapshotMapper::class);
        $repository = app(SquareMirrorRepository::class);
        $newer = self::squareInvoice();
        $newer['version'] = 5;
        $newer['status'] = 'PAID';
        $older = self::squareInvoice();
        $older['version'] = 4;

        $this->assertSame(1, $repository->storeInvoices($this->workspace, [$mapper->invoice($newer)], Date::now()->toImmutable()));
        $this->assertSame(0, $repository->storeInvoices($this->workspace, [$mapper->invoice($older)], Date::now()->toImmutable()));
        $this->assertSame(['status' => 'PAID', 'square_version' => 5], SquareInvoiceSnapshot::query()->sole()->only(['status', 'square_version']));

        // The same version with a new presentation (svc's own projection
        // changed) is still written.
        $same = $newer;
        $same['title'] = 'Renamed retainer';
        $this->assertSame(1, $repository->storeInvoices($this->workspace, [$mapper->invoice($same)], Date::now()->toImmutable()));
        $this->assertSame('Renamed retainer', SquareInvoiceSnapshot::query()->sole()->title);
    }

    public function test_listings_are_followed_across_pages_and_a_repeated_cursor_fails_the_run(): void
    {
        $invoices = $this->syntheticInvoices();
        $this->serveSquareAccount(invoicePages: [[$invoices[0]], [$invoices[1]], [$invoices[2]]]);

        $run = $this->sync();

        $this->assertSame([SquareSyncRun::COMPLETED, 3], [$run->status, $run->invoices_seen]);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/v2/invoices') && ($request->data()['cursor'] ?? null) === 'cursor-2');

        $this->serveSquareAccount(invoicePages: [[$invoices[0]], [$invoices[1]]], loopCursor: true);
        $looping = $this->sync();
        $this->assertSame([SquareSyncRun::FAILED, 'UNREADABLE'], [$looping->status, $looping->error_code]);
        $this->assertSame(3, SquareInvoiceSnapshot::query()->count());
    }

    public function test_a_square_failure_ends_the_run_failed_with_a_safe_code(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['category' => 'API_ERROR', 'code' => 'INTERNAL_SERVER_ERROR', 'detail' => 'synthetic '.self::SQUARE_TOKEN]]], 500)]);

        $run = $this->sync();

        $this->assertSame([SquareSyncRun::FAILED, 'SERVICE_UNAVAILABLE'], [$run->status, $run->error_code]);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, SquareInvoiceSnapshot::query()->count());
        $this->assertStringNotContainsString(self::SQUARE_TOKEN, json_encode($run->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_a_run_is_performed_once_and_a_run_for_an_unbound_workspace_does_nothing(): void
    {
        $this->serveSquareAccount();
        $sync = app(SquareMirrorSync::class);
        $run = $sync->perform($this->workspace, $sync->request($this->workspace, SquareSyncRun::TRIGGER_REQUEST));
        $calls = count(Http::recorded());

        $again = $sync->perform($this->workspace, $run);

        $this->assertSame([SquareSyncRun::COMPLETED, $run->public_id], [$again->status, $again->public_id]);
        $this->assertCount($calls, Http::recorded());

        $other = Workspace::query()->create(['name' => 'Synthetic unbound workspace', 'slug' => 'synthetic-unbound']);
        $refused = $sync->perform($other, $sync->request($other, SquareSyncRun::TRIGGER_REQUEST));
        $this->assertSame([SquareSyncRun::FAILED, 'NOT_CONNECTED'], [$refused->status, $refused->error_code]);
        $this->assertCount($calls, Http::recorded());
        $this->assertSame(0, SquareInvoiceSnapshot::query()->where('workspace_id', $other->id)->count());
    }

    public function test_runs_left_behind_by_a_dead_process_are_ended(): void
    {
        $sync = app(SquareMirrorSync::class);
        $stale = $sync->request($this->workspace, SquareSyncRun::TRIGGER_SCHEDULE);
        $stale->forceFill(['status' => SquareSyncRun::RUNNING, 'created_at' => Date::now()->subMinutes(45)])->save();
        $fresh = $sync->request($this->workspace, SquareSyncRun::TRIGGER_SCHEDULE);

        $this->assertSame(1, $sync->abandonStale($this->workspace));

        $this->assertSame([SquareSyncRun::FAILED, 'ABANDONED'], [$stale->fresh()?->status, $stale->fresh()?->error_code]);
        $this->assertSame(SquareSyncRun::REQUESTED, $fresh->fresh()?->status);
    }

    public function test_every_mirror_write_names_its_workspace(): void
    {
        $this->serveSquareAccount();
        $this->sync();
        $paidNow = self::squareInvoice('inv:0-fixture1', '000001-R-0001');
        $paidNow['version'] = 3;
        $paidNow['status'] = 'PAID';
        $paidNow['payment_requests'][0]['total_completed_amount_money'] = ['amount' => 12_345, 'currency' => 'USD'];
        $this->serveSquareAccount(invoices: [$paidNow, ...array_slice($this->syntheticInvoices(), 1)]);

        $writes = $this->writesIssuedBy(fn () => $this->sync());

        $this->assertEveryTenantOwnedWriteNamesItsWorkspace($writes, ['square_invoice_snapshots', 'square_customer_snapshots', 'square_location_snapshots', 'square_sync_runs']);
    }

    public function test_the_command_syncs_the_bound_workspace_every_fifteen_minutes_and_is_quiet_without_square(): void
    {
        $events = array_values(array_filter(app(Schedule::class)->events(), static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'svc:square:sync')));
        $this->assertCount(1, $events);
        $this->assertSame('*/15 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);

        $this->serveSquareAccount();
        $this->artisan('svc:square:sync', ['--apply' => true])->assertSuccessful();
        $this->assertSame(['trigger' => SquareSyncRun::TRIGGER_SCHEDULE, 'status' => SquareSyncRun::COMPLETED], SquareSyncRun::query()->sole()->only(['trigger', 'status']));

        config(['services.square.access_token' => null]);
        $this->artisan('svc:square:sync', ['--apply' => true])->expectsOutputToContain('not configured')->assertSuccessful();
        $this->assertSame(1, SquareSyncRun::query()->count());

        $this->connectSquare(null, ['workspace' => '00000000-0000-4000-8000-000000000000']);
        $this->artisan('svc:square:sync', ['--apply' => true])->assertFailed();
    }

    private function sync(): SquareSyncRun
    {
        $sync = app(SquareMirrorSync::class);

        return $sync->perform($this->workspace, $sync->request($this->workspace, SquareSyncRun::TRIGGER_REQUEST));
    }
}
