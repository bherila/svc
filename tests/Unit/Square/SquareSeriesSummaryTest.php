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
