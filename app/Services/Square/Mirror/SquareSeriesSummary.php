<?php

namespace App\Services\Square\Mirror;

use Carbon\CarbonImmutable;

/**
 * One recurring Square series, summarised from its mirrored invoices (#438).
 * Database-free.
 *
 * A dashboard series numbers its invoices `NNNNNN-R-NNNN`, so membership is
 * the six-digit prefix. Cadence is read from the spacing of due dates per
 * occurrence - the median, so one skipped month or one late invoice does not
 * change it - and a gap in the occurrence numbers is reported rather than
 * guessed at. The amount is the latest member's, and `amount_varies` says
 * whether any member differs (a price change mid-series).
 */
final class SquareSeriesSummary
{
    /** Square statuses whose balance is still owed. SCHEDULED is not issued yet. */
    public const array OPEN_STATUSES = ['UNPAID', 'PARTIALLY_PAID', 'PAYMENT_PENDING'];

    /** Statuses that are not, or are no longer, a bill. */
    private const array INACTIVE_STATUSES = ['CANCELED', 'DRAFT', 'FAILED'];

    /** [cadence, the fewest and the most days between occurrences]. */
    private const array CADENCES = [
        ['weekly', 6, 8],
        ['monthly', 26, 35],
        ['quarterly', 85, 96],
        ['semi_annual', 175, 190],
        ['annual', 355, 375],
    ];

    /** Missing occurrence numbers reported at most. */
    public const int MAX_MISSING = 50;

    /**
     * @param  list<array{series_occurrence: int|null, due_on: string|null, scheduled_on: string|null, status: string, currency: string|null, total_amount: int|null, paid_amount: int|null, autopay: bool, square_customer_id: string|null, recipient_name: string|null, square_location_id: string|null}>  $members  ordered by occurrence
     * @return array<string, mixed>
     */
    public static function of(string $seriesKey, array $members, CarbonImmutable $today): array
    {
        $occurrences = array_values(array_filter(array_column($members, 'series_occurrence'), 'is_int'));
        $latest = $members === [] ? null : $members[array_key_last($members)];
        $billed = array_values(array_filter($members, static fn (array $member): bool => ! in_array($member['status'], self::INACTIVE_STATUSES, true)));
        $amounts = array_values(array_unique(array_filter(array_column($billed, 'total_amount'), 'is_int')));
        $currencies = array_values(array_unique(array_filter(array_column($members, 'currency'), 'is_string')));
        $customers = array_values(array_unique(array_filter(array_column($members, 'square_customer_id'), 'is_string')));
        [$cadence, $days] = self::cadence($billed);
        $todayString = $today->toDateString();
        $upcoming = array_values(array_filter(
            array_map(static fn (array $member): ?string => in_array($member['status'], ['SCHEDULED', ...self::OPEN_STATUSES], true) ? self::date($member) : null, $members),
            static fn (?string $date): bool => $date !== null && $date >= $todayString,
        ));
        sort($upcoming);
        $dates = array_values(array_filter(array_map(self::date(...), $billed)));
        sort($dates);
        $statuses = [];
        foreach ($members as $member) {
            $statuses[$member['status']] = ($statuses[$member['status']] ?? 0) + 1;
        }
        ksort($statuses);
        $open = null;
        if (count($currencies) <= 1) {
            foreach ($members as $member) {
                if (in_array($member['status'], self::OPEN_STATUSES, true) && is_int($member['total_amount'])) {
                    $open = ($open ?? 0) + max(0, $member['total_amount'] - ($member['paid_amount'] ?? 0));
                }
            }
        }
        $missing = self::missing($occurrences);

        return [
            'series_key' => $seriesKey,
            'customer_id' => $latest['square_customer_id'] ?? null,
            'customer_count' => count($customers),
            'recipient_name' => $latest['recipient_name'] ?? null,
            'location_id' => $latest['square_location_id'] ?? null,
            'currency' => count($currencies) === 1 ? $currencies[0] : null,
            'invoice_count' => count($members),
            'first_occurrence' => $occurrences === [] ? null : min($occurrences),
            'last_occurrence' => $occurrences === [] ? null : max($occurrences),
            'missing_occurrences' => array_slice($missing, 0, self::MAX_MISSING),
            'missing_occurrences_truncated' => count($missing) > self::MAX_MISSING,
            'cadence' => $cadence,
            'cadence_days' => $days,
            'amount' => self::latestAmount($billed),
            'amount_varies' => count($amounts) > 1,
            'first_due_on' => $dates[0] ?? null,
            'last_due_on' => $dates === [] ? null : $dates[count($dates) - 1],
            'next_due_on' => $upcoming[0] ?? null,
            'open_amount' => $open,
            'autopay' => $latest['autopay'] ?? false,
            'statuses' => $statuses,
        ];
    }

    /**
     * The cadence from the median spacing per occurrence between consecutive
     * dated members, and that median in days.
     *
     * @param  list<array<string, mixed>>  $members
     * @return array{string, int|null}
     */
    private static function cadence(array $members): array
    {
        $dated = [];
        foreach ($members as $member) {
            $date = self::date($member);
            if ($date !== null && is_int($member['series_occurrence'])) {
                $dated[$member['series_occurrence']] = $date;
            }
        }
        ksort($dated);
        $spacings = [];
        $previous = null;
        foreach ($dated as $occurrence => $date) {
            if ($previous !== null) {
                [$previousOccurrence, $previousDate] = $previous;
                $days = CarbonImmutable::parse($previousDate)->diffInDays(CarbonImmutable::parse($date));
                $spacings[] = $days / ($occurrence - $previousOccurrence);
            }
            $previous = [$occurrence, $date];
        }
        if ($spacings === []) {
            return ['unknown', null];
        }
        sort($spacings);
        $middle = intdiv(count($spacings), 2);
        $median = count($spacings) % 2 === 1 ? $spacings[$middle] : ($spacings[$middle - 1] + $spacings[$middle]) / 2;
        $days = (int) round($median);
        foreach (self::CADENCES as [$name, $least, $most]) {
            if ($days >= $least && $days <= $most) {
                return [$name, $days];
            }
        }

        return ['irregular', $days];
    }

    /** @param  list<array<string, mixed>>  $members */
    private static function latestAmount(array $members): ?int
    {
        for ($index = count($members) - 1; $index >= 0; $index--) {
            if (is_int($members[$index]['total_amount'])) {
                return $members[$index]['total_amount'];
            }
        }

        return null;
    }

    /**
     * Occurrence numbers between the first and last that no mirrored invoice
     * carries.
     *
     * @param  list<int>  $occurrences
     * @return list<int>
     */
    private static function missing(array $occurrences): array
    {
        if ($occurrences === []) {
            return [];
        }
        $present = array_flip($occurrences);
        $missing = [];
        for ($occurrence = min($occurrences) + 1; $occurrence < max($occurrences); $occurrence++) {
            if (! isset($present[$occurrence])) {
                $missing[] = $occurrence;
                if (count($missing) > self::MAX_MISSING) {
                    break;
                }
            }
        }

        return $missing;
    }

    /** @param  array<string, mixed>  $member */
    private static function date(array $member): ?string
    {
        $date = $member['due_on'] ?? $member['scheduled_on'] ?? null;

        return is_string($date) ? $date : null;
    }
}
