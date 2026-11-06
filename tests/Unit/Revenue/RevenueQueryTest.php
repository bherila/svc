<?php

namespace Tests\Unit\Revenue;

use App\Support\Revenue\RevenueBasis;
use App\Support\Revenue\RevenueQuery;
use App\Support\Revenue\ServiceMonth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RevenueQueryTest extends TestCase
{
    public function test_nothing_asked_for_is_invoiced_with_expenses_and_no_projected_overage(): void
    {
        $query = RevenueQuery::fromInput([]);

        $this->assertSame(RevenueBasis::Invoiced, $query->basis);
        $this->assertTrue($query->includeExpenses);
        $this->assertFalse($query->includeProjectedOverage);
        $this->assertNull($query->clientId);
        $this->assertSame(['basis' => 'invoiced', 'expenses' => true, 'overage' => false], $query->toggles());
    }

    public function test_each_toggle_is_read_and_the_client_is_carried(): void
    {
        $query = RevenueQuery::fromInput(['basis' => 'collected', 'expenses' => '0', 'overage' => '1'], 'client-1');

        $this->assertSame(RevenueBasis::Collected, $query->basis);
        $this->assertFalse($query->includeExpenses);
        $this->assertTrue($query->includeProjectedOverage);
        $this->assertSame('client-1', $query->clientId);
        $this->assertSame(['basis' => 'collected', 'expenses' => false, 'overage' => true], $query->toggles());
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function flags(): iterable
    {
        foreach (['1', 'true', 'on', 'yes', 1, true] as $value) {
            yield 'on '.var_export($value, true) => [$value, true];
        }
        foreach (['0', 'false', 'off', 'no', 0, false] as $value) {
            yield 'off '.var_export($value, true) => [$value, false];
        }
    }

    #[DataProvider('flags')]
    public function test_a_flag_is_read_as_the_boolean_it_spells(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, RevenueQuery::fromInput(['overage' => $value])->includeProjectedOverage);
        $this->assertSame($expected, RevenueQuery::fromInput(['expenses' => $value])->includeExpenses);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unreadable(): iterable
    {
        yield 'garbage text' => [['basis' => 'bogus', 'expenses' => 'maybe', 'overage' => 'perhaps']];
        yield 'arrays' => [['basis' => ['collected'], 'expenses' => ['0'], 'overage' => ['1']]];
        yield 'null' => [['basis' => null, 'expenses' => null, 'overage' => null]];
        yield 'basis in the wrong case' => [['basis' => 'Collected']];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('unreadable')]
    public function test_anything_unreadable_takes_the_defaults(array $input): void
    {
        $this->assertSame(['basis' => 'invoiced', 'expenses' => true, 'overage' => false], RevenueQuery::fromInput($input)->toggles());
    }

    /** @return iterable<string, array{mixed, array{string, string}|null}> */
    public static function months(): iterable
    {
        yield 'january' => ['2026-01', ['2026-01-01', '2026-01-31']];
        yield 'a 30 day month' => ['2026-11', ['2026-11-01', '2026-11-30']];
        yield 'february of a leap year' => ['2028-02', ['2028-02-01', '2028-02-29']];
        yield 'february' => ['2026-02', ['2026-02-01', '2026-02-28']];
        yield 'december' => ['2026-12', ['2026-12-01', '2026-12-31']];
        yield 'month zero' => ['2026-00', null];
        yield 'month thirteen' => ['2026-13', null];
        yield 'a date' => ['2026-11-01', null];
        yield 'one digit month' => ['2026-1', null];
        yield 'trailing text' => ['2026-11x', null];
        yield 'leading text' => ['x2026-11', null];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
        yield 'an array' => [['2026-11'], null];
    }

    /** @param array{string, string}|null $expected */
    #[DataProvider('months')]
    public function test_a_service_month_is_a_real_month_or_nothing(mixed $month, ?array $expected): void
    {
        $this->assertSame($expected, ServiceMonth::bounds($month));
    }
}
