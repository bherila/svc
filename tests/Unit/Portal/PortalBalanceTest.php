<?php

namespace Tests\Unit\Portal;

use App\Support\Portal\PortalBalance;
use PHPUnit\Framework\TestCase;

/** The portal's Balance card, decided without a database. Today is 2026-11-06. */
final class PortalBalanceTest extends TestCase
{
    private const string TODAY = '2026-11-06';

    public function test_nothing_open_is_nothing_due(): void
    {
        $this->assertSame([
            'amount_due' => 0,
            'currency' => null,
            'invoice_count' => 0,
            'overdue' => false,
            'overdue_amount' => 0,
            'first' => null,
        ], $this->balance([]));
    }

    public function test_the_amount_due_is_the_sum_of_the_open_balances(): void
    {
        $facts = $this->balance([$this->row('A', 920000, '2026-11-15'), $this->row('B', 5000, '2026-12-01')]);

        $this->assertSame(925000, $facts['amount_due']);
        $this->assertSame('USD', $facts['currency']);
        $this->assertSame(2, $facts['invoice_count']);
    }

    public function test_an_invoice_due_today_is_not_overdue_and_one_due_yesterday_is(): void
    {
        $today = $this->balance([$this->row('A', 1000, '2026-11-06')]);
        $this->assertFalse($today['overdue']);
        $this->assertSame(0, $today['overdue_amount']);
        $this->assertSame(0, $today['first']['days_until_due']);

        $yesterday = $this->balance([$this->row('A', 1000, '2026-11-05')]);
        $this->assertTrue($yesterday['overdue']);
        $this->assertSame(1000, $yesterday['overdue_amount']);
        $this->assertSame(-1, $yesterday['first']['days_until_due']);
    }

    public function test_only_the_late_balances_count_as_overdue(): void
    {
        $facts = $this->balance([$this->row('A', 700, '2026-10-01'), $this->row('B', 300, '2026-11-20'), $this->row('C', 50, null)]);

        $this->assertTrue($facts['overdue']);
        $this->assertSame(700, $facts['overdue_amount']);
        $this->assertSame(1050, $facts['amount_due']);
    }

    public function test_an_invoice_with_no_due_date_is_never_overdue(): void
    {
        $facts = $this->balance([$this->row('A', 700, null)]);

        $this->assertFalse($facts['overdue']);
        $this->assertNull($facts['first']['days_until_due']);
    }

    public function test_the_earliest_due_date_comes_first(): void
    {
        $facts = $this->balance([$this->row('LATE', 1, '2026-12-01'), $this->row('SOON', 1, '2026-11-09'), $this->row('NODATE', 1, null)]);

        $this->assertSame('SOON', $facts['first']['number']);
        $this->assertSame(3, $facts['first']['days_until_due']);
    }

    public function test_an_undated_invoice_waits_behind_every_dated_one(): void
    {
        $facts = $this->balance([$this->row('A-NODATE', 1, null), $this->row('Z-DATED', 1, '2030-01-01')]);

        $this->assertSame('Z-DATED', $facts['first']['number']);
    }

    public function test_equal_due_dates_break_on_the_invoice_number(): void
    {
        $facts = $this->balance([$this->row('INV-2', 1, '2026-11-15'), $this->row('INV-1', 1, '2026-11-15')]);

        $this->assertSame('INV-1', $facts['first']['number']);
    }

    public function test_the_first_invoice_keeps_its_own_facts(): void
    {
        $facts = $this->balance([$this->row('INV-1', 920000, '2026-11-15')]);

        $this->assertSame(['id' => 'id-INV-1', 'number' => 'INV-1', 'balance' => 920000, 'currency' => 'USD', 'due_date' => '2026-11-15', 'days_until_due' => 9], $facts['first']);
    }

    public function test_more_than_one_currency_has_no_honest_sum(): void
    {
        $facts = $this->balance([$this->row('A', 1000, '2026-11-01'), $this->row('B', 2000, '2026-11-20', 'EUR')]);

        $this->assertNull($facts['amount_due']);
        $this->assertNull($facts['currency']);
        $this->assertNull($facts['overdue_amount']);
        $this->assertTrue($facts['overdue']);
        $this->assertSame(2, $facts['invoice_count']);
    }

    /**
     * @param  list<array{id: string, number: string, balance: int, currency: string, due_date: string|null}>  $open
     * @return array<string, mixed>
     */
    private function balance(array $open): array
    {
        return (new PortalBalance($open, self::TODAY))->toArray();
    }

    /** @return array{id: string, number: string, balance: int, currency: string, due_date: string|null} */
    private function row(string $number, int $balance, ?string $due, string $currency = 'USD'): array
    {
        return ['id' => 'id-'.$number, 'number' => $number, 'balance' => $balance, 'currency' => $currency, 'due_date' => $due];
    }
}
