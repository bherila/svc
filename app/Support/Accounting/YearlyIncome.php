<?php

namespace App\Support\Accounting;

/** Pure aggregation shared by the CSV and JSON doors. */
final readonly class YearlyIncome
{
    /** @param list<array<string, mixed>> $months */
    public function __construct(public int $year, public array $months) {}

    /** @param iterable<array<string, mixed>> $lines
     * @param  iterable<array<string, mixed>>  $cash
     */
    public static function calculate(int $year, iterable $lines, iterable $cash): self
    {
        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $months[sprintf('%04d-%02d', $year, $month)] = ['month' => sprintf('%04d-%02d', $year, $month),
                'invoiced_net' => 0, 'invoiced_tax' => 0, 'cash_received' => 0, 'refunds' => 0,
                'refund_reversals' => 0, 'fees' => 0, 'expenses_passed_through' => 0,
                'dispute_movements' => 0, 'net_cash' => 0, 'unattributed_refunds' => 0];
        }
        foreach ($lines as $line) {
            $month = substr((string) $line['effective_on'], 0, 7);
            if (! isset($months[$month])) {
                continue;
            }
            $months[$month]['invoiced_net'] += (int) $line['net_amount'];
            $months[$month]['invoiced_tax'] += (int) $line['tax_amount'];
            if ($line['line_type'] === 'expense') {
                $months[$month]['expenses_passed_through'] += (int) $line['net_amount'];
            }
        }
        foreach ($cash as $row) {
            $month = substr((string) $row['effective_on'], 0, 7);
            if (! isset($months[$month])) {
                continue;
            }
            $amount = (int) $row['amount'];
            if ($row['kind'] === 'payment') {
                $months[$month]['cash_received'] += $amount;
                $months[$month]['unattributed_refunds'] += (int) $row['unattributed_refund_amount'];
            } elseif ($row['kind'] === 'refund') {
                $months[$month]['refunds'] -= $amount;
            } elseif ($row['kind'] === 'refund_reversal') {
                $months[$month]['refund_reversals'] += $amount;
            } elseif (in_array($row['kind'], ['dispute_debit', 'dispute_credit'], true)) {
                $months[$month]['dispute_movements'] += $amount;
            }
            $months[$month]['fees'] += (int) $row['fee_amount'];
            $months[$month]['net_cash'] += $amount - (int) $row['fee_amount'];
        }

        return new self($year, array_values($months));
    }

    /** @return array{data: array{year: int, months: list<array<string, mixed>>}} */
    public function toArray(): array
    {
        return ['data' => ['year' => $this->year, 'months' => $this->months]];
    }
}
