<?php

declare(strict_types=1);

namespace App\Support\Accounting;

use App\Support\Revenue\RevenueBand;

/**
 * One calendar year of income by month, accumulated from the entries of the
 * two accounting feeds and nothing else, so the yearly export cannot disagree
 * with the lists it summarises.
 *
 * - Invoiced: every `invoice_line`, `correction` and `void` entry by the
 *   month of its effective date, tax apart from the net. Pass-through
 *   expense lines are also counted on their own, as the part of the net that
 *   was billed at cost.
 * - Cash: payments by received month; refunds (refunds and their reversals)
 *   and disputes as the signed money they moved; fees as the processor's
 *   signed expense. Net cash is received plus refunds plus disputes less fees.
 *
 * Amounts are integer minor units of the workspace's single currency.
 */
final class IncomeYear
{
    public const int METHOD_VERSION = 1;

    public const array COLUMNS = [
        'invoiced_net_minor', 'invoiced_tax_minor', 'invoiced_total_minor', 'expenses_passed_through_minor',
        'cash_received_minor', 'refunds_minor', 'disputes_minor', 'fees_minor', 'net_cash_minor',
    ];

    /** @var array<string, array<string, int>> month (`Y-m`) => column => amount */
    private array $months = [];

    public function __construct(private readonly int $year)
    {
        for ($month = 1; $month <= 12; $month++) {
            $this->months[sprintf('%04d-%02d', $year, $month)] = array_fill_keys(self::COLUMNS, 0);
        }
    }

    /** @param array<string, mixed> $entry a payload of the invoice-line feed */
    public function addLine(array $entry): void
    {
        $month = $this->month($entry['effective_on']);
        if ($month === null) {
            return;
        }
        $net = $entry['net_amount'];
        $this->months[$month]['invoiced_net_minor'] += $net;
        $this->months[$month]['invoiced_tax_minor'] += $entry['tax_amount'];
        $this->months[$month]['invoiced_total_minor'] += $entry['total_amount'];
        if (RevenueBand::forStoredLineType($entry['line_type']) === RevenueBand::Expenses) {
            $this->months[$month]['expenses_passed_through_minor'] += $net;
        }
    }

    /** @param array<string, mixed> $entry a payload of the cash-income feed */
    public function addCash(array $entry): void
    {
        $month = $this->month($entry['effective_on']);
        if ($month === null) {
            return;
        }
        $amount = $entry['amount_minor'];
        $column = match (CashEntryType::from($entry['entry_type'])) {
            CashEntryType::Payment => 'cash_received_minor',
            CashEntryType::Refund, CashEntryType::RefundReversal => 'refunds_minor',
            CashEntryType::DisputeDebit, CashEntryType::DisputeCredit => 'disputes_minor',
            CashEntryType::Fee, CashEntryType::FeeReversal => null,
        };
        if ($column !== null) {
            $this->months[$month][$column] += $amount;
        }
        $this->months[$month]['fees_minor'] += $entry['fee_minor'] ?? 0;
    }

    /**
     * @return array{year: int, months: list<array<string, int|string>>, totals: array<string, int>}
     */
    public function toArray(): array
    {
        $totals = array_fill_keys(self::COLUMNS, 0);
        $months = [];
        foreach ($this->months as $month => $figures) {
            $figures['net_cash_minor'] = $figures['cash_received_minor'] + $figures['refunds_minor'] + $figures['disputes_minor'] - $figures['fees_minor'];
            foreach ($figures as $column => $amount) {
                $totals[$column] += $amount;
            }
            $months[] = ['month' => $month, ...$figures];
        }

        return ['year' => $this->year, 'months' => $months, 'totals' => $totals];
    }

    /** The month an effective date falls in, when it falls in this year. */
    private function month(mixed $date): ?string
    {
        $month = substr((string) $date, 0, 7);

        return array_key_exists($month, $this->months) ? $month : null;
    }
}
