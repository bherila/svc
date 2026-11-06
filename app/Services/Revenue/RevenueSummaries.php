<?php

namespace App\Services\Revenue;

use App\Services\Billing\Balances\MonthSummary;
use App\Support\Revenue\AgreementChange;
use App\Support\Revenue\BandAmounts;
use App\Support\Revenue\ClientRevenue;
use App\Support\Revenue\OpenInvoice;
use App\Support\Revenue\ProjectedAgreement;
use App\Support\Revenue\ProjectionInputs;
use App\Support\Revenue\RevenueActualsInputs;
use App\Support\Revenue\RevenueAssumptions;
use App\Support\Revenue\RevenueClient;
use App\Support\Revenue\RevenueProjection;
use App\Support\Revenue\RevenueSummary;
use Carbon\CarbonImmutable;

/**
 * The dashboard, roster and planning figures over what the repositories read
 * and the projector computed. Database-free; REST, MCP and the dashboard all
 * read these, so the three cannot disagree.
 *
 * Windows, all inclusive and in the workspace's calendar:
 * - year to date: January 1 to the day read, against the same days a year
 *   earlier;
 * - trailing twelve months: the twelve full months before the current one
 *   plus the current month to date, against the same span a year earlier
 *   (the two overlap by the opening part of the month a year ago: a window
 *   longer than a year cannot be compared to its predecessor otherwise);
 * - next twelve months: the twelve full months after the current one.
 */
final class RevenueSummaries
{
    public const string BAND_ALL = 'all';

    /** @var list<string> */
    public const array BANDS = [self::BAND_ALL, 'recurring', 'overage_and_other', 'expenses'];

    public function __construct(private readonly AgreementHealthCalculator $health = new AgreementHealthCalculator) {}

    public function summary(RevenueActualsInputs $actuals, ProjectionInputs $inputs, RevenueProjection $projection, RevenueAssumptions $assumptions, ?int $clientId = null): RevenueSummary
    {
        $asOf = $assumptions->asOf->startOfDay();
        $amounts = $actuals->amounts($assumptions->basis);
        $expenses = $assumptions->includeExpenses;
        $sum = static fn (CarbonImmutable $from, CarbonImmutable $to): int => RevenueActuals::between($amounts, $from, $to, $expenses, $clientId);

        $ytdFrom = $asOf->startOfYear();
        $ttmFrom = self::ttmFrom($asOf);
        $yearAgo = $asOf->subYearNoOverflow();
        $ytd = ['from' => $ytdFrom, 'to' => $asOf, 'amount' => $sum($ytdFrom, $asOf),
            'prior_from' => $ytdFrom->subYearNoOverflow(), 'prior_to' => $yearAgo, 'prior_amount' => $sum($ytdFrom->subYearNoOverflow(), $yearAgo)];
        $ttm = ['from' => $ttmFrom, 'to' => $asOf, 'amount' => $sum($ttmFrom, $asOf),
            'prior_from' => $ttmFrom->subYearNoOverflow(), 'prior_to' => $yearAgo, 'prior_amount' => $sum($ttmFrom->subYearNoOverflow(), $yearAgo)];

        $future = array_slice($projection->months, 1);
        $current = $projection->months[0];
        $currentBands = RevenueActuals::month(RevenueActuals::monthly($amounts), $current, $clientId);

        $open = array_values(array_filter($actuals->openInvoices, static fn (OpenInvoice $invoice): bool => $clientId === null || $invoice->clientId === $clientId));
        $overdue = array_values(array_filter($open, static fn (OpenInvoice $invoice): bool => $invoice->isOverdueOn($asOf)));
        $balance = static fn (array $invoices): int => array_sum(array_map(static fn (OpenInvoice $invoice): int => $invoice->balance, $invoices));

        $changes = $this->changes($clientId === null ? $inputs : $inputs->forClient($clientId));

        return new RevenueSummary(
            $assumptions,
            $ytd,
            $ttm,
            [
                'from' => $future[0].'-01',
                'to' => CarbonImmutable::parse($future[count($future) - 1].'-01')->endOfMonth()->toDateString(),
                'contracted' => array_sum(array_map(static fn (string $month): int => $projection->contracted($month, $clientId), $future)),
                'projected_overage' => array_sum(array_map(static fn (string $month): int => $projection->projectedOverage($month, $clientId), $future)),
            ],
            [
                'month' => $current,
                'actual' => $currentBands->total($expenses),
                'remaining_contracted' => $projection->contracted($current, $clientId),
                'projected_overage' => $projection->projectedOverage($current, $clientId),
            ],
            ['outstanding' => $balance($open), 'invoice_count' => count($open), 'overdue_amount' => $balance($overdue), 'overdue_count' => count($overdue)],
            $changes['ending'],
            $changes['starting'],
        );
    }

    /**
     * Agreements ending and starting in the next twelve months, soonest first.
     *
     * @return array{ending: list<AgreementChange>, starting: list<AgreementChange>}
     */
    public function changes(ProjectionInputs $inputs): array
    {
        $asOf = $inputs->asOf->startOfDay();
        $horizon = $asOf->addMonthsNoOverflow(RevenueAssumptions::PROJECTION_MONTHS);
        $ending = [];
        $starting = [];
        foreach ($inputs->agreements as $agreement) {
            if (! $agreement->billsOnARecurringCadence()) {
                continue;
            }
            $end = $agreement->effectiveEnd();
            if ($end !== null && $end->gt($asOf) && $end->lte($horizon) && $agreement->startsOn->lte($end)) {
                $ending[] = new AgreementChange(AgreementChange::ENDING, $agreement, $end, $agreement->monthlyRecurringOn($end));
            }
            if ($agreement->startsOn->gt($asOf) && $agreement->startsOn->lte($horizon)) {
                $starting[] = new AgreementChange(AgreementChange::STARTING, $agreement, $agreement->startsOn, $agreement->monthlyRecurringOn($agreement->startsOn));
            }
        }
        $soonest = static fn (AgreementChange $a, AgreementChange $b): int => [$a->date->toDateString(), $a->agreement->publicId] <=> [$b->date->toDateString(), $b->agreement->publicId];
        usort($ending, $soonest);
        usort($starting, $soonest);

        return ['ending' => $ending, 'starting' => $starting];
    }

    /**
     * The client roster, by name, with its totals.
     *
     * @param  array<int, list<MonthSummary>|null>  $ledgers  by agreement id, for the agreements in force; null where the ledger refused
     * @return array{clients: list<ClientRevenue>, totals: array{mrr: int, ytd: int, ttm: int, open_amount: int, overdue_amount: int, overdue_count: int}}
     */
    public function clients(RevenueActualsInputs $actuals, ProjectionInputs $inputs, RevenueAssumptions $assumptions, array $ledgers): array
    {
        $asOf = $assumptions->asOf->startOfDay();
        $amounts = $actuals->amounts($assumptions->basis);
        $expenses = $assumptions->includeExpenses;
        $monthly = RevenueActuals::monthly($amounts);
        $ttmFrom = self::ttmFrom($asOf);
        $seriesMonths = [];
        for ($back = 12; $back >= 1; $back--) {
            $seriesMonths[] = $asOf->startOfMonth()->subMonthsNoOverflow($back)->format('Y-m');
        }

        $rows = [];
        foreach ($actuals->clients as $clientId => $client) {
            $agreements = array_values(array_filter($inputs->agreements, static fn (ProjectedAgreement $agreement): bool => $agreement->clientId === $clientId));
            $open = array_values(array_filter($actuals->openInvoices, static fn (OpenInvoice $invoice): bool => $invoice->clientId === $clientId));
            $overdue = array_values(array_filter($open, static fn (OpenInvoice $invoice): bool => $invoice->isOverdueOn($asOf)));
            $health = [];
            foreach ($agreements as $agreement) {
                if (array_key_exists($agreement->id, $ledgers)) {
                    $health[] = $this->health->health($agreement, $ledgers[$agreement->id], $asOf);
                }
            }

            $row = new ClientRevenue(
                client: $client,
                mrr: array_sum(array_map(static fn (ProjectedAgreement $agreement): int => $agreement->monthlyRecurringOn($asOf), $agreements)),
                ytd: RevenueActuals::between($amounts, $asOf->startOfYear(), $asOf, $expenses, $clientId),
                ttm: RevenueActuals::between($amounts, $ttmFrom, $asOf, $expenses, $clientId),
                monthToDate: RevenueActuals::between($amounts, $asOf->startOfMonth(), $asOf, $expenses, $clientId),
                ttmSeries: array_map(static fn (string $month): array => ['month' => $month, 'amount' => RevenueActuals::month($monthly, $month, $clientId)->total($expenses)], $seriesMonths),
                openAmount: array_sum(array_map(static fn (OpenInvoice $invoice): int => $invoice->balance, $open)),
                overdueAmount: array_sum(array_map(static fn (OpenInvoice $invoice): int => $invoice->balance, $overdue)),
                overdueCount: count($overdue),
                agreements: $health,
            );

            if ($client->isActive || $row->mrr !== 0 || $row->ttm !== 0 || $row->ytd !== 0 || $row->openAmount !== 0 || $health !== []) {
                $rows[] = $row;
            }
        }
        usort($rows, static fn (ClientRevenue $a, ClientRevenue $b): int => [mb_strtolower($a->client->name), $a->client->publicId] <=> [mb_strtolower($b->client->name), $b->client->publicId]);

        $total = static fn (string $field): int => array_sum(array_map(static fn (ClientRevenue $row): int => $row->{$field}, $rows));

        return ['clients' => $rows, 'totals' => [
            'mrr' => $total('mrr'), 'ytd' => $total('ytd'), 'ttm' => $total('ttm'),
            'open_amount' => $total('openAmount'), 'overdue_amount' => $total('overdueAmount'), 'overdue_count' => $total('overdueCount'),
        ]];
    }

    /**
     * Per month and per client: the twelve months before the current one,
     * the current month, and the twelve after it. Past months carry actuals;
     * the current month carries actuals so far and what remains contracted;
     * later months the projection, plus any charge already invoiced in advance.
     *
     * `band` narrows every row to one band: `recurring` is the actual
     * recurring band with the contracted projection, `overage_and_other` the
     * actual band with the projected overage, `expenses` expenses alone
     * (never projected). Figures outside the band, or switched off, are null.
     *
     * @return list<array<string, mixed>>
     */
    public function monthly(RevenueActualsInputs $actuals, RevenueProjection $projection, RevenueAssumptions $assumptions, string $band = self::BAND_ALL, ?int $clientId = null): array
    {
        $asOf = $assumptions->asOf->startOfDay();
        $monthly = RevenueActuals::monthly($actuals->amounts($assumptions->basis));
        $current = $asOf->format('Y-m');
        $projectedClients = [];
        foreach ($projection->rows as $row) {
            $projectedClients[$row->clientId] = new RevenueClient($row->clientId, $row->clientPublicId, $row->clientName, true);
        }
        $clients = $actuals->clients + $projectedClients;

        $rows = [];
        for ($offset = -12; $offset <= RevenueAssumptions::PROJECTION_MONTHS; $offset++) {
            $month = $asOf->startOfMonth()->addMonthsNoOverflow($offset)->format('Y-m');
            $perClient = [];
            foreach ($clients as $id => $client) {
                if ($clientId !== null && $id !== $clientId) {
                    continue;
                }
                $figures = $this->figures($monthly[$id][$month] ?? new BandAmounts, $projection->contracted($month, $id), $projection->projectedOverage($month, $id), $assumptions, $band);
                if ($figures['total'] !== 0 || array_filter($figures, static fn (?int $value): bool => $value !== null && $value !== 0) !== []) {
                    $perClient[] = ['client_id' => $client->publicId, 'client_name' => $client->name, ...$figures];
                }
            }
            usort($perClient, static fn (array $a, array $b): int => [$b['total'], $a['client_id']] <=> [$a['total'], $b['client_id']]);

            $rows[] = [
                'month' => $month,
                'kind' => $month < $current ? 'actual' : ($month === $current ? 'current' : 'projected'),
                ...$this->figures(RevenueActuals::month($monthly, $month, $clientId), $projection->contracted($month, $clientId), $projection->projectedOverage($month, $clientId), $assumptions, $band),
                'clients' => $perClient,
            ];
        }

        return $rows;
    }

    /** @return array{recurring: int|null, overage_and_other: int|null, expenses: int|null, contracted: int|null, projected_overage: int|null, total: int} */
    private function figures(BandAmounts $actual, int $contracted, int $projectedOverage, RevenueAssumptions $assumptions, string $band): array
    {
        $in = static fn (string $name): bool => $band === self::BAND_ALL || $band === $name;
        $figures = [
            'recurring' => $in('recurring') ? $actual->recurring : null,
            'overage_and_other' => $in('overage_and_other') ? $actual->overageAndOther : null,
            'expenses' => $in('expenses') && $assumptions->includeExpenses ? $actual->expenses : null,
            'contracted' => $in('recurring') ? $contracted : null,
            'projected_overage' => $in('overage_and_other') && $assumptions->includeProjectedOverage ? $projectedOverage : null,
        ];

        return [...$figures, 'total' => array_sum(array_map(static fn (?int $value): int => $value ?? 0, $figures))];
    }

    private static function ttmFrom(CarbonImmutable $asOf): CarbonImmutable
    {
        return $asOf->startOfMonth()->subMonthsNoOverflow(12)->startOfDay();
    }
}
