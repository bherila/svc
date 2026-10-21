<?php

namespace Tests\Feature\Finance;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ProcessorMovement;
use App\Models\Workspace;
use App\Services\Finance\SquareProcessorMovementSync;
use App\Services\Square\SquareRequestFailed;
use App\Support\Finance\ProcessorReadBudget;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Concerns\FakesSquare;
use Tests\TestCase;

final class SquareProcessorMovementSyncTest extends TestCase
{
    use FakesSquare;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $locations;

    /** @var array<string, mixed> */
    private array $payout;

    /** @var array<string, array<string, mixed>> */
    private array $pages;

    /** @var array<string, array<string, mixed>> */
    private array $refunds = [];

    /** @var array<string, array<string, mixed>> */
    private array $disputes = [];

    /** @var array<string, array<string, mixed>> */
    private array $discoveryPages = [];

    private bool $failSecondPage = false;

    private bool $failSource = false;

    /** @var list<int> */
    private array $rowsAtRead = [];

    /** @var list<int> */
    private array $transactionLevelsAtRead = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        $this->locations = [self::squareLocation()];
        $this->payout = ['id' => 'po_synthetic', 'location_id' => 'LOCFIXTURE01', 'arrival_date' => '2031-05-01'];
        $this->pages = ['' => ['payout_entries' => [$this->entry()]]];
        Http::fake(function (Request $request) {
            $this->assertSame('GET', $request->method());
            $this->rowsAtRead[] = ProcessorMovement::query()->count();
            $this->transactionLevelsAtRead[] = DB::transactionLevel();
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v2/locations') {
                return Http::response(['locations' => $this->locations]);
            }
            if ($path === '/v2/payouts') {
                return Http::response($this->discoveryPages[($request['location_id'] ?? '').'|'.($request['cursor'] ?? '')] ?? []);
            }
            if ($path === '/v2/payouts/po_synthetic') {
                return Http::response(['payout' => $this->payout]);
            }
            if ($path === '/v2/payouts/po_synthetic/payout-entries') {
                $cursor = $request['cursor'] ?? '';
                if ($cursor !== '' && $this->failSecondPage) {
                    return Http::response([], 503);
                }

                return Http::response($this->pages[$cursor] ?? []);
            }
            if (is_string($path) && str_starts_with($path, '/v2/refunds/')) {
                return $this->failSource ? Http::response([], 503) : Http::response(['refund' => $this->refunds[basename($path)] ?? null]);
            }
            if (is_string($path) && str_starts_with($path, '/v2/disputes/')) {
                return $this->failSource ? Http::response([], 503) : Http::response(['dispute' => $this->disputes[basename($path)] ?? null]);
            }
            $this->fail('An unexpected Square request was issued.');
        });
    }

    public function test_preview_is_default_then_an_applied_entry_replays_without_duplicate_or_payment_changes(): void
    {
        [$workspace, $payment] = $this->fixture();
        $before = $payment->refresh()->getAttributes();
        $sync = app(SquareProcessorMovementSync::class);
        $preview = $sync->payout($workspace, 'po_synthetic');
        $this->assertSame(['entries_read' => 1, 'created' => 1, 'unchanged' => 0, 'enriched' => 0, 'conflict' => 0, 'deferred' => 0, 'ignored' => 0], $preview);
        $this->assertDatabaseCount('processor_movements', 0);
        $this->assertSame($preview, $sync->payout($workspace, 'po_synthetic', true));
        $stored = ProcessorMovement::query()->sole();
        $this->assertSame('square:sandbox:MERCHANTFIXTURE', $stored->account_key);
        $this->assertSame('2030-01-02', $stored->occurred_on->toDateString());
        $this->assertSame('square_effective_at', $stored->date_basis);
        $this->assertSame($before, $payment->fresh()?->getAttributes());
        $this->assertDatabaseCount('processor_payouts', 0);
        $this->assertDatabaseCount('payment_reconciliations', 0);
        $replay = $sync->payout($workspace, 'po_synthetic', true);
        $this->assertSame([0, 1], [$replay['created'], $replay['unchanged']]);
        $this->assertSame($stored->getAttributes(), $stored->fresh()?->getAttributes());
    }

    public function test_paginated_debits_credits_and_fees_are_read_completely_before_writes(): void
    {
        [$workspace] = $this->fixture();
        $refundCredit = $this->entry('poe_refund_credit', 'REFUND', 100);
        $disputeDebit = $this->entry('poe_dispute_debit', 'OPEN_DISPUTE', -100);
        $disputeCredit = $this->entry('poe_dispute_credit', 'DISPUTE', 100);
        $fee = $this->entry('poe_fee', 'FEE', -5);
        $this->pages = ['' => ['payout_entries' => [$this->entry(), $refundCredit], 'cursor' => 'cursor_next'],
            'cursor_next' => ['payout_entries' => [$disputeDebit, $disputeCredit, $fee, ['id' => 'poe_charge', 'payout_id' => 'po_synthetic', 'type' => 'CHARGE']]]];
        $counts = app(SquareProcessorMovementSync::class)->payout($workspace, 'po_synthetic', true);
        $this->assertSame([6, 5, 1], [$counts['entries_read'], $counts['created'], $counts['ignored']]);
        $this->assertSame([0, 0, 0, 0], $this->rowsAtRead);
        $this->assertSame([1, 1, 1, 1], $this->transactionLevelsAtRead);
        $this->assertSame(['refund', 'refund_reversal', 'dispute_debit', 'dispute_credit', 'fee'], ProcessorMovement::query()->orderBy('id')->get()->map(static fn (ProcessorMovement $row): string => $row->kind->value)->all());
        $this->assertSame([-100, 100, -100, 100, 0], ProcessorMovement::query()->orderBy('id')->pluck('principal_minor')->all());
    }

    public function test_missing_payment_ids_are_resolved_from_the_exact_refund_and_dispute(): void
    {
        [$workspace] = $this->fixture();
        $refund = $this->entry();
        unset($refund['type_refund_details']['payment_id']);
        $dispute = $this->entry('poe_dispute', 'OPEN_DISPUTE', -100);
        unset($dispute['type_open_dispute_details']['payment_id']);
        $this->pages = ['' => ['payout_entries' => [$refund, $dispute]]];
        $this->refunds = ['refund_synthetic' => ['id' => 'refund_synthetic', 'payment_id' => 'payment_synthetic', 'status' => 'FAILED']];
        $this->disputes = ['dispute_synthetic' => ['id' => 'dispute_synthetic', 'disputed_payment' => ['payment_id' => 'payment_synthetic'], 'state' => 'WON']];
        $counts = app(SquareProcessorMovementSync::class)->payout($workspace, 'po_synthetic', true);
        $this->assertSame([2, 0], [$counts['created'], $counts['deferred']]);
        Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/v2/refunds/refund_synthetic'));
        Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/v2/disputes/dispute_synthetic'));
        $this->assertSame([0, 0, 0, 0, 0], $this->rowsAtRead);
    }

    public function test_unknown_foreign_and_unreadable_rows_report_only_fixed_deferred_counts(): void
    {
        [$workspace] = $this->fixture();
        [, $foreign] = $this->fixture(false, 'payment_foreign');
        $foreignEntry = $this->entry('poe_foreign');
        $foreignEntry['type_refund_details']['payment_id'] = $foreign->provider_payment_identifier;
        $unknown = $this->entry('poe_unknown');
        $unknown['type_refund_details']['payment_id'] = 'payment_unknown';
        $bad = $this->entry('poe_bad');
        $bad['type'] = 'SYNTHETIC_UNREADABLE';
        $this->pages = ['' => ['payout_entries' => [$foreignEntry, $unknown, $bad]]];
        $counts = app(SquareProcessorMovementSync::class)->payout($workspace, 'po_synthetic', true);
        $this->assertSame([0, 3], [$counts['created'], $counts['deferred']]);
        $this->assertDatabaseCount('processor_movements', 0);
        foreach ($counts as $count) {
            $this->assertIsInt($count);
        }
        $this->assertStringNotContainsString('payment_', json_encode($counts, JSON_THROW_ON_ERROR));
    }

    public function test_a_payment_created_after_the_first_read_is_recovered_by_rereading_the_payout(): void
    {
        [$workspace, $payment] = $this->fixture();
        $this->pages['']['payout_entries'][0]['type_refund_details']['payment_id'] = 'payment_late';
        $sync = app(SquareProcessorMovementSync::class);
        $this->assertSame(1, $sync->payout($workspace, 'po_synthetic', true)['deferred']);
        $this->assertDatabaseCount('processor_movements', 0);
        $copy = $payment->replicate();
        $copy->public_id = (string) Str::uuid();
        $copy->provider_payment_identifier = 'payment_late';
        $copy->save();
        $this->assertSame(1, $sync->payout($workspace, 'po_synthetic', true)['created']);
        $this->assertSame($copy->id, ProcessorMovement::query()->sole()->client_invoice_payment_id);
    }

    public function test_resolved_source_ids_cannot_cross_a_workspace_or_change_the_entry_reference(): void
    {
        [$workspace] = $this->fixture();
        [, $foreign] = $this->fixture(false, 'payment_foreign');
        $foreignEntry = $this->entry();
        unset($foreignEntry['type_refund_details']['payment_id']);
        $wrongReference = $this->entry('poe_wrong_source');
        $wrongReference['type_refund_details'] = ['refund_id' => 'refund_requested'];
        $this->pages = ['' => ['payout_entries' => [$foreignEntry, $wrongReference]]];
        $this->refunds = ['refund_synthetic' => ['id' => 'refund_synthetic', 'payment_id' => $foreign->provider_payment_identifier],
            'refund_requested' => ['id' => 'refund_not_requested', 'payment_id' => 'payment_synthetic']];
        $counts = app(SquareProcessorMovementSync::class)->payout($workspace, 'po_synthetic', true);
        $this->assertSame([0, 2], [$counts['created'], $counts['deferred']]);
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_a_foreign_workspace_is_refused_before_any_provider_read(): void
    {
        $this->fixture();
        [$foreign] = $this->fixture(false, 'payment_foreign');
        try {
            app(SquareProcessorMovementSync::class)->payout($foreign, 'po_synthetic', true);
            $this->fail('A foreign workspace was read.');
        } catch (SquareRequestFailed $failure) {
            $this->assertSame(404, $failure->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_a_late_page_failure_cannot_partially_write_the_first_page(): void
    {
        [$workspace] = $this->fixture();
        $this->pages['']['cursor'] = 'cursor_next';
        $this->failSecondPage = true;
        try {
            app(SquareProcessorMovementSync::class)->payout($workspace, 'po_synthetic', true);
            $this->fail('The failed page was ignored.');
        } catch (SquareRequestFailed $failure) {
            $this->assertSame(503, $failure->getStatusCode());
        }
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_a_late_source_read_failure_cannot_partially_write_direct_entries(): void
    {
        [$workspace] = $this->fixture();
        $unbound = $this->entry('poe_unbound');
        unset($unbound['type_refund_details']['payment_id']);
        $this->pages['']['payout_entries'][] = $unbound;
        $this->failSource = true;
        try {
            app(SquareProcessorMovementSync::class)->payout($workspace, 'po_synthetic', true);
            $this->fail('The failed source read was ignored.');
        } catch (SquareRequestFailed $failure) {
            $this->assertSame(503, $failure->getStatusCode());
        }
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_duplicate_cursors_and_foreign_payout_members_refuse_all_writes(): void
    {
        [$workspace] = $this->fixture();
        $this->pages['']['cursor'] = 'cursor_cycle';
        $this->pages['cursor_cycle'] = ['payout_entries' => [], 'cursor' => 'cursor_cycle'];
        try {
            app(SquareProcessorMovementSync::class)->payout($workspace, 'po_synthetic', true);
            $this->fail('A cursor cycle was accepted.');
        } catch (SquareRequestFailed $failure) {
            $this->assertSame(502, $failure->getStatusCode());
        }
        $this->assertDatabaseCount('processor_movements', 0);
        $this->pages = ['' => ['payout_entries' => [array_replace($this->entry(), ['payout_id' => 'po_foreign'])]]];
        $this->expectException(SquareRequestFailed::class);
        app(SquareProcessorMovementSync::class)->payout($workspace, 'po_synthetic', true);
    }

    public function test_a_wrong_payout_or_unowned_location_is_not_used(): void
    {
        [$workspace] = $this->fixture();
        foreach ([['id' => 'po_foreign', 'location_id' => 'LOCFIXTURE01'], ['id' => 'po_synthetic', 'location_id' => 'LOCATION_FOREIGN']] as $payout) {
            $this->payout = $payout;
            try {
                app(SquareProcessorMovementSync::class)->payout($workspace, 'po_synthetic', true);
                $this->fail('An unproved payout was accepted.');
            } catch (SquareRequestFailed $failure) {
                $this->assertSame(502, $failure->getStatusCode());
            }
        }
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_missing_or_inconsistent_merchant_identity_cannot_be_replaced_by_a_token_hash(): void
    {
        [$workspace] = $this->fixture();
        unset($this->locations[0]['merchant_id']);
        $this->expectException(SquareRequestFailed::class);
        app(SquareProcessorMovementSync::class)->payout($workspace, 'po_synthetic', true);
    }

    public function test_credential_rotation_keeps_the_account_namespace_and_replay_identity(): void
    {
        [$workspace] = $this->fixture();
        $sync = app(SquareProcessorMovementSync::class);
        $sync->payout($workspace, 'po_synthetic', true);
        config(['services.square.access_token' => 'synthetic_rotated_token']);
        $this->assertSame(1, $sync->payout($workspace, 'po_synthetic', true)['unchanged']);
        $this->assertDatabaseCount('processor_movements', 1);
    }

    public function test_excess_rows_in_a_page_and_duplicate_entries_are_refused(): void
    {
        [$workspace] = $this->fixture();
        foreach ([array_fill(0, 101, $this->entry()), [$this->entry(), $this->entry()]] as $entries) {
            $this->pages = ['' => ['payout_entries' => $entries]];
            try {
                app(SquareProcessorMovementSync::class)->payout($workspace, 'po_synthetic', true);
                $this->fail('An over-budget or duplicate entry page was accepted.');
            } catch (SquareRequestFailed $failure) {
                $this->assertSame(502, $failure->getStatusCode());
            }
        }
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_a_shared_budget_exhausts_before_the_next_provider_call_and_writes_nothing(): void
    {
        [$workspace] = $this->fixture();
        $budget = new ProcessorReadBudget(2);
        try {
            app(SquareProcessorMovementSync::class)->payout($workspace, 'po_synthetic', true, $budget);
            $this->fail('The shared read budget was ignored.');
        } catch (DomainException $exception) {
            $this->assertSame('The processor read budget is exhausted; continue the sync in another run.', $exception->getMessage());
        }
        $this->assertSame(2, $budget->used());
        Http::assertSentCount(2);
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_source_lookup_budget_exhaustion_is_not_swallowed_as_a_deferred_record(): void
    {
        [$workspace] = $this->fixture();
        $unbound = $this->entry('poe_unbound');
        unset($unbound['type_refund_details']['payment_id']);
        $this->pages['']['payout_entries'][] = $unbound;
        $budget = new ProcessorReadBudget(3);
        try {
            app(SquareProcessorMovementSync::class)->payout($workspace, 'po_synthetic', true, $budget);
            $this->fail('Source lookup silently completed after exhausting its budget.');
        } catch (DomainException $exception) {
            $this->assertSame('The processor read budget is exhausted; continue the sync in another run.', $exception->getMessage());
        }
        Http::assertSentCount(3);
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_an_explicitly_null_entry_list_cannot_be_reported_as_an_empty_payout(): void
    {
        [$workspace] = $this->fixture();
        $this->pages = ['' => ['payout_entries' => null]];
        $this->expectException(SquareRequestFailed::class);
        app(SquareProcessorMovementSync::class)->payout($workspace, 'po_synthetic', true);
    }

    public function test_a_hundred_page_ceiling_cannot_be_reported_as_complete(): void
    {
        [$workspace] = $this->fixture();
        $this->pages = [];
        for ($page = 0; $page < 100; $page++) {
            $this->pages[$page === 0 ? '' : 'cursor_'.$page] = ['payout_entries' => [], 'cursor' => 'cursor_'.($page + 1)];
        }
        try {
            app(SquareProcessorMovementSync::class)->payout($workspace, 'po_synthetic', true);
            $this->fail('A truncated payout was reported complete.');
        } catch (SquareRequestFailed $failure) {
            $this->assertSame(502, $failure->getStatusCode());
        }
        Http::assertSentCount(102);
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_inconsistent_merchants_and_too_many_locations_are_refused(): void
    {
        [$workspace] = $this->fixture();
        $cases = [[self::squareLocation(), array_replace(self::squareLocation('LOCFIXTURE02'), ['merchant_id' => 'MERCHANTOTHER'])],
            array_map(static fn (int $id): array => self::squareLocation('LOCATION'.$id), range(1, 101))];
        foreach ($cases as $locations) {
            $this->locations = $locations;
            try {
                app(SquareProcessorMovementSync::class)->payout($workspace, 'po_synthetic', true);
                $this->fail('An unproved merchant/location inventory was accepted.');
            } catch (SquareRequestFailed $failure) {
                $this->assertSame(502, $failure->getStatusCode());
            }
        }
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_changed_immutable_entries_are_counted_as_conflicts_and_held(): void
    {
        [$workspace] = $this->fixture();
        $sync = app(SquareProcessorMovementSync::class);
        $sync->payout($workspace, 'po_synthetic', true);
        $this->pages['']['payout_entries'] = [$this->entry(amount: -101)];
        $preview = $sync->payout($workspace, 'po_synthetic');
        $this->assertSame(1, $preview['conflict']);
        $this->assertFalse(ProcessorMovement::query()->sole()->needs_review);
        $this->assertSame(1, $sync->payout($workspace, 'po_synthetic', true)['conflict']);
        $this->assertTrue(ProcessorMovement::query()->sole()->needs_review);
        $this->assertSame(-100, ProcessorMovement::query()->sole()->principal_minor);
    }

    public function test_discovery_uses_one_namespace_and_fixed_explicit_time_bounds_across_pages(): void
    {
        [$workspace] = $this->fixture();
        $this->locations[] = self::squareLocation('LOCFIXTURE02');
        $this->discoveryPages = ['LOCFIXTURE01|' => ['payouts' => [], 'cursor' => 'discovery_next'],
            'LOCFIXTURE01|discovery_next' => ['payouts' => [$this->payout]], 'LOCFIXTURE02|' => ['payouts' => []]];
        $since = CarbonImmutable::parse('2028-01-01T01:00:00+01:00');
        $until = CarbonImmutable::parse('2031-01-01T00:00:00Z');
        $sync = app(SquareProcessorMovementSync::class);
        $counts = iterator_to_array($sync->payouts($workspace, $since, until: $until), false);
        $this->assertCount(1, $counts);
        $this->assertSame(1, $counts[0]['created']);
        $this->assertDatabaseCount('processor_movements', 0);
        $reads = Http::recorded(static fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/v2/payouts');
        $this->assertCount(3, $reads);
        foreach ($reads as [$request]) {
            $this->assertSame('2028-01-01T00:00:00Z', $request['begin_time']);
            $this->assertSame('2031-01-01T00:00:00Z', $request['end_time']);
            $this->assertSame('DESC', $request['sort_order']);
            $this->assertSame('100', (string) $request['limit']);
        }
        $this->assertCount(1, Http::recorded(static fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/v2/locations'));
        $this->assertSame(1, iterator_to_array($sync->payouts($workspace, $since, true, until: $until), false)[0]['created']);
        $this->assertSame(1, iterator_to_array($sync->payouts($workspace, $since, true, until: $until), false)[0]['unchanged']);
        $this->assertDatabaseCount('processor_movements', 1);
    }

    public function test_discovery_cannot_accept_a_payout_from_another_location(): void
    {
        [$workspace] = $this->fixture();
        $this->discoveryPages = ['LOCFIXTURE01|' => ['payouts' => [array_replace($this->payout, ['location_id' => 'LOCATIONOTHER'])]]];
        $this->expectException(SquareRequestFailed::class);
        iterator_to_array(app(SquareProcessorMovementSync::class)->payouts($workspace, CarbonImmutable::parse('2020-01-01'), true));
    }

    public function test_discovery_rejects_duplicate_cursors_and_explicitly_null_lists(): void
    {
        [$workspace] = $this->fixture();
        foreach ([['LOCFIXTURE01|' => ['payouts' => [], 'cursor' => 'repeat'], 'LOCFIXTURE01|repeat' => ['payouts' => [], 'cursor' => 'repeat']],
            ['LOCFIXTURE01|' => ['payouts' => null]]] as $pages) {
            $this->discoveryPages = $pages;
            try {
                iterator_to_array(app(SquareProcessorMovementSync::class)->payouts($workspace, CarbonImmutable::parse('2020-01-01'), true));
                $this->fail('Incomplete discovery was reported complete.');
            } catch (SquareRequestFailed $failure) {
                $this->assertSame(502, $failure->getStatusCode());
            }
        }
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_discovery_rejects_duplicate_payouts_in_a_page_before_applying_them(): void
    {
        [$workspace] = $this->fixture();
        $this->discoveryPages = ['LOCFIXTURE01|' => ['payouts' => [$this->payout, $this->payout]]];
        try {
            iterator_to_array(app(SquareProcessorMovementSync::class)->payouts($workspace, CarbonImmutable::parse('2020-01-01'), true));
            $this->fail('Duplicate payout membership was accepted.');
        } catch (SquareRequestFailed $failure) {
            $this->assertSame(502, $failure->getStatusCode());
        }
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_discovery_budget_exhaustion_keeps_completed_payouts_idempotent_but_never_reports_completion(): void
    {
        [$workspace] = $this->fixture();
        $this->discoveryPages = ['LOCFIXTURE01|' => ['payouts' => [$this->payout], 'cursor' => 'later_page']];
        $budget = new ProcessorReadBudget(4);
        $stream = app(SquareProcessorMovementSync::class)->payouts($workspace, CarbonImmutable::parse('2020-01-01'), true, $budget);
        try {
            iterator_to_array($stream);
            $this->fail('Discovery silently completed after the read budget was exhausted.');
        } catch (DomainException $exception) {
            $this->assertSame('The processor read budget is exhausted; continue the sync in another run.', $exception->getMessage());
        }
        $this->assertDatabaseCount('processor_movements', 1);
        Http::assertSentCount(4);
        $this->assertSame(1, app(SquareProcessorMovementSync::class)->payout($workspace, 'po_synthetic', true)['unchanged']);
    }

    public function test_discovery_detail_must_keep_the_listed_location_even_when_both_locations_are_owned(): void
    {
        [$workspace] = $this->fixture();
        $this->locations[] = self::squareLocation('LOCFIXTURE02');
        $this->discoveryPages = ['LOCFIXTURE01|' => ['payouts' => [$this->payout]]];
        $this->payout['location_id'] = 'LOCFIXTURE02';
        $results = iterator_to_array(app(SquareProcessorMovementSync::class)->payouts($workspace, CarbonImmutable::parse('2020-01-01'), true), false);
        $this->assertCount(1, $results);
        $this->assertSame(1, $results[0]['deferred']);
        $this->assertSame(0, $results[0]['created']);
        $this->assertDatabaseCount('processor_movements', 0);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/po_synthetic/payout-entries'));
        Http::assertSentCount(4);
    }

    public function test_discovery_page_limit_cannot_report_a_truncated_inventory_as_complete(): void
    {
        [$workspace] = $this->fixture();
        for ($page = 0; $page < 100; $page++) {
            $cursor = $page === 0 ? '' : 'page_'.$page;
            $this->discoveryPages['LOCFIXTURE01|'.$cursor] = ['payouts' => [], 'cursor' => 'page_'.($page + 1)];
        }
        try {
            iterator_to_array(app(SquareProcessorMovementSync::class)->payouts($workspace, CarbonImmutable::parse('2020-01-01'), true));
            $this->fail('An incomplete payout inventory was reported complete.');
        } catch (SquareRequestFailed $failure) {
            $this->assertSame(502, $failure->getStatusCode());
        }
        Http::assertSentCount(101);
        $this->assertDatabaseCount('processor_movements', 0);
    }

    public function test_discovery_refuses_reversed_time_bounds_before_provider_reads(): void
    {
        [$workspace] = $this->fixture();
        try {
            iterator_to_array(app(SquareProcessorMovementSync::class)->payouts($workspace, CarbonImmutable::parse('2030-01-02'), until: CarbonImmutable::parse('2030-01-01')));
            $this->fail('Reversed discovery bounds were accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('The payout discovery time range is invalid.', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    /** @return array{Workspace, ClientInvoicePayment} */
    private function fixture(bool $connect = true, string $identifier = 'payment_synthetic'): array
    {
        $suffix = (string) Str::uuid();
        $workspace = Workspace::query()->create(['name' => 'Synthetic workspace', 'slug' => 'synthetic-'.$suffix, 'timezone' => 'UTC']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-'.$suffix]);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYN-'.$suffix, 'currency' => 'USD']);
        $payment = ClientInvoicePayment::query()->create(['workspace_id' => $workspace->id, 'client_invoice_id' => $invoice->id, 'status' => 'succeeded',
            'amount' => 1000, 'currency' => 'USD', 'method' => 'square', 'provider' => 'square', 'provider_payment_identifier' => $identifier]);
        if ($connect) {
            $this->connectSquare($workspace, ['max_attempts' => 1]);
        }

        return [$workspace, $payment];
    }

    /** @return array<string, mixed> */
    private function entry(string $id = 'poe_synthetic', string $type = 'REFUND', int $amount = -100): array
    {
        $details = ['payment_id' => 'payment_synthetic'];
        if ($type === 'REFUND') {
            $details['refund_id'] = 'refund_synthetic';
        } elseif (in_array($type, ['OPEN_DISPUTE', 'DISPUTE'], true)) {
            $details['dispute_id'] = 'dispute_synthetic';
        }

        return ['id' => $id, 'payout_id' => 'po_synthetic', 'type' => $type, 'effective_at' => '2030-01-02T12:00:00Z',
            'gross_amount_money' => ['amount' => $amount, 'currency' => 'USD'], 'fee_amount_money' => ['amount' => 0, 'currency' => 'USD'],
            'net_amount_money' => ['amount' => $amount, 'currency' => 'USD'], 'type_'.strtolower($type).'_details' => $details];
    }
}
