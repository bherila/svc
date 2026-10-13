<?php

namespace Tests\Feature\Square;

use App\Models\SquareCustomerSnapshot;
use App\Models\SquareInvoiceSnapshot;
use App\Models\SquareLocationSnapshot;
use App\Models\SquareSyncRun;
use App\Models\Workspace;
use App\Services\Square\Mirror\SquareMirrorRepository;
use App\Services\Square\Mirror\SquareMirrorSync;
use App\Services\Square\Mirror\SquareSnapshotMapper;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesSquare;
use Tests\TestCase;

final class SquareMirrorRetirementTest extends TestCase
{
    use FakesSquare;
    use RefreshDatabase;

    private Workspace $workspace;

    private array $account;

    private int $ambientDepth;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        Date::setTestNow('2030-01-01');
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic retirement workspace', 'slug' => 'synthetic-retirement']);
        $this->connectSquare($this->workspace);
        $this->ambientDepth = DB::transactionLevel();
        $this->account = ['locations' => [self::squareLocation()], 'customers' => [self::squareCustomer()], 'invoices' => [self::squareInvoice()]];
        $page = 0;
        Http::fake(function (Request $request) use (&$page) {
            $this->assertSame($this->ambientDepth, DB::transactionLevel(), 'A Square call must not hold the mirror write lock.');
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v2/invoices' && ($this->account['fail'] ?? false)) {
                return Http::response(['errors' => [['category' => 'API_ERROR', 'code' => 'INTERNAL_SERVER_ERROR']]], 503);
            }
            $key = match ($path) {
                '/v2/locations' => 'locations',
                '/v2/customers' => 'customers',
                '/v2/invoices' => 'invoices',
                default => throw new \LogicException('Unexpected synthetic Square read.'),
            };
            $body = [$key => $this->account[$key]];
            if ($key === 'invoices' && ($this->account['truncate'] ?? false)) {
                $body['cursor'] = 'fixture_cursor_'.(++$page);
            }

            return Http::response($body);
        });
    }

    public function test_a_complete_empty_listing_retires_records_only_in_its_workspace(): void
    {
        $this->assertSame(SquareSyncRun::COMPLETED, $this->sync()->status);
        $other = Workspace::query()->create(['name' => 'Synthetic other mirror', 'slug' => 'synthetic-other-mirror']);
        $this->store($other, null, false);
        $this->account = ['locations' => [], 'customers' => [], 'invoices' => []];
        $this->assertSame(SquareSyncRun::COMPLETED, $this->sync()->status);
        foreach ([SquareLocationSnapshot::class, SquareCustomerSnapshot::class, SquareInvoiceSnapshot::class] as $model) {
            $this->assertSame(0, $model::query()->where('workspace_id', $this->workspace->id)->count());
            $this->assertSame(1, $model::query()->where('workspace_id', $other->id)->count());
            $this->assertSame(1, DB::table((new $model)->getTable())->where('workspace_id', $this->workspace->id)->count(), 'Retirement preserves the snapshot.');
        }
    }

    public function test_failed_and_truncated_listings_do_not_retire_unseen_rows(): void
    {
        $this->sync();
        $this->account['customers'] = [];
        $this->account['invoices'] = [];
        $this->account['fail'] = true;
        $this->assertSame(SquareSyncRun::FAILED, $this->sync()->status);
        $this->assertSame(1, SquareCustomerSnapshot::query()->where('workspace_id', $this->workspace->id)->count());
        $this->assertSame(1, SquareInvoiceSnapshot::query()->where('workspace_id', $this->workspace->id)->count());
        unset($this->account['fail']);
        $this->account['truncate'] = true;
        $run = $this->sync();
        $this->assertSame(SquareSyncRun::COMPLETED, $run->status);
        $this->assertTrue($run->truncated);
        $this->assertSame(1, SquareCustomerSnapshot::query()->where('workspace_id', $this->workspace->id)->count());
        $this->assertSame(1, SquareInvoiceSnapshot::query()->where('workspace_id', $this->workspace->id)->count());
    }

    public function test_old_generations_cannot_revive_tombstones_or_retire_newer_records_and_new_generations_can_restore_them(): void
    {
        $this->sync();
        $original = SquareInvoiceSnapshot::query()->where('workspace_id', $this->workspace->id)->sole()->id;
        $sync = app(SquareMirrorSync::class);
        $older = $sync->request($this->workspace, SquareSyncRun::TRIGGER_SCHEDULE);
        $newer = $sync->request($this->workspace, SquareSyncRun::TRIGGER_SCHEDULE);
        $repository = app(SquareMirrorRepository::class);
        $this->store($this->workspace, $newer->id, true);
        $repository->retireMissing($this->workspace, $newer->id, CarbonImmutable::parse('2030-01-01'));
        $newer->forceFill(['status' => SquareSyncRun::COMPLETED])->save();
        $this->store($this->workspace, $older->id, false);
        $repository->retireMissing($this->workspace, $older->id, CarbonImmutable::parse('2030-01-01'));
        foreach ([SquareLocationSnapshot::class, SquareCustomerSnapshot::class, SquareInvoiceSnapshot::class] as $model) {
            $this->assertSame(1, $model::query()->where('workspace_id', $this->workspace->id)->count());
            $this->assertSame(1, $model::onlyTrashed()->where('workspace_id', $this->workspace->id)->count());
        }
        $latest = $sync->request($this->workspace, SquareSyncRun::TRIGGER_SCHEDULE);
        $this->store($this->workspace, $latest->id, false);
        $this->assertSame($original, SquareInvoiceSnapshot::query()->where('workspace_id', $this->workspace->id)->where('square_invoice_id', 'inv:0-fixture1')->sole()->id);
        $this->assertSame(0, SquareInvoiceSnapshot::onlyTrashed()->where('workspace_id', $this->workspace->id)->count());
        $this->assertSame(2, SquareInvoiceSnapshot::query()->where('workspace_id', $this->workspace->id)->count());
    }

    public function test_an_older_run_cannot_insert_a_record_after_a_newer_complete_listing(): void
    {
        $sync = app(SquareMirrorSync::class);
        $older = $sync->request($this->workspace, SquareSyncRun::TRIGGER_SCHEDULE);
        $this->account = ['locations' => [], 'customers' => [], 'invoices' => []];
        $newer = $this->sync();
        $this->assertSame(SquareSyncRun::COMPLETED, $newer->status);
        $this->store($this->workspace, $older->id, false);
        foreach ([SquareLocationSnapshot::class, SquareCustomerSnapshot::class, SquareInvoiceSnapshot::class] as $model) {
            $this->assertSame(0, $model::withTrashed()->where('workspace_id', $this->workspace->id)->count());
        }
    }

    public function test_malformed_records_never_make_a_listing_eligible_for_retirement(): void
    {
        $this->sync();
        $original = $this->account;
        foreach (['locations', 'customers', 'invoices'] as $kind) {
            foreach (['invalid', ['id' => 'invalid id', 'status' => 'UNPAID']] as $invalid) {
                $this->account = $original;
                $this->account[$kind] = [$invalid];
                $run = $this->sync();
                $this->assertSame([SquareSyncRun::FAILED, 'UNREADABLE'], [$run->status, $run->error_code]);
                foreach ([SquareLocationSnapshot::class, SquareCustomerSnapshot::class, SquareInvoiceSnapshot::class] as $model) {
                    $this->assertSame(1, $model::query()->where('workspace_id', $this->workspace->id)->count());
                }
            }
        }
    }

    public function test_mirror_writes_use_the_registered_workspace_lock_only(): void
    {
        LockOrderRecorder::start();
        $this->sync();
        $sequences = LockOrderRecorder::sequences();
        $this->assertNotEmpty($sequences);
        foreach ($sequences as $sequence) {
            $this->assertSame([LockResource::Workspace], $sequence);
        }
    }

    private function store(Workspace $workspace, ?int $generation, bool $different): void
    {
        $mapper = app(SquareSnapshotMapper::class);
        $repository = app(SquareMirrorRepository::class);
        $location = self::squareLocation();
        $customer = self::squareCustomer();
        $invoice = self::squareInvoice();
        if ($different) {
            $location['id'] = 'LOCFIXTURE02';
            $customer['id'] = 'cust_fixture_b';
            $invoice['id'] = 'inv_fixture_new';
            $invoice['location_id'] = $location['id'];
            $invoice['primary_recipient']['customer_id'] = $customer['id'];
        }
        $now = CarbonImmutable::parse('2030-01-01');
        $repository->storeLocations($workspace, [$mapper->location($location)], $now, $generation);
        $repository->storeCustomers($workspace, [$mapper->customer($customer)], $now, $generation);
        $repository->storeInvoices($workspace, [$mapper->invoice($invoice)], $now, $generation);
    }

    private function sync(): SquareSyncRun
    {
        $sync = app(SquareMirrorSync::class);

        return $sync->perform($this->workspace, $sync->request($this->workspace, SquareSyncRun::TRIGGER_SCHEDULE));
    }
}
