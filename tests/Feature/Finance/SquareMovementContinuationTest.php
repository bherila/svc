<?php

namespace Tests\Feature\Finance;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ProcessorMovement;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesSquare;
use Tests\TestCase;

final class SquareMovementContinuationTest extends TestCase
{
    use FakesSquare;
    use RefreshDatabase;

    /** @var array<string, list<list<string>>> */
    private array $pages = ['LOCFIXTURE01' => [['po_new', 'po_middle', 'po_old']]];

    /** @var list<string> */
    private array $reads = [];

    /** @var list<array{string, string}> */
    private array $windows = [];

    private ?string $deferred = null;

    private ?string $failed = null;

    private int $failureStatus = 503;

    private string $merchant = 'MERCHANTFIXTURE';

    private bool $late = false;

    private string $paymentIdentifier = 'payment_synthetic';

    private bool $sameInstant = false;

    /** @var array<string, array{issued: int, begin: string, end: string, offset: int}> */
    private array $cursors = [];

    /** @var array<string, string> */
    private array $createdAt = [];

    private bool $misorder = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-02-01 12:00:00');
        config(['cache.default' => 'array']);
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->assertSame('GET', $request->method());
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v2/locations') {
                return Http::response(['locations' => array_map(fn (string $id): array => ['id' => $id, 'merchant_id' => $this->merchant], array_keys($this->pages))]);
            }
            if ($path === '/v2/payouts') {
                $this->windows[] = [$request['begin_time'], $request['end_time']];
                $location = $request['location_id'];
                $ids = array_merge(...$this->pages[$location]);
                $rows = [];
                foreach ($ids as $index => $id) {
                    $created = $this->createdAt[$id] ?? CarbonImmutable::parse('2030-01-15T12:00:00Z')->addSeconds($this->sameInstant ? 0 : $index)->toIso8601ZuluString();
                    if ($this->instantKey($created) >= $this->instantKey($request['begin_time']) && $this->instantKey($created) < $this->instantKey($request['end_time'])) {
                        $rows[] = ['id' => $id, 'location_id' => $location, 'created_at' => $created];
                    }
                }
                if ($request['sort_order'] === 'DESC' || $this->misorder) {
                    $rows = array_reverse($rows);
                }
                $offset = 0;
                if (($request['cursor'] ?? null) !== null) {
                    $cursor = $this->cursors[$request['cursor']] ?? null;
                    if ($cursor === null || now()->timestamp - $cursor['issued'] > 300
                        || $request['begin_time'] !== $cursor['begin'] || $request['end_time'] !== $cursor['end']) {
                        return Http::response([], 400);
                    }
                    $offset = $cursor['offset'];
                }
                $size = min(100, count($this->pages[$location][0]));
                $next = null;
                if (count($rows) > $offset + $size) {
                    $next = 'cursor_'.count($this->cursors);
                    $this->cursors[$next] = ['issued' => now()->timestamp, 'begin' => $request['begin_time'], 'end' => $request['end_time'], 'offset' => $offset + $size];
                }

                return Http::response(['payouts' => array_slice($rows, $offset, $size), 'cursor' => $next]);
            }
            $id = explode('/', $path)[3] ?? '';
            foreach ($this->pages as $location => $pages) {
                if (! in_array($id, array_merge(...$pages), true)) {
                    continue;
                }
                if (! str_ends_with($path, '/payout-entries')) {
                    $this->reads[] = $id;
                    if ($id === $this->failed) {
                        return Http::response(['errors' => [['category' => 'INVALID_REQUEST_ERROR', 'code' => 'NOT_FOUND']]], $this->failureStatus);
                    }

                    return Http::response(['payout' => ['id' => $id, 'location_id' => $location]]);
                }
                $entries = [$this->entry($id)];
                if ($this->late && $id === 'po_new') {
                    $entries[] = $this->entry($id, 'late_');
                }

                return Http::response(['payout_entries' => $entries]);
            }
            $this->fail('Unexpected synthetic Square request.');
        });
    }

    public function test_dense_same_day_pages_and_locations_progress_with_frozen_default_bounds(): void
    {
        $this->fixture();
        $this->pages = ['LOCFIXTURE01' => [['po_new', 'po_middle'], ['po_old']], 'LOCFIXTURE02' => [['po_other']]];
        for ($i = 0; $i < 4; $i++) {
            $report = $this->runSync(['--limit' => 1]);
            $this->assertSame(1, $report['exit']);
            $this->assertSame('record_limit', $report['providers']['square']['note']);
            $this->travel(1)->days();
        }
        $this->assertSame(['po_new', 'po_middle', 'po_old', 'po_other'], $this->reads);
        $this->assertCount(1, array_unique(array_column($this->windows, 1)));
        $this->assertDatabaseCount('processor_movements', 4);
        $this->assertTrue($this->runSync(['--limit' => 1])['completed']);
        $this->late = true;
        $this->assertSame(1, $this->runSync(['--limit' => 1])['providers']['square']['created']);
        $this->assertDatabaseCount('processor_movements', 5);
        $this->assertCount(2, array_unique(array_column($this->windows, 1)));
    }

    public function test_preview_does_not_advance_or_reset_an_existing_position(): void
    {
        $this->fixture();
        $this->runSync(['--limit' => 1]);
        $before = serialize(Cache::getStore());
        $this->runSync(['--limit' => 1, '--apply' => false]);
        $this->runSync(['--limit' => 1, '--apply' => false]);
        $this->assertSame($before, serialize(Cache::getStore()));
        $this->assertDatabaseCount('processor_movements', 1);
        $this->runSync(['--limit' => 1]);
        $this->assertSame(['po_new', 'po_middle', 'po_middle', 'po_middle'], $this->reads);
        $this->assertDatabaseCount('processor_movements', 2);
        $this->runSync();
        $before = serialize(Cache::getStore());
        $this->runSync(['--apply' => false]);
        $this->assertSame($before, serialize(Cache::getStore()));
    }

    public function test_real_budget_denial_keeps_the_current_payout_pending_but_final_allowed_call_advances(): void
    {
        $this->fixture();
        $first = $this->runSync(['--max-calls' => 5]);
        $this->assertSame(1, $first['exit']);
        $this->assertSame(1, $first['providers']['square']['created']);
        $this->assertSame(['po_new', 'po_middle'], $this->reads);
        $next = $this->runSync();
        $this->assertTrue($next['completed']);
        $this->assertSame(['po_new', 'po_middle', 'po_middle', 'po_old'], $this->reads);
        $this->assertDatabaseCount('processor_movements', 3);
    }

    public function test_infrastructure_failure_retries_the_interrupted_payout_without_replaying_the_finished_one(): void
    {
        $this->fixture();
        $this->failed = 'po_middle';
        $this->assertFalse($this->runSync()['completed']);
        $this->failed = null;
        $this->assertTrue($this->runSync()['completed']);
        $this->assertSame(['po_new', 'po_middle', 'po_middle', 'po_old'], $this->reads);
        $this->assertDatabaseCount('processor_movements', 3);
    }

    #[DataProvider('incompleteKinds')]
    public function test_a_clean_final_batch_cannot_erase_earlier_incomplete_coverage_and_next_cycle_retries_it(bool $refused): void
    {
        $this->fixture();
        $this->deferred = $refused ? null : 'po_new';
        $this->failed = $refused ? 'po_new' : null;
        $this->failureStatus = 404;
        $this->assertFalse($this->runSync(['--limit' => 1])['completed']);
        $this->deferred = $this->failed = null;
        $last = $this->runSync();
        $this->assertSame(2, $last['providers']['square']['created']);
        $this->assertFalse($last['completed']);
        $this->assertSame('needs_review', $last['providers']['square']['note']);
        $this->assertTrue($this->runSync()['completed']);
        $this->assertDatabaseCount('processor_movements', 3);
    }

    public static function incompleteKinds(): iterable
    {
        yield 'unbound facts' => [false];
        yield 'record-specific refusal' => [true];
    }

    public function test_finishing_on_the_final_allowed_call_advances_before_a_later_actual_denial(): void
    {
        $this->fixture();
        $report = $this->runSync(['--max-calls' => 4]);
        $this->assertFalse($report['completed']);
        $this->assertSame(4, $report['calls_used']);
        $this->assertSame(1, $report['providers']['square']['created']);
        $this->assertSame(['po_new'], $this->reads);
        $this->assertTrue($this->runSync()['completed']);
        $this->assertSame(['po_new', 'po_middle', 'po_old'], $this->reads);
        $this->assertDatabaseCount('processor_movements', 3);
    }

    public function test_explicit_windows_and_changed_accounts_do_not_borrow_another_cycles_position(): void
    {
        $this->fixture();
        $this->runSync(['--limit' => 1]);
        $this->runSync(['--limit' => 1, '--since' => '2030-01-01', '--until' => '2030-02-01']);
        $this->merchant = 'MERCHANTOTHER';
        $this->runSync(['--limit' => 1]);
        $this->assertSame(['po_new', 'po_new', 'po_new'], $this->reads);
        $this->merchant = 'MERCHANTFIXTURE';
        $this->runSync(['--limit' => 1]);
        $this->assertSame('po_middle', $this->reads[3]);
    }

    public function test_changed_workspace_cannot_advance_or_bind_the_previous_workspaces_scan(): void
    {
        $first = $this->fixture();
        $this->runSync(['--limit' => 1]);
        $second = $this->fixture();
        $report = $this->runSync(['--limit' => 1, '--workspace' => $first->public_id]);
        $this->assertSame('different_workspace', $report['providers']['square']['note']);
        $this->runSync(['--limit' => 1]);
        $this->assertSame(['po_new', 'po_new'], $this->reads);
        $this->assertSame(1, ProcessorMovement::query()->where('workspace_id', $first->id)->count());
        $this->assertSame(1, ProcessorMovement::query()->where('workspace_id', $second->id)->count());
        $this->connectSquare($first, ['max_attempts' => 1]);
        $this->paymentIdentifier = 'payment_synthetic_'.$first->id;
        $this->runSync(['--limit' => 1]);
        $this->assertSame('po_middle', $this->reads[2]);
    }

    public function test_more_than_two_full_pages_in_one_day_progress_with_only_four_calls_per_spaced_run(): void
    {
        $this->fixture();
        $ids = array_map(static fn (int $number): string => 'po_dense_'.$number, range(0, 204));
        $this->pages = ['LOCFIXTURE01' => array_chunk($ids, 100)];
        foreach ($ids as $index => $id) {
            $report = $this->runSync(['--limit' => 1, '--max-calls' => 4]);
            $this->assertFalse($report['completed']);
            $this->assertSame(1, $report['providers']['square']['created'], 'Dense day position '.$index);
            $this->assertLessThanOrEqual(4, $report['calls_used']);
            $this->travel(10)->minutes();
        }
        $this->assertSame($ids, $this->reads);
        $this->assertDatabaseCount('processor_movements', 205);
        $this->assertTrue($this->runSync(['--limit' => 1, '--max-calls' => 4])['completed']);
        Http::assertSent(static fn (Request $request): bool => ($request['begin_time'] ?? '') === '2030-01-15T12:03:18.000000000Z'
            && ($request['sort_order'] ?? '') === 'ASC' && ! isset($request['cursor']));
    }

    public function test_exact_timestamp_ties_are_replayed_by_identity_without_skipping_unseen_rows(): void
    {
        $this->fixture();
        $this->sameInstant = true;
        $this->pages = ['LOCFIXTURE01' => [['po_new', 'po_middle'], ['po_old']]];
        foreach (range(1, 3) as $ignored) {
            $this->assertSame(1, $this->runSync(['--limit' => 1])['providers']['square']['created']);
            $this->travel(1)->days();
        }
        $this->assertSame(['po_new', 'po_middle', 'po_old'], $this->reads);
        $this->assertTrue($this->runSync()['completed']);
    }

    public function test_a_tie_group_larger_than_the_listing_budget_remains_incomplete_until_it_can_be_read(): void
    {
        $this->fixture();
        $this->sameInstant = true;
        $ids = array_map(static fn (int $number): string => 'po_tie_'.$number, range(0, 6));
        $this->pages = ['LOCFIXTURE01' => array_chunk($ids, 2)];
        foreach (range(1, 10) as $ignored) {
            $this->assertFalse($this->runSync(['--limit' => 1, '--max-calls' => 4])['completed']);
            $this->travel(10)->minutes();
        }
        $this->assertDatabaseCount('processor_movements', 6);
        $finished = $this->runSync();
        $this->assertTrue($finished['completed']);
        $this->assertSame(1, $finished['providers']['square']['created']);
        $this->assertDatabaseCount('processor_movements', 7);
    }

    public function test_seek_preserves_nanosecond_timestamp_precision(): void
    {
        $this->fixture();
        $this->pages = ['LOCFIXTURE01' => [['po_new'], ['po_middle'], ['po_old']]];
        $this->createdAt = ['po_new' => '2030-01-15T12:00:00.123456701Z', 'po_middle' => '2030-01-15T12:00:00.123456702Z', 'po_old' => '2030-01-15T12:00:00.123456703Z'];
        foreach (range(1, 3) as $ignored) {
            $this->assertSame(1, $this->runSync(['--limit' => 1])['providers']['square']['created']);
            $this->travel(10)->minutes();
        }
        $this->assertSame(['po_new', 'po_middle', 'po_old'], $this->reads);
        $this->assertTrue($this->runSync()['completed']);
        Http::assertSent(static fn (Request $request): bool => ($request['begin_time'] ?? '') === '2030-01-15T12:00:00.123456702Z');
    }

    public function test_out_of_order_creation_times_are_refused_before_any_page_payout_is_written(): void
    {
        $this->fixture();
        $this->misorder = true;
        $report = $this->runSync();
        $this->assertFalse($report['completed']);
        $this->assertSame('sync_incomplete', $report['providers']['square']['note']);
        $this->assertSame([], $this->reads);
        $this->assertDatabaseCount('processor_movements', 0);
        $this->misorder = false;
        $this->assertTrue($this->runSync()['completed']);
    }

    public function test_changed_locations_cannot_abandon_an_incomplete_cycle_and_restoring_them_resumes(): void
    {
        $this->fixture();
        $this->deferred = 'po_new';
        $this->assertFalse($this->runSync(['--limit' => 1])['completed']);
        $before = serialize(Cache::getStore());
        $this->pages['LOCFIXTURE02'] = [['po_other']];
        $changed = $this->runSync();
        $this->assertFalse($changed['completed']);
        $this->assertSame('sync_incomplete', $changed['providers']['square']['note']);
        $this->assertSame(['po_new'], $this->reads);
        $this->assertSame($before, serialize(Cache::getStore()));
        unset($this->pages['LOCFIXTURE02']);
        $this->deferred = null;
        $finished = $this->runSync();
        $this->assertSame(['po_new', 'po_middle', 'po_old'], $this->reads);
        $this->assertSame('needs_review', $finished['providers']['square']['note']);
        $this->assertFalse($finished['completed']);
        $this->assertTrue($this->runSync()['completed']);
    }

    public function test_malformed_cached_position_is_refused_without_discarding_incomplete_coverage(): void
    {
        $workspace = $this->fixture();
        $selection = json_encode([null, null], JSON_THROW_ON_ERROR);
        $key = 'finance:processor-movements:square:scan-v1:'.hash('sha256', json_encode([$workspace->id, 'square:sandbox:MERCHANTFIXTURE', $selection], JSON_THROW_ON_ERROR));
        Cache::forever($key, ['incomplete' => true]);
        $report = $this->runSync();
        $this->assertFalse($report['completed']);
        $this->assertSame('sync_incomplete', $report['providers']['square']['note']);
        $this->assertSame(['incomplete' => true], Cache::get($key));
        $this->assertSame([], $this->reads);
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_position_persistence_failure_is_incomplete_without_reading_or_writing_a_payout(): void
    {
        $this->fixture();
        $cache = Mockery::mock(Cache::getFacadeRoot());
        $cache->shouldReceive('forever')->once()->andReturn(false);
        Cache::swap($cache);
        $report = $this->runSync();
        $this->assertFalse($report['completed']);
        $this->assertSame('sync_incomplete', $report['providers']['square']['note']);
        $this->assertSame([], $this->reads);
        $this->assertDatabaseCount('processor_movements', 0);
    }

    private function instantKey(string $value): string
    {
        return substr($value, 0, 19).'.'.str_pad(str_contains($value, '.') ? substr($value, 20, -1) : '', 9, '0').'Z';
    }

    private function fixture(): Workspace
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic continuation workspace', 'slug' => 'synthetic-'.Str::uuid(), 'timezone' => 'UTC']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic continuation company', 'slug' => 'synthetic-company']);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYN-CONTINUATION', 'currency' => 'USD']);
        $this->paymentIdentifier = 'payment_synthetic_'.$workspace->id;
        ClientInvoicePayment::query()->create(['workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'provider' => 'square',
            'provider_payment_identifier' => $this->paymentIdentifier, 'status' => 'succeeded', 'method' => 'square', 'amount' => 1000, 'currency' => 'USD']);
        $this->connectSquare($workspace, ['max_attempts' => 1]);

        return $workspace;
    }

    /** @return array<string, mixed> */
    private function entry(string $payout, string $prefix = ''): array
    {
        return ['id' => $prefix.'entry_'.$payout, 'payout_id' => $payout, 'type' => 'REFUND', 'effective_at' => '2030-01-15T12:00:00Z',
            'gross_amount_money' => ['amount' => -100, 'currency' => 'USD'], 'fee_amount_money' => ['amount' => 0, 'currency' => 'USD'], 'net_amount_money' => ['amount' => -100, 'currency' => 'USD'],
            'type_refund_details' => ['payment_id' => $payout === $this->deferred ? 'payment_unknown' : $this->paymentIdentifier, 'refund_id' => $prefix.'refund_'.$payout]];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runSync(array $options = []): array
    {
        $exit = Artisan::call('svc:finance:sync-processor-movements', [...['--provider' => 'square', '--apply' => true], ...$options, '--format' => 'json']);

        return [...json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR), 'exit' => $exit];
    }
}
