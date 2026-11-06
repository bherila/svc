<?php

declare(strict_types=1);

namespace App\Support\Revenue;

use Closure;

/**
 * The dashboard's and the client overview's revenue props, shaped from what
 * the revenue read model already computed (docs/ux-overhaul.md sections 2 and
 * 4). Pure and database-free: it adds no revenue figure of its own, only
 * comparisons between two figures the model returned, the top-five breakdown
 * of a month and the finished links, so the browser prints numbers and never
 * derives them.
 *
 * Amounts stay in minor units, tax excluded (open receivables include it,
 * as the model documents).
 */
final class RevenueDashboard
{
    /** Clients named in a month's tooltip before the rest are folded into "others". */
    public const int TOP_CLIENTS = 5;

    /**
     * Percent change from the prior figure to the current one, one decimal.
     * Null when there is no prior figure to compare to: zero, because a
     * change from nothing has no percentage.
     */
    public static function percentChange(int $current, int $prior): ?float
    {
        if ($prior === 0) {
            return null;
        }

        return round(($current - $prior) / abs($prior) * 100, 1);
    }

    /**
     * The four key figures.
     *
     * @param  array<string, mixed>  $summary  `RevenueSummary::toArray()`
     * @return array{ytd: array<string, mixed>, ttm: array<string, mixed>, next_12_months: array<string, mixed>, open_receivables: array<string, mixed>}
     */
    public static function kpis(array $summary): array
    {
        $window = static fn (array $figure): array => [
            'amount' => $figure['amount'],
            'prior_amount' => $figure['prior_amount'],
            'change_percent' => self::percentChange($figure['amount'], $figure['prior_amount']),
            'from' => $figure['from'],
            'to' => $figure['to'],
            'prior_from' => $figure['prior_from'],
            'prior_to' => $figure['prior_to'],
        ];

        return [
            'ytd' => $window($summary['ytd']),
            'ttm' => $window($summary['ttm']),
            'next_12_months' => $summary['next_12_months'],
            'open_receivables' => $summary['open_receivables'],
        ];
    }

    /**
     * The chart's months, each with its bands, a link to that month's
     * invoices and its per-client breakdown (the top five, then the rest as
     * one "others" line).
     *
     * @param  list<array<string, mixed>>  $months  `RevenueSummaries::monthly()`
     * @param  Closure(string): string  $monthHref  month (`Y-m`) to the invoices list for it
     * @param  Closure(string): string  $clientHref  client public id to its overview
     * @return list<array<string, mixed>>
     */
    public static function months(array $months, Closure $monthHref, Closure $clientHref): array
    {
        $rows = [];
        foreach ($months as $month) {
            $clients = $month['clients'];
            $top = array_slice($clients, 0, self::TOP_CLIENTS);
            $rest = array_slice($clients, self::TOP_CLIENTS);

            $rows[] = [
                'month' => $month['month'],
                'kind' => $month['kind'],
                'recurring' => $month['recurring'],
                'overage_and_other' => $month['overage_and_other'],
                'expenses' => $month['expenses'],
                'contracted' => $month['contracted'],
                'projected_overage' => $month['projected_overage'],
                'total' => $month['total'],
                'href' => $monthHref($month['month']),
                'clients' => array_map(static fn (array $client): array => [
                    'client_id' => $client['client_id'],
                    'name' => $client['client_name'],
                    'total' => $client['total'],
                    'href' => $clientHref($client['client_id']),
                ], $top),
                'others' => $rest === [] ? null : [
                    'count' => count($rest),
                    'total' => array_sum(array_column($rest, 'total')),
                ],
            ];
        }

        return $rows;
    }

    /**
     * Agreements ending inside the window, with the monthly amount that drops
     * off, soonest first (the model's order). No renewal is assumed.
     *
     * @param  list<array{agreement_id: string, client_id: string, client_name: string, title: string, date: string, monthly_amount: int}>  $ending
     * @param  Closure(string): string  $clientHref
     * @param  Closure(string, string): string  $agreementHref  client id, agreement id
     * @return array{agreements: list<array<string, mixed>>, monthly_total: int}
     */
    public static function atRisk(array $ending, Closure $clientHref, Closure $agreementHref): array
    {
        return [
            'agreements' => array_map(static fn (array $change): array => [
                'agreement_id' => $change['agreement_id'],
                'client_id' => $change['client_id'],
                'client_name' => $change['client_name'],
                'title' => $change['title'],
                'ends_on' => $change['date'],
                'monthly_amount' => $change['monthly_amount'],
                'client_href' => $clientHref($change['client_id']),
                'href' => $agreementHref($change['client_id'], $change['agreement_id']),
            ], $ending),
            'monthly_total' => array_sum(array_column($ending, 'monthly_amount')),
        ];
    }
}
