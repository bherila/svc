<?php

namespace Tests\Unit\Square;

use App\Services\Square\Mirror\SquareSeriesSummary;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Summarising a recurring Square series from its mirrored invoices (#438),
 * with synthetic members only.
 */
final class SquareSeriesSummaryTest extends TestCase
{
    public function test_a_monthly_series_with_a_skipped_month_and_a_price_change(): void
    {
        $members = [
            self::member(1, '2026-05-15', 'PAID', 10_000, 10_000),
            self::member(2, '2026-06-15', 'PAID', 10_000, 10_000),
            // July was skipped in Square: no invoice, and no occurrence 3.
            self::member(4, '2026-08-15', 'PAID', 12_000, 12_000),
            self::member(5, '2026-09-15', 'UNPAID', 12_000, 0),
            self::member(6, '2026-10-15', 'PARTIALLY_PAID', 12_000, 2_000),
            self::member(7, '2026-11-15', 'SCHEDULED', 12_000, 0),
        ];

        $summary = SquareSeriesSummary::of('000123', $members, CarbonImmutable::parse('2026-10-12'));

        $this->assertSame([
            'series_key' => '000123',
            'customer_id' => 'cust_fixture_a',
            'customer_count' => 1,
            'recipient_name' => 'Fixture Holdings',
            'location_id' => 'LOCFIXTURE01',
            'currency' => 'USD',
            'invoice_count' => 6,
            'first_occurrence' => 1,
            'last_occurrence' => 7,
            'missing_occurrences' => [3],
            'missing_occurrences_truncated' => false,
            'cadence' => 'monthly',
            'cadence_days' => 31,
            'amount' => 12_000,
            'amount_varies' => true,
            'first_due_on' => '2026-05-15',
            'last_due_on' => '2026-11-15',
            'next_due_on' => '2026-10-15',
            'open_amount' => 22_000,
            'autopay' => true,
            'statuses' => ['PAID' => 3, 'PARTIALLY_PAID' => 1, 'SCHEDULED' => 1, 'UNPAID' => 1],
        ], $summary);
    }

    /** @return iterable<string, array{list<string>, string, int|null}> */
    public static function cadences(): iterable
    {
        yield 'weekly' => [['2026-01-05', '2026-01-12', '2026-01-19'], 'weekly', 7];
        yield 'monthly' => [['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], 'monthly', 30];
        yield 'quarterly' => [['2026-01-01', '2026-04-01', '2026-07-01'], 'quarterly', 91];
        yield 'semi-annual' => [['2025-01-01', '2025-07-01', '2026-01-01'], 'semi_annual', 183];
        yield 'annual' => [['2024-03-01', '2025-03-01', '2026-03-01'], 'annual', 365];
        yield 'irregular' => [['2026-01-01', '2026-01-20', '2026-02-08'], 'irregular', 19];
        yield 'one member' => [['2026-01-01'], 'unknown', null];
    }

    /** @param  list<string>  $dates */
    #[DataProvider('cadences')]
    public function test_cadence_is_read_from_the_spacing_of_due_dates(array $dates, string $cadence, ?int $days): void
    {
        $members = [];
        foreach ($dates as $index => $date) {
            $members[] = self::member($index + 1, $date, 'PAID', 5_000, 5_000);
        }

        $summary = SquareSeriesSummary::of('000001', $members, CarbonImmutable::parse('2027-01-01'));

        $this->assertSame([$cadence, $days], [$summary['cadence'], $summary['cadence_days']]);
    }

    public function test_canceled_members_do_not_set_the_amount_or_cadence_and_a_scheduled_date_stands_in_for_a_due_date(): void
    {
        $members = [
            self::member(1, '2026-01-10', 'PAID', 9_000, 9_000),
            self::member(2, '2026-01-25', 'CANCELED', 1, 0),
            self::member(3, null, 'SCHEDULED', 9_000, 0, scheduled: '2026-03-10'),
        ];

        $summary = SquareSeriesSummary::of('000002', $members, CarbonImmutable::parse('2026-02-01'));

        $this->assertSame(['monthly', 30, 9_000, false, '2026-03-10', null], [
            $summary['cadence'], $summary['cadence_days'], $summary['amount'], $summary['amount_varies'], $summary['next_due_on'], $summary['open_amount'],
        ]);
        $this->assertSame(['CANCELED' => 1, 'PAID' => 1, 'SCHEDULED' => 1], $summary['statuses']);
    }

    public function test_mixed_currencies_have_no_open_amount_and_many_gaps_are_capped(): void
    {
        $members = [
            self::member(1, '2026-01-01', 'UNPAID', 1_000, 0),
            self::member(80, '2026-02-01', 'UNPAID', 1_000, 0, currency: 'CAD'),
        ];

        $summary = SquareSeriesSummary::of('000003', $members, CarbonImmutable::parse('2026-01-01'));

        $this->assertNull($summary['currency']);
        $this->assertNull($summary['open_amount']);
        $this->assertSame(range(2, 51), $summary['missing_occurrences']);
        $this->assertTrue($summary['missing_occurrences_truncated']);
    }

    public function test_an_empty_series_is_described_without_guessing(): void
    {
        $summary = SquareSeriesSummary::of('000004', [], CarbonImmutable::parse('2026-01-01'));

        $this->assertSame([null, 0, null, null, [], 'unknown', null, false, null, null, false, []], [
            $summary['customer_id'], $summary['invoice_count'], $summary['first_occurrence'], $summary['amount'], $summary['missing_occurrences'],
            $summary['cadence'], $summary['cadence_days'], $summary['amount_varies'], $summary['next_due_on'], $summary['open_amount'],
            $summary['autopay'], $summary['statuses'],
        ]);
    }

    public function test_unknown_fields_do_not_become_occurrences_amounts_currencies_or_customers(): void
    {
        $members = [
            array_replace(self::member(1, null, 'UNPAID', 100, 0), ['series_occurrence' => null, 'total_amount' => null, 'currency' => null, 'square_customer_id' => null]),
            self::member(4, '2040-03-10', 'UNPAID', 100, 0),
        ];
        $summary = SquareSeriesSummary::of('000001', $members, CarbonImmutable::parse('2040-01-01'));
        $this->assertSame([4, 4, [], 100, false, 'USD', 1, 'unknown', null, '2040-03-10', '2040-03-10'], [
            $summary['first_occurrence'], $summary['last_occurrence'], $summary['missing_occurrences'], $summary['amount'], $summary['amount_varies'],
            $summary['currency'], $summary['customer_count'], $summary['cadence'], $summary['cadence_days'], $summary['first_due_on'], $summary['last_due_on'],
        ]);
    }

    public function test_due_dates_are_sorted_and_today_is_still_upcoming(): void
    {
        $members = [
            self::member(1, '2040-03-01', 'UNPAID', 100, 0, scheduled: '2040-04-01'),
            self::member(2, '2040-01-01', 'UNPAID', 100, 0),
            self::member(3, '2040-02-01', 'UNPAID', 100, 0),
        ];
        $summary = SquareSeriesSummary::of('000001', $members, CarbonImmutable::parse('2040-01-01'));
        $this->assertSame(['2040-01-01', '2040-03-01', '2040-01-01'], [$summary['first_due_on'], $summary['last_due_on'], $summary['next_due_on']]);
    }

    public function test_fully_covered_and_overpaid_open_members_have_zero_balance_and_unknown_payment_is_zero(): void
    {
        $members = [
            self::member(1, '2040-01-01', 'PAYMENT_PENDING', 100, 100),
            self::member(2, '2040-02-01', 'PARTIALLY_PAID', 100, 200),
            array_replace(self::member(3, '2040-03-01', 'UNPAID', 100, 0), ['paid_amount' => null]),
        ];
        $summary = SquareSeriesSummary::of('000001', $members, CarbonImmutable::parse('2040-01-01'));
        $this->assertSame(100, $summary['open_amount']);
    }

    public function test_the_latest_known_amount_wins_and_one_member_is_sufficient(): void
    {
        $today = CarbonImmutable::parse('2040-01-01');
        $first = self::member(1, '2040-01-01', 'PAID', 100, 100);
        $last = self::member(2, '2040-02-01', 'PAID', 200, 200);
        $this->assertSame(100, SquareSeriesSummary::of('000001', [$first], $today)['amount']);
        $this->assertSame(200, SquareSeriesSummary::of('000001', [$first, $last], $today)['amount']);
        $unknown = array_replace($last, ['total_amount' => null]);
        $this->assertSame(100, SquareSeriesSummary::of('000001', [$first, $unknown], $today)['amount']);
        $this->assertFalse(SquareSeriesSummary::of('000001', [$first, $unknown], $today)['amount_varies']);
    }

    public function test_cadence_sorts_occurrences_and_ignores_incomplete_members(): void
    {
        $members = [
            self::member(1, '2040-01-01', 'PAID', 100, 100),
            self::member(3, '2040-03-03', 'PAID', 100, 100),
            self::member(2, '2040-02-01', 'PAID', 100, 100),
            self::member(4, null, 'PAID', 100, 100),
            array_replace(self::member(5, '2040-12-01', 'PAID', 100, 100), ['series_occurrence' => null]),
        ];
        $summary = SquareSeriesSummary::of('000001', $members, CarbonImmutable::parse('2040-01-01'));
        $this->assertSame(['monthly', 31], [$summary['cadence'], $summary['cadence_days']]);
    }

    /** @return iterable<string, array{int, string}> */
    public static function cadenceBoundaries(): iterable
    {
        foreach (['weekly' => [6, 8], 'monthly' => [26, 35], 'quarterly' => [85, 96], 'semi_annual' => [175, 190], 'annual' => [355, 375]] as $name => $bounds) {
            foreach ($bounds as $days) {
                yield $name.' '.$days => [$days, $name];
            }
        }
    }

    #[DataProvider('cadenceBoundaries')]
    public function test_cadence_boundaries_are_inclusive(int $days, string $cadence): void
    {
        $first = CarbonImmutable::parse('2040-01-01');
        $members = [self::member(1, $first->toDateString(), 'PAID', 100, 100), self::member(2, $first->addDays($days)->toDateString(), 'PAID', 100, 100)];
        $summary = SquareSeriesSummary::of('000001', $members, $first);
        $this->assertSame([$cadence, $days], [$summary['cadence'], $summary['cadence_days']]);
    }

    public function test_fractional_spacing_is_rounded_to_the_nearest_day(): void
    {
        $first = CarbonImmutable::parse('2040-01-01');
        $members = [self::member(1, $first->toDateString(), 'PAID', 100, 100), self::member(6, $first->addDays(36)->toDateString(), 'PAID', 100, 100)];
        $summary = SquareSeriesSummary::of('000001', $members, $first);
        $this->assertSame(['weekly', 7], [$summary['cadence'], $summary['cadence_days']]);
    }

    public function test_missing_occurrences_are_unique_sorted_and_bounded(): void
    {
        $members = [];
        foreach ([6, 1, 3, 3] as $occurrence) {
            $members[] = self::member($occurrence, null, 'PAID', 100, 100);
        }
        $summary = SquareSeriesSummary::of('000001', $members, CarbonImmutable::parse('2040-01-01'));
        $this->assertSame([2, 4, 5], $summary['missing_occurrences']);
        $this->assertFalse($summary['missing_occurrences_truncated']);
    }

    public function test_exactly_the_display_limit_of_missing_occurrences_is_not_truncated(): void
    {
        $members = [self::member(1, null, 'PAID', 100, 100), self::member(52, null, 'PAID', 100, 100)];
        $summary = SquareSeriesSummary::of('000001', $members, CarbonImmutable::parse('2040-01-01'));
        $this->assertSame(range(2, 51), $summary['missing_occurrences']);
        $this->assertFalse($summary['missing_occurrences_truncated']);
    }

    public function test_nonuniform_cadence_is_calculated_in_occurrence_order(): void
    {
        $members = [self::member(1, '2040-01-01', 'PAID', 100, 100), self::member(3, '2040-04-01', 'PAID', 100, 100), self::member(2, '2040-01-11', 'PAID', 100, 100)];
        $summary = SquareSeriesSummary::of('000001', $members, CarbonImmutable::parse('2040-01-01'));
        $this->assertSame(['irregular', 46], [$summary['cadence'], $summary['cadence_days']]);
    }

    public function test_gap_enumeration_stops_after_enough_entries_to_prove_truncation(): void
    {
        // The public summary slices the result, so check the bounded calculation
        // itself: producing every absent occurrence would waste memory and work.
        $missing = new \ReflectionMethod(SquareSeriesSummary::class, 'missing');
        $this->assertSame(range(2, 52), $missing->invoke(null, [1, 80]));
    }

    /** @return array{series_occurrence: int|null, due_on: string|null, scheduled_on: string|null, status: string, currency: string|null, total_amount: int|null, paid_amount: int|null, autopay: bool, square_customer_id: string|null, recipient_name: string|null, square_location_id: string|null} */
    private static function member(int $occurrence, ?string $due, string $status, int $total, int $paid, ?string $scheduled = null, string $currency = 'USD'): array
    {
        return [
            'series_occurrence' => $occurrence,
            'due_on' => $due,
            'scheduled_on' => $scheduled,
            'status' => $status,
            'currency' => $currency,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'autopay' => true,
            'square_customer_id' => 'cust_fixture_a',
            'recipient_name' => 'Fixture Holdings',
            'square_location_id' => 'LOCFIXTURE01',
        ];
    }
}
