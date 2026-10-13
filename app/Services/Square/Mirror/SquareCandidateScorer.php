<?php

namespace App\Services\Square\Mirror;

use Carbon\CarbonImmutable;

/**
 * Ranks mirrored Square invoices as the counterpart of one svc invoice (#438).
 * Database-free.
 *
 * Points, as #438 sets them and the person confirming sees them: the amount
 * exactly (+40) or within 1% (+15); the Square invoice falling in the svc
 * invoice's period slot (+30); its series' cadence matching the agreement's
 * (+20); its due date within 7 days of the svc invoice's (+10); and its
 * customer being the one confirmed for the client (+10). The score is capped
 * at 100, and 70 or more is a suggestion - never a link: a person confirms
 * every link.
 *
 * Ties break by distance from the svc invoice's date, then an exact amount,
 * then a status that fits the svc invoice's (paid against paid, open against
 * open) - status is evidence, not a filter.
 */
final class SquareCandidateScorer
{
    public const int SUGGEST_AT = 70;

    public const int MAX_SCORE = 100;

    private const array CADENCES = ['monthly', 'quarterly', 'semi_annual', 'annual'];

    private const array OPEN = ['UNPAID', 'SCHEDULED', 'PARTIALLY_PAID', 'PAYMENT_PENDING'];

    private const array SETTLED = ['PAID', 'PARTIALLY_REFUNDED', 'REFUNDED'];

    /**
     * @param  array{currency: string, total_amount: int, due_date: string|null, reference_date: string|null, cycle_start: string|null, cycle_end: string|null, service_start: string|null, service_end: string|null, cadence: string|null, settled: bool}  $invoice
     * @param  list<array{square_invoice_id: string, status: string, currency: string|null, total_amount: int|null, due_on: string|null, scheduled_on: string|null, sale_or_service_date: string|null, square_customer_id: string|null, series_key: string|null, series_cadence: string|null, invoice_number: string|null}>  $candidates
     * @return list<array{square_invoice_id: string, score: int, suggested: bool, signals: array{amount: string, period_slot: bool, cadence: bool, due_within_7_days: bool, customer_mapped: bool}, reasons: list<string>, date_distance_days: int|null}>
     */
    public static function rank(array $invoice, ?string $mappedCustomerId, array $candidates): array
    {
        $scored = [];
        foreach ($candidates as $candidate) {
            $scored[] = self::score($invoice, $mappedCustomerId, $candidate) + ['_plausible' => self::plausible($invoice['settled'], $candidate['status']), '_number' => $candidate['invoice_number'] ?? ''];
        }
        usort($scored, static fn (array $a, array $b): int => [$b['score'], $a['date_distance_days'] ?? PHP_INT_MAX, $a['signals']['amount'] !== 'exact', ! $a['_plausible'], $a['_number']]
            <=> [$a['score'], $b['date_distance_days'] ?? PHP_INT_MAX, $b['signals']['amount'] !== 'exact', ! $b['_plausible'], $b['_number']]);

        return array_map(static function (array $entry): array {
            unset($entry['_plausible'], $entry['_number']);

            return $entry;
        }, $scored);
    }

    /**
     * Two or more suggestions share the top score: the person has to choose,
     * by period, between them - for example two series of one customer at
     * the same amount.
     *
     * @param  list<array{score: int, suggested: bool}>  $ranked
     */
    public static function ambiguous(array $ranked): bool
    {
        $first = $ranked[0] ?? null;
        $second = $ranked[1] ?? null;

        return $first !== null && $second !== null && $first['suggested'] && $second['suggested'] && $first['score'] === $second['score'];
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @param  array<string, mixed>  $candidate
     * @return array{square_invoice_id: string, score: int, suggested: bool, signals: array{amount: string, period_slot: bool, cadence: bool, due_within_7_days: bool, customer_mapped: bool}, reasons: list<string>, date_distance_days: int|null}
     */
    private static function score(array $invoice, ?string $mappedCustomerId, array $candidate): array
    {
        $points = 0;
        $reasons = [];

        $amount = 'unknown';
        if (is_int($candidate['total_amount'])) {
            $difference = abs($candidate['total_amount'] - $invoice['total_amount']);
            $amount = match (true) {
                $difference === 0 => 'exact',
                $difference * 100 <= abs($invoice['total_amount']) => 'within_1_percent',
                default => 'different',
            };
        }
        if ($amount === 'exact') {
            $points = 40;
            $reasons[] = 'Amount matches exactly (+40)';
        } elseif ($amount === 'within_1_percent') {
            $points = 15;
            $reasons[] = 'Amount within 1% (+15)';
        }

        $anchor = $candidate['due_on'] ?? $candidate['scheduled_on'];
        $slot = self::within($anchor, $invoice['cycle_start'], $invoice['cycle_end'])
            || self::within($candidate['sale_or_service_date'], $invoice['service_start'], $invoice['service_end']);
        if ($slot) {
            $points += 30;
            $reasons[] = 'Falls in this invoice\'s period (+30)';
        }

        $cadence = $candidate['series_key'] !== null
            && in_array($invoice['cadence'], self::CADENCES, true)
            && $candidate['series_cadence'] === $invoice['cadence'];
        if ($cadence) {
            $points += 20;
            $reasons[] = sprintf('Series cadence is %s, as the agreement\'s (+20)', str_replace('_', '-', $invoice['cadence']));
        }

        $dueDistance = self::days($candidate['due_on'], $invoice['due_date']);
        $dueClose = $dueDistance !== null && $dueDistance <= 7;
        if ($dueClose) {
            $points += 10;
            $reasons[] = 'Due within 7 days of this invoice (+10)';
        }

        $customer = $mappedCustomerId !== null && $candidate['square_customer_id'] === $mappedCustomerId;
        if ($customer) {
            $points += 10;
            $reasons[] = 'Customer is the client\'s confirmed Square customer (+10)';
        }

        $score = min(self::MAX_SCORE, $points);

        return [
            'square_invoice_id' => $candidate['square_invoice_id'],
            'score' => $score,
            'suggested' => $score >= self::SUGGEST_AT,
            'signals' => [
                'amount' => $amount,
                'period_slot' => $slot,
                'cadence' => $cadence,
                'due_within_7_days' => $dueClose,
                'customer_mapped' => $customer,
            ],
            'reasons' => $reasons,
            'date_distance_days' => self::days($anchor, $invoice['reference_date']),
        ];
    }

    private static function plausible(bool $settled, string $status): bool
    {
        return in_array($status, $settled ? self::SETTLED : self::OPEN, true);
    }

    private static function within(?string $date, ?string $start, ?string $end): bool
    {
        return $date !== null && $start !== null && $end !== null && $date >= $start && $date <= $end;
    }

    private static function days(?string $from, ?string $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        $distance = abs(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)));
        // @infection-ignore-all The declared ?int return coerces this integral day distance identically; static analysis requires the cast.
        $days = (int) $distance;

        return $days;
    }
}
