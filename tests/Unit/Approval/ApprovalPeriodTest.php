<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\ApprovalPeriod;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ApprovalPeriodTest extends TestCase
{
    public function test_a_period_is_the_calendar_month_holding_the_day(): void
    {
        $period = ApprovalPeriod::containing('2026-10-14');

        $this->assertSame('2026-10', $period->key());
        $this->assertSame('2026-10-01', $period->start());
        $this->assertSame('2026-10-31', $period->end());
        $this->assertSame('October 2026', $period->label());
    }

    public function test_the_first_and_last_day_belong_to_it_and_their_neighbours_do_not(): void
    {
        $period = ApprovalPeriod::containing('2026-10-14');

        $this->assertTrue($period->holds('2026-10-01'));
        $this->assertTrue($period->holds('2026-10-31'));
        $this->assertFalse($period->holds('2026-09-30'));
        $this->assertFalse($period->holds('2026-11-01'));
    }

    public function test_the_last_day_follows_the_month_length_including_leap_years(): void
    {
        $this->assertSame('2026-02-28', ApprovalPeriod::containing('2026-02-10')->end());
        $this->assertSame('2028-02-29', ApprovalPeriod::containing('2028-02-10')->end());
        $this->assertSame('2026-04-30', ApprovalPeriod::containing('2026-04-30')->end());
        $this->assertSame('2026-12-31', ApprovalPeriod::containing('2026-12-01')->end());
    }

    public function test_a_key_names_the_same_period_as_a_day_in_it(): void
    {
        $period = ApprovalPeriod::fromKey('2026-02');

        $this->assertNotNull($period);
        $this->assertSame('2026-02-01', $period->start());
        $this->assertSame('2026-02-28', $period->end());
    }

    public function test_a_key_that_is_not_a_year_and_month_names_nothing(): void
    {
        foreach (['2026-13', '2026-00', '2026-1', '26-10', '2026-10-01', ' 2026-10', '2026-10 ', '', 'October'] as $key) {
            $this->assertNull(ApprovalPeriod::fromKey($key), $key);
        }
        $this->assertNotNull(ApprovalPeriod::fromKey('2026-01'));
        $this->assertNotNull(ApprovalPeriod::fromKey('2026-12'));
    }

    public function test_a_day_that_is_not_a_real_date_is_refused(): void
    {
        foreach (['2026-02-30', '2026-10-1', '10/14/2026', '', '2026-10-14T00:00:00'] as $day) {
            try {
                ApprovalPeriod::containing($day);
                $this->fail("Accepted {$day}.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
