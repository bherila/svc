<?php

namespace App\Services\Revenue;

use App\Support\Revenue\ActualAmount;
use App\Support\Revenue\BandAmounts;
use Carbon\CarbonImmutable;

/**
 * Monthly actuals per client, and totals over a date range, from amounts the
 * repository already placed and classified. Database-free.
 */
final class RevenueActuals
{
    /**
     * @param  list<ActualAmount>  $amounts
     * @return array<int, array<string, BandAmounts>> by client id, then `Y-m`
     */
    public static function monthly(array $amounts): array
    {
        $months = [];
        foreach ($amounts as $amount) {
            $month = $amount->date->format('Y-m');
            $months[$amount->clientId][$month] = ($months[$amount->clientId][$month] ?? new BandAmounts)->plus($amount->band, $amount->amount);
        }

        return $months;
    }

    /**
     * The bands of one month, over every client or one.
     *
     * @param  array<int, array<string, BandAmounts>>  $monthly
     */
    public static function month(array $monthly, string $month, ?int $clientId = null): BandAmounts
    {
        $total = new BandAmounts;
        foreach ($monthly as $client => $months) {
            if (($clientId === null || $client === $clientId) && isset($months[$month])) {
                $total = $total->add($months[$month]);
            }
        }

        return $total;
    }

    /**
     * Money placed on a day in [$from, $to], both inclusive.
     *
     * @param  list<ActualAmount>  $amounts
     */
    public static function between(array $amounts, CarbonImmutable $from, CarbonImmutable $to, bool $includeExpenses, ?int $clientId = null): int
    {
        $total = new BandAmounts;
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        foreach ($amounts as $amount) {
            if (($clientId === null || $amount->clientId === $clientId) && $amount->date->betweenIncluded($from, $to)) {
                $total = $total->plus($amount->band, $amount->amount);
            }
        }

        return $total->total($includeExpenses);
    }
}
