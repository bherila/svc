<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\BillingCadence;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class BillingCadenceEnumTest extends TestCase
{
    public function test_months_in_cycle_matches_supported_cadences(): void
    {
        $this->assertSame(1, BillingCadence::Monthly->monthsInCycle());
        $this->assertSame(3, BillingCadence::Quarterly->monthsInCycle());
        $this->assertSame(6, BillingCadence::SemiAnnual->monthsInCycle());
        $this->assertSame(12, BillingCadence::Annual->monthsInCycle());
    }

    public function test_cycle_start_and_end_are_calendar_aligned(): void
    {
        $reference = Carbon::parse('2026-05-08');

        $this->assertEquals('2026-05-01', BillingCadence::Monthly->cycleStart($reference)->toDateString());
        $this->assertEquals('2026-05-31', BillingCadence::Monthly->cycleEnd($reference)->toDateString());

        $this->assertEquals('2026-04-01', BillingCadence::Quarterly->cycleStart($reference)->toDateString());
        $this->assertEquals('2026-06-30', BillingCadence::Quarterly->cycleEnd($reference)->toDateString());

        $this->assertEquals('2026-01-01', BillingCadence::Annual->cycleStart($reference)->toDateString());
        $this->assertEquals('2026-12-31', BillingCadence::Annual->cycleEnd($reference)->toDateString());
    }

    public function test_semi_annual_cycle_start_first_half(): void
    {
        // Dates in Jan–Jun should return Jan 1
        $this->assertEquals('2026-01-01', BillingCadence::SemiAnnual->cycleStart(Carbon::parse('2026-01-01'))->toDateString());
        $this->assertEquals('2026-01-01', BillingCadence::SemiAnnual->cycleStart(Carbon::parse('2026-03-15'))->toDateString());
        $this->assertEquals('2026-01-01', BillingCadence::SemiAnnual->cycleStart(Carbon::parse('2026-06-30'))->toDateString());
    }

    public function test_semi_annual_cycle_start_second_half(): void
    {
        // Dates in Jul–Dec should return Jul 1
        $this->assertEquals('2026-07-01', BillingCadence::SemiAnnual->cycleStart(Carbon::parse('2026-07-01'))->toDateString());
        $this->assertEquals('2026-07-01', BillingCadence::SemiAnnual->cycleStart(Carbon::parse('2026-09-15'))->toDateString());
        $this->assertEquals('2026-07-01', BillingCadence::SemiAnnual->cycleStart(Carbon::parse('2026-12-31'))->toDateString());
    }

    public function test_semi_annual_cycle_end_first_half(): void
    {
        $this->assertEquals('2026-06-30', BillingCadence::SemiAnnual->cycleEnd(Carbon::parse('2026-03-15'))->toDateString());
    }

    public function test_semi_annual_cycle_end_second_half(): void
    {
        $this->assertEquals('2026-12-31', BillingCadence::SemiAnnual->cycleEnd(Carbon::parse('2026-09-15'))->toDateString());
    }

    public function test_cycle_starts_between_excludes_containing_cycle_before_from_date(): void
    {
        $starts = iterator_to_array(BillingCadence::Quarterly->cycleStartsBetween(
            Carbon::parse('2026-02-15'),
            Carbon::parse('2026-12-31'),
        ), false);

        $this->assertCount(3, $starts);
        $this->assertEquals('2026-04-01', $starts[0]->toDateString());
        $this->assertEquals('2026-07-01', $starts[1]->toDateString());
        $this->assertEquals('2026-10-01', $starts[2]->toDateString());
    }
}
