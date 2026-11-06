<?php

namespace Tests\Unit\Revenue;

use App\Support\Revenue\AgreementHealth;
use App\Support\Revenue\ClientRosterView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientRosterViewTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $agreements
     * @return array<string, mixed>
     */
    private function client(string $id, string $name, int $mrr = 0, int $ytd = 0, int $ttm = 0, int $open = 0, int $overdue = 0, int $overdueCount = 0, bool $active = true, bool $ending = false, bool $over = false, array $agreements = []): array
    {
        return [
            'client_id' => $id, 'name' => $name, 'is_active' => $active, 'mrr' => $mrr, 'ytd' => $ytd,
            'ttm' => ['amount' => $ttm, 'month_to_date' => 0, 'series' => [['month' => '2026-10', 'amount' => $ttm]]],
            'open_amount' => $open, 'overdue_amount' => $overdue, 'overdue_count' => $overdueCount,
            'ending_soon' => $ending, 'over_retainer' => $over, 'agreements' => $agreements,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function roster(): array
    {
        return [
            $this->client('c1', 'bravo', mrr: 300, ytd: 10, ttm: 50, open: 5, overdue: 5, overdueCount: 1),
            $this->client('c2', 'Alpha', mrr: 100, ytd: 30, ttm: 20, open: 0, ending: true),
            $this->client('c3', 'Charlie', mrr: 200, ytd: 20, ttm: 40, open: 9, overdue: 0, active: false, over: true),
            $this->client('c4', 'alpha two', mrr: 100, ytd: 5, ttm: 10, open: 9, overdue: 9, overdueCount: 2),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function ids(array $rows): array
    {
        return array_column($rows, 'client_id');
    }

    private function view(array $input = []): array
    {
        return ClientRosterView::fromInput($input)->apply($this->roster(), fn (string $id): string => "/clients/{$id}");
    }

    public function test_the_default_is_the_whole_roster_by_name_ignoring_case(): void
    {
        $result = $this->view();

        $this->assertSame(['c2', 'c4', 'c1', 'c3'], $this->ids($result['clients']));
        $this->assertSame('/clients/c2', $result['clients'][0]['href']);
        $this->assertSame(['q' => '', 'filter' => 'all', 'sort' => 'name', 'dir' => 'asc'], ClientRosterView::fromInput([])->toArray());
    }

    public function test_a_row_carries_its_figures_and_not_the_ledger_derived_ones(): void
    {
        $row = $this->view()['clients'][0];

        $this->assertSame(['client_id', 'name', 'is_active', 'href', 'mrr', 'ytd', 'ttm', 'open_amount', 'overdue_amount', 'overdue_count', 'ending_soon'], array_keys($row));
        $this->assertSame(['amount', 'series'], array_keys($row['ttm']));
        $this->assertTrue($row['ending_soon']);
    }

    /** @return iterable<string, array<string, mixed>> */
    public static function sorts(): iterable
    {
        yield 'name asc' => [['sort' => 'name'], ['c2', 'c4', 'c1', 'c3']];
        yield 'name desc' => [['sort' => 'name', 'dir' => 'desc'], ['c3', 'c1', 'c4', 'c2']];
        yield 'mrr desc, ties by name' => [['sort' => 'mrr', 'dir' => 'desc'], ['c1', 'c3', 'c2', 'c4']];
        yield 'mrr asc, ties by name' => [['sort' => 'mrr'], ['c2', 'c4', 'c3', 'c1']];
        yield 'ytd desc' => [['sort' => 'ytd', 'dir' => 'desc'], ['c2', 'c3', 'c1', 'c4']];
        yield 'ttm desc' => [['sort' => 'ttm', 'dir' => 'desc'], ['c1', 'c3', 'c2', 'c4']];
        yield 'open desc, ties by name' => [['sort' => 'open', 'dir' => 'desc'], ['c4', 'c3', 'c1', 'c2']];
        yield 'overdue desc' => [['sort' => 'overdue', 'dir' => 'desc'], ['c4', 'c1', 'c2', 'c3']];
        yield 'overdue asc' => [['sort' => 'overdue'], ['c2', 'c3', 'c1', 'c4']];
        yield 'an unknown column falls back to name' => [['sort' => 'secret', 'dir' => 'sideways'], ['c2', 'c4', 'c1', 'c3']];
    }

    /**
     * @param  array<string, string>  $input
     * @param  list<string>  $expected
     */
    #[DataProvider('sorts')]
    public function test_every_column_sorts_both_ways_with_the_name_breaking_ties(array $input, array $expected): void
    {
        $this->assertSame($expected, $this->ids($this->view($input)['clients']));
    }

    /** @return iterable<string, array{array<string, string>, list<string>}> */
    public static function filters(): iterable
    {
        yield 'all' => [['filter' => 'all'], ['c2', 'c4', 'c1', 'c3']];
        yield 'active' => [['filter' => 'active'], ['c2', 'c4', 'c1']];
        yield 'ending soon' => [['filter' => 'ending_soon'], ['c2']];
        yield 'over retainer' => [['filter' => 'over_retainer'], ['c3']];
        yield 'has overdue invoices' => [['filter' => 'overdue'], ['c4', 'c1']];
        yield 'unknown' => [['filter' => 'dormant'], ['c2', 'c4', 'c1', 'c3']];
    }

    /**
     * @param  array<string, string>  $input
     * @param  list<string>  $expected
     */
    #[DataProvider('filters')]
    public function test_each_filter_keeps_exactly_its_rows(array $input, array $expected): void
    {
        $this->assertSame($expected, $this->ids($this->view($input)['clients']));
    }

    public function test_search_matches_a_part_of_the_name_without_regard_to_case(): void
    {
        $this->assertSame(['c2', 'c4'], $this->ids($this->view(['q' => 'ALPHA'])['clients']));
        $this->assertSame(['c4'], $this->ids($this->view(['q' => 'pha tw'])['clients']));
        $this->assertSame(['c2', 'c4'], $this->ids($this->view(['q' => '  alpha  '])['clients']), 'Surrounding space is not part of the search.');
        $this->assertSame([], $this->ids($this->view(['q' => 'zzz'])['clients']));
        $this->assertSame(4, count($this->view(['q' => ''])['clients']));
    }

    public function test_search_and_filter_combine(): void
    {
        $this->assertSame(['c4'], $this->ids($this->view(['q' => 'alpha', 'filter' => 'overdue'])['clients']));
    }

    public function test_the_search_is_capped_and_must_be_text(): void
    {
        $this->assertSame(100, mb_strlen(ClientRosterView::fromInput(['q' => str_repeat('é', 150)])->search));
        $this->assertSame('', ClientRosterView::fromInput(['q' => ['alpha']])->search);
        $this->assertSame('all', ClientRosterView::fromInput(['filter' => ['active']])->filter);
        $this->assertSame('name', ClientRosterView::fromInput(['sort' => ['mrr']])->sort);
    }

    public function test_the_totals_are_those_of_the_rows_that_remain(): void
    {
        $all = $this->view()['totals'];
        $this->assertSame(['mrr' => 700, 'ytd' => 65, 'ttm' => 120, 'open_amount' => 23, 'overdue_amount' => 14, 'overdue_count' => 3], $all);

        $overdue = $this->view(['filter' => 'overdue'])['totals'];
        $this->assertSame(['mrr' => 400, 'ytd' => 15, 'ttm' => 60, 'open_amount' => 14, 'overdue_amount' => 14, 'overdue_count' => 3], $overdue);

        $this->assertSame(['mrr' => 0, 'ytd' => 0, 'ttm' => 0, 'open_amount' => 0, 'overdue_amount' => 0, 'overdue_count' => 0], $this->view(['q' => 'nobody'])['totals']);
    }

    /** @return array<string, mixed> */
    private function health(string $status, ?float $used = null, ?float $included = null): array
    {
        return ['agreement_id' => 'a', 'ledger_status' => $status, 'hours_used' => $used, 'hours_included' => $included];
    }

    public function test_a_clients_meter_sums_the_agreements_whose_ledger_was_read(): void
    {
        $this->assertSame(
            ['status' => 'ok', 'minutes_used' => 270, 'minutes_included' => 1200],
            ClientRosterView::health([$this->health(AgreementHealth::OK, 2.5, 10.0), $this->health(AgreementHealth::OK, 2.0, 10.0), $this->health(AgreementHealth::NO_RETAINER)]),
        );
    }

    public function test_a_client_with_no_agreement_has_no_meter(): void
    {
        $this->assertSame(['status' => 'none', 'minutes_used' => null, 'minutes_included' => null], ClientRosterView::health([]));
    }

    public function test_a_client_whose_agreements_grant_no_retainer_has_none_to_meter(): void
    {
        $this->assertSame(['status' => 'no_retainer', 'minutes_used' => null, 'minutes_included' => null], ClientRosterView::health([$this->health(AgreementHealth::NO_RETAINER)]));
    }

    public function test_one_unreadable_ledger_withholds_the_whole_meter_rather_than_showing_it_short(): void
    {
        $this->assertSame(
            ['status' => 'ledger_unreadable', 'minutes_used' => null, 'minutes_included' => null],
            ClientRosterView::health([$this->health(AgreementHealth::OK, 2.0, 10.0), $this->health(AgreementHealth::LEDGER_UNREADABLE)]),
        );
    }

    public function test_health_is_keyed_by_client_with_whether_it_is_over_its_retainer(): void
    {
        $roster = [
            $this->client('c1', 'One', over: true, agreements: [$this->health(AgreementHealth::OK, 11.0, 10.0)]),
            $this->client('c2', 'Two'),
        ];

        $this->assertSame([
            'c1' => ['status' => 'ok', 'minutes_used' => 660, 'minutes_included' => 600, 'over_retainer' => true],
            'c2' => ['status' => 'none', 'minutes_used' => null, 'minutes_included' => null, 'over_retainer' => false],
        ], ClientRosterView::healthByClient($roster));
    }
}
