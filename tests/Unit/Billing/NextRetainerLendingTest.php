<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\NextRetainerLending;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** When the retainer an invoice sells may absorb the overflow of its work. */
final class NextRetainerLendingTest extends TestCase
{
    public function test_an_ordinary_month_lends_the_next_months_retainer_net_of_unabsorbed_debt(): void
    {
        $this->assertSame(10.0, NextRetainerLending::capacity(CarbonImmutable::parse('2026-01-31'), CarbonImmutable::parse('2026-02-01'), false, 10.0, 0.0));
        $this->assertSame(7.5, NextRetainerLending::capacity(CarbonImmutable::parse('2026-01-31'), CarbonImmutable::parse('2026-02-01'), false, 10.0, 2.5));
        $this->assertSame(0.0, NextRetainerLending::capacity(CarbonImmutable::parse('2026-01-31'), CarbonImmutable::parse('2026-02-01'), false, 10.0, 12.0));
    }

    /** A period ending mid-month derives its "next" retainer month as the same month. */
    public function test_a_period_ending_inside_its_month_lends_nothing_more_from_that_month(): void
    {
        $this->assertSame(0.0, NextRetainerLending::capacity(CarbonImmutable::parse('2026-02-15'), CarbonImmutable::parse('2026-02-01'), false, 10.0, 0.0));
        $this->assertSame(0.0, NextRetainerLending::capacity(CarbonImmutable::parse('2026-02-28'), CarbonImmutable::parse('2026-02-01'), false, 10.0, 0.0));
    }

    public function test_the_same_calendar_month_of_another_year_is_another_month(): void
    {
        $this->assertSame(10.0, NextRetainerLending::capacity(CarbonImmutable::parse('2025-02-15'), CarbonImmutable::parse('2026-02-01'), false, 10.0, 0.0));
    }

    public function test_nothing_is_lent_past_termination(): void
    {
        $this->assertSame(0.0, NextRetainerLending::capacity(CarbonImmutable::parse('2026-01-31'), CarbonImmutable::parse('2026-02-01'), true, 10.0, 0.0));
    }
}
