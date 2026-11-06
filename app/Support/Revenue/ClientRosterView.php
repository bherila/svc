<?php

declare(strict_types=1);

namespace App\Support\Revenue;

use Closure;

/**
 * The client roster as asked for (docs/ux-overhaul.md section 3): searched,
 * filtered and sorted, with the totals of the rows that remain. Pure and
 * database-free. It reorders and sums figures the revenue model already
 * produced (`ClientRevenue::toArray()`), so the browser only prints them and
 * a filtered roster's totals row is the server's, not a sum done in the page.
 */
final readonly class ClientRosterView
{
    /** @var list<string> */
    public const array SORTS = ['name', 'mrr', 'ytd', 'ttm', 'open', 'overdue'];

    /** @var list<string> */
    public const array FILTERS = ['all', 'active', 'ending_soon', 'over_retainer', 'overdue'];

    public const int MAX_SEARCH = 100;

    public function __construct(
        public string $search = '',
        public string $filter = 'all',
        public string $sort = 'name',
        public string $direction = 'asc',
    ) {}

    /**
     * From a query string. A value that is not one of the known ones takes
     * its default, so a mangled link shows the whole roster by name.
     *
     * @param  array<array-key, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $search = $input['q'] ?? '';
        $filter = $input['filter'] ?? '';
        $sort = $input['sort'] ?? '';

        return new self(
            search: is_string($search) ? mb_substr(trim($search), 0, self::MAX_SEARCH) : '',
            filter: is_string($filter) && in_array($filter, self::FILTERS, true) ? $filter : 'all',
            sort: is_string($sort) && in_array($sort, self::SORTS, true) ? $sort : 'name',
            direction: ($input['dir'] ?? null) === 'desc' ? 'desc' : 'asc',
        );
    }

    /** @return array{q: string, filter: string, sort: string, dir: string} */
    public function toArray(): array
    {
        return ['q' => $this->search, 'filter' => $this->filter, 'sort' => $this->sort, 'dir' => $this->direction];
    }

    /**
     * @param  list<array<string, mixed>>  $clients  `ClientRevenue::toArray()` rows
     * @param  Closure(string): string  $clientHref  client public id to its overview
     * @return array{clients: list<array<string, mixed>>, totals: array{mrr: int, ytd: int, ttm: int, open_amount: int, overdue_amount: int, overdue_count: int}}
     */
    public function apply(array $clients, Closure $clientHref): array
    {
        $rows = [];
        foreach ($clients as $client) {
            if ($this->search !== '' && mb_stripos($client['name'], $this->search) === false) {
                continue;
            }
            if (! $this->matchesFilter($client)) {
                continue;
            }
            $rows[] = $client;
        }

        usort($rows, fn (array $a, array $b): int => $this->compare($a, $b));

        $total = static fn (callable $field): int => array_sum(array_map($field, $rows));

        return [
            'clients' => array_map(static fn (array $client): array => [
                'client_id' => $client['client_id'],
                'name' => $client['name'],
                'is_active' => $client['is_active'],
                'href' => $clientHref($client['client_id']),
                'mrr' => $client['mrr'],
                'ytd' => $client['ytd'],
                'ttm' => ['amount' => $client['ttm']['amount'], 'series' => $client['ttm']['series']],
                'open_amount' => $client['open_amount'],
                'overdue_amount' => $client['overdue_amount'],
                'overdue_count' => $client['overdue_count'],
                'ending_soon' => $client['ending_soon'],
            ], $rows),
            'totals' => [
                'mrr' => $total(static fn (array $client): int => $client['mrr']),
                'ytd' => $total(static fn (array $client): int => $client['ytd']),
                'ttm' => $total(static fn (array $client): int => $client['ttm']['amount']),
                'open_amount' => $total(static fn (array $client): int => $client['open_amount']),
                'overdue_amount' => $total(static fn (array $client): int => $client['overdue_amount']),
                'overdue_count' => $total(static fn (array $client): int => $client['overdue_count']),
            ],
        ];
    }

    /**
     * Each client's retainer meter and whether it is over its retainer, by
     * client id: the part of the roster that needs the capacity ledgers,
     * which cost queries per agreement, so a screen asks for it after its
     * first paint.
     *
     * @param  list<array<string, mixed>>  $clients  `ClientRevenue::toArray()` rows read with their ledgers
     * @return array<string, array{status: string, minutes_used: int|null, minutes_included: int|null, over_retainer: bool}>
     */
    public static function healthByClient(array $clients): array
    {
        $health = [];
        foreach ($clients as $client) {
            $health[$client['client_id']] = [...self::health($client['agreements']), 'over_retainer' => $client['over_retainer']];
        }

        return $health;
    }

    /**
     * One meter for a client with possibly several agreements: the retainer
     * hours used and included this period, summed over the agreements whose
     * ledger was read. Where any ledger refused to build the meter is
     * withheld ("ledger_unreadable") rather than shown short; a client with
     * no agreement has "none", and one whose agreements grant no retainer
     * has "no_retainer".
     *
     * @param  list<array<string, mixed>>  $agreements  `AgreementHealth::toArray()` rows
     * @return array{status: string, minutes_used: int|null, minutes_included: int|null}
     */
    public static function health(array $agreements): array
    {
        $statuses = array_column($agreements, 'ledger_status');

        if ($agreements === []) {
            return ['status' => 'none', 'minutes_used' => null, 'minutes_included' => null];
        }
        if (in_array(AgreementHealth::LEDGER_UNREADABLE, $statuses, true)) {
            return ['status' => AgreementHealth::LEDGER_UNREADABLE, 'minutes_used' => null, 'minutes_included' => null];
        }

        $metered = array_filter($agreements, static fn (array $row): bool => $row['ledger_status'] === AgreementHealth::OK);
        if ($metered === []) {
            return ['status' => AgreementHealth::NO_RETAINER, 'minutes_used' => null, 'minutes_included' => null];
        }

        return [
            'status' => AgreementHealth::OK,
            'minutes_used' => (int) round(array_sum(array_column($metered, 'hours_used')) * 60),
            'minutes_included' => (int) round(array_sum(array_column($metered, 'hours_included')) * 60),
        ];
    }

    /** @param array<string, mixed> $client */
    private function matchesFilter(array $client): bool
    {
        return match ($this->filter) {
            'active' => $client['is_active'] === true,
            'ending_soon' => $client['ending_soon'] === true,
            'over_retainer' => $client['over_retainer'] === true,
            'overdue' => $client['overdue_count'] > 0,
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compare(array $a, array $b): int
    {
        $name = [mb_strtolower($a['name']), $a['client_id']] <=> [mb_strtolower($b['name']), $b['client_id']];
        if ($this->sort === 'name') {
            return $this->direction === 'desc' ? -$name : $name;
        }

        $key = fn (array $client): int => match ($this->sort) {
            'mrr' => $client['mrr'],
            'ytd' => $client['ytd'],
            'ttm' => $client['ttm']['amount'],
            'open' => $client['open_amount'],
            default => $client['overdue_amount'],
        };
        $order = $key($a) <=> $key($b);

        // Ties fall back to the name, ascending, whichever way the column runs.
        return ($this->direction === 'desc' ? -$order : $order) ?: $name;
    }
}
