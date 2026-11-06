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

    public function test_the_search_keeps_its_first_character_and_is_cut_only_at_the_cap(): void
    {
        $this->assertSame('xyz', ClientRosterView::fromInput(['q' => 'xyz'])->search);
        $this->assertSame('abc', ClientRosterView::fromInput(['q' => '  abc  '])->search);
        $this->assertSame(str_repeat('a', 100), ClientRosterView::fromInput(['q' => str_repeat('a', 101)])->search);
        $this->assertSame('x'.str_repeat('a', 99), ClientRosterView::fromInput(['q' => 'x'.str_repeat('a', 120)])->search);
    }

    public function test_search_ignores_case_in_names_that_are_not_ascii(): void
    {
        $roster = [$this->client('c1', 'Émile Société'), $this->client('c2', 'Plain')];
        $names = fn (string $q): array => array_column(ClientRosterView::fromInput(['q' => $q])->apply($roster, fn (string $id): string => $id)['clients'], 'client_id');

        $this->assertSame(['c1'], $names('émile'));
        $this->assertSame(['c1'], $names('SOCIÉTÉ'));
        $this->assertSame([], $names('ÉMILE X'));
    }

    public function test_names_sort_by_their_lowercase_in_any_script_whichever_order_they_arrive(): void
    {
        $upper = $this->client('c1', 'Éz');
        $lower = $this->client('c2', 'éa');
        $sort = fn (array $roster, string $dir): array => array_column(ClientRosterView::fromInput(['dir' => $dir])->apply($roster, fn (string $id): string => $id)['clients'], 'client_id');

        foreach ([[$upper, $lower], [$lower, $upper]] as $roster) {
            $this->assertSame(['c2', 'c1'], $sort($roster, 'asc'));
            $this->assertSame(['c1', 'c2'], $sort($roster, 'desc'));
        }
    }

    /** @return iterable<string, array{float, float, int, int}> */
    public static function meterRounding(): iterable
    {
        yield 'rounds up past the half minute' => [0.0125, 0.0125, 1, 1];
        yield 'rounds down below the half minute' => [0.0075, 0.0075, 0, 0];
        yield 'used and included are rounded on their own' => [0.0125, 0.0075, 1, 0];
        yield 'and the other way about' => [0.0075, 0.0125, 0, 1];
    }

    #[DataProvider('meterRounding')]
    public function test_the_meter_rounds_hours_to_the_nearest_whole_minute(float $used, float $included, int $minutesUsed, int $minutesIncluded): void
    {
        $meter = ClientRosterView::health([$this->health(AgreementHealth::OK, $used, $included)]);

        $this->assertSame($minutesUsed, $meter['minutes_used']);
        $this->assertSame($minutesIncluded, $meter['minutes_included']);
    }

    public function test_the_meter_sums_hours_across_agreements_before_rounding(): void
    {
        $meter = ClientRosterView::health([$this->health(AgreementHealth::OK, 0.0075, 0.0075), $this->health(AgreementHealth::OK, 0.0075, 0.0075)]);

        // 0.015 h is 0.9 of a minute: 1, where rounding each part first gives 0.
        $this->assertSame(1, $meter['minutes_used']);
        $this->assertSame(1, $meter['minutes_included']);
    }

    public function test_a_meter_over_agreements_listed_with_gaps_still_sums_the_metered_ones(): void
    {
        $meter = ClientRosterView::health([
            5 => $this->health(AgreementHealth::NO_RETAINER),
            9 => $this->health(AgreementHealth::OK, 1.0, 2.0),
        ]);

        $this->assertSame(['status' => 'ok', 'minutes_used' => 60, 'minutes_included' => 120], $meter);
    }
}
