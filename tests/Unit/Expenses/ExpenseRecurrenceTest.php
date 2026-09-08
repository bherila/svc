<?php

namespace Tests\Unit\Expenses;

use App\Support\Billing\BillingCadence;
use App\Support\Expenses\ExpenseRecurrence;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ExpenseRecurrenceTest extends TestCase
{
    public function test_dates_keep_the_original_anchor_after_short_months(): void
    {
        $monthly = new ExpenseRecurrence(CarbonImmutable::parse('2028-01-31'), BillingCadence::Monthly);
        $this->assertSame(['2028-01-31', '2028-02-29', '2028-03-31'], array_map(fn (int $i): string => $monthly->occurrence($i)->toDateString(), [0, 1, 2]));
        $annual = new ExpenseRecurrence(CarbonImmutable::parse('2028-02-29'), BillingCadence::Annual);
        $this->assertSame('2029-02-28', $annual->occurrence(1)->toDateString());
        $this->assertSame('2032-02-29', $annual->occurrence(4)->toDateString());
    }

    public function test_negative_cursor_is_refused(): void
    {
        $this->expectException(\DomainException::class);
        (new ExpenseRecurrence(CarbonImmutable::parse('2028-01-31'), BillingCadence::Monthly))->occurrence(-1);
    }
}
