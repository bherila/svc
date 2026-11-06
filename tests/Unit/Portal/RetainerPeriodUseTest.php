<?php

namespace Tests\Unit\Portal;

use App\Services\Billing\Balances\ClosingBalance;
use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\Balances\OpeningBalance;
use App\Support\Portal\NextInvoiceEstimate;
use App\Support\Portal\RetainerPeriodUse;
use PHPUnit\Framework\TestCase;

final class RetainerPeriodUseTest extends TestCase
{
    public function test_the_rows_of_the_period_are_summed_and_the_rollover_is_the_first_rows_opening(): void
    {
        $use = RetainerPeriodUse::forPeriod([
            $this->row('2026-07', '2026-07-01', hours: 30, retainer: 40, rollover: 9, negative: 0),
            $this->row('2026-10', '2026-10-01', hours: 12.5, retainer: 20, rollover: 6, negative: 0),
            $this->row('2026-11', '2026-10-01', hours: 8.25, retainer: 20, rollover: 2, negative: 1.5),
        ], '2026-10-01', '2026-12-31');

        $this->assertNotNull($use);
        $this->assertSame(20.75, $use->hoursUsed);
        $this->assertSame(40.0, $use->hoursIncluded);
        $this->assertSame(6.0, $use->rolloverHours);
        $this->assertSame(1.5, $use->overageHours);
        $this->assertSame(['minutes_used' => 1245, 'minutes_included' => 2400, 'rollover_minutes' => 360, 'overage_minutes' => 90], $use->toArray());
    }

    public function test_a_legacy_monthly_ledger_is_selected_by_the_months_the_period_touches(): void
    {
        $use = RetainerPeriodUse::forPeriod([
            $this->row('2026-10', null, hours: 99, retainer: 99, rollover: 0, negative: 0),
            $this->row('2026-11', null, hours: 10, retainer: 30, rollover: 4, negative: 0),
            $this->row('2026-12', null, hours: 5, retainer: 30, rollover: 1, negative: 0),
            $this->row('2027-01', null, hours: 99, retainer: 99, rollover: 0, negative: 0),
        ], '2026-11-01', '2026-12-31');

        $this->assertNotNull($use);
        $this->assertSame(15.0, $use->hoursUsed);
        $this->assertSame(60.0, $use->hoursIncluded);
        $this->assertSame(4.0, $use->rolloverHours);
    }

    public function test_the_first_and_last_months_of_a_period_are_inside_it(): void
    {
        $use = RetainerPeriodUse::forPeriod([
            $this->row('2026-11', null, hours: 1, retainer: 1, rollover: 0, negative: 0),
            $this->row('2026-12', null, hours: 2, retainer: 2, rollover: 0, negative: 0),
        ], '2026-11-15', '2026-12-14');

        $this->assertSame(3.0, $use?->hoursUsed);
    }

    public function test_a_period_with_no_rows_has_no_use(): void
    {
        $this->assertNull(RetainerPeriodUse::forPeriod([$this->row('2026-07', '2026-07-01', hours: 1, retainer: 1, rollover: 0, negative: 0)], '2026-10-01', '2026-12-31'));
        $this->assertNull(RetainerPeriodUse::forPeriod([], '2026-10-01', '2026-12-31'));
    }

    public function test_negative_rollover_and_balance_never_read_as_less_than_nothing(): void
    {
        $use = RetainerPeriodUse::forPeriod([$this->row('2026-10', '2026-10-01', hours: 1, retainer: 1, rollover: -3, negative: -2)], '2026-10-01', '2026-12-31');

        $this->assertSame(0.0, $use?->rolloverHours);
        $this->assertSame(0.0, $use?->overageHours);
    }

    public function test_the_estimate_prints_its_parts_and_what_it_leaves_out(): void
    {
        $estimate = new NextInvoiceEstimate('2026-12-01', '2026-12-31', 'USD', [
            ['kind' => 'retainer', 'description' => 'Retainer', 'amount' => 800000, 'date' => '2026-12-01', 'hours' => null],
            ['kind' => 'overage', 'description' => 'Hours past the retainer so far', 'amount' => null, 'date' => null, 'hours' => 2.0],
        ]);

        $this->assertSame(800000, $estimate->toArray()['total']);
        $this->assertSame('tax and expenses', $estimate->toArray()['excludes']);
        $this->assertSame('USD', $estimate->toArray()['currency']);
        $this->assertSame('2026-12-01', $estimate->toArray()['period_start']);
        $this->assertSame('2026-12-31', $estimate->toArray()['period_end']);
        $this->assertCount(2, $estimate->toArray()['parts']);
        $this->assertSame(0, (new NextInvoiceEstimate('2026-12-01', '2026-12-31', null, []))->total());
    }

    private function row(string $month, ?string $cycleStart, float $hours, float $retainer, float $rollover, float $negative): MonthSummary
    {
        return new MonthSummary(
            new OpeningBalance($retainer, $rollover, 0, $retainer + $rollover, 0, 0, $retainer, 0),
            new ClosingBalance(0, 0, 0, 0, $negative, 0),
            $hours,
            $month,
            $retainer,
            cycleStart: $cycleStart,
        );
    }
}
