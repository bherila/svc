<?php

namespace Tests\Unit\Expenses;

use App\Support\Expenses\NewExpense;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The write boundary's checks, without a database.
 *
 * Each case names a value the type refuses and the reason it refuses it. The
 * last two are the ones that matter beyond tidiness: an expense is billed at
 * cost, so its amount and currency are what a client is charged.
 */
final class NewExpenseTest extends TestCase
{
    public function test_it_keeps_the_facts_it_was_given(): void
    {
        $expense = new NewExpense(CarbonImmutable::parse('2026-08-15'), 12_500, 'USD', 'Synthetic travel expense');

        $this->assertSame(12_500, $expense->amount);
        $this->assertSame('USD', $expense->currency);
        $this->assertSame('Synthetic travel expense', $expense->description);
        $this->assertSame('2026-08-15', $expense->spentOn->toDateString());
    }

    /**
     * A receipt timestamped at 23:30 and one at 00:30 the same day are the same
     * day's expense. Keeping the time would make two rows for one date sort and
     * group differently for no reason a reader could see.
     */
    public function test_it_reduces_a_moment_to_the_day_it_was_spent(): void
    {
        $expense = new NewExpense(CarbonImmutable::parse('2026-08-15 23:30:00'), 500, 'USD', 'Late taxi');

        $this->assertSame('2026-08-15 00:00:00', $expense->spentOn->toDateTimeString());
    }

    public function test_it_normalizes_a_lowercase_currency(): void
    {
        $expense = new NewExpense(CarbonImmutable::parse('2026-08-15'), 500, ' usd ', 'Synthetic expense');

        $this->assertSame('USD', $expense->currency);
    }

    public function test_it_trims_the_description(): void
    {
        $expense = new NewExpense(CarbonImmutable::parse('2026-08-15'), 500, 'USD', "  Synthetic expense\n");

        $this->assertSame('Synthetic expense', $expense->description);
    }

    public function test_it_refuses_a_zero_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NewExpense(CarbonImmutable::parse('2026-08-15'), 0, 'USD', 'Synthetic expense');
    }

    public function test_it_refuses_a_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NewExpense(CarbonImmutable::parse('2026-08-15'), -100, 'USD', 'Synthetic expense');
    }

    /**
     * A blank currency is the defect #115 catalogued in another column: a rate
     * whose currency said nothing billed as that many units of whatever the
     * invoice was in.
     */
    public function test_it_refuses_a_currency_that_is_not_three_letters(): void
    {
        foreach (['', 'US', 'USDD', 'US1', '   '] as $currency) {
            try {
                new NewExpense(CarbonImmutable::parse('2026-08-15'), 500, $currency, 'Synthetic expense');
                $this->fail("'{$currency}' was accepted as an ISO 4217 code.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_refuses_a_blank_description(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NewExpense(CarbonImmutable::parse('2026-08-15'), 500, 'USD', "  \n ");
    }

    /**
     * The seam this type exists for: it cannot carry tenancy, so it cannot get
     * tenancy wrong. If a workspace, company or project column ever appears
     * here, the boundary has stopped being the only thing that decides ownership.
     */
    public function test_its_attributes_carry_no_tenant_columns(): void
    {
        $attributes = (new NewExpense(CarbonImmutable::parse('2026-08-15'), 500, 'USD', 'Synthetic expense'))->attributes();

        $this->assertSame(['spent_on', 'amount', 'currency', 'description'], array_keys($attributes));
    }
}
