<?php

namespace Tests\Unit\Accounting;

use App\Support\Accounting\IncomeYear;
use App\Support\Accounting\IncomeYearCsv;
use PHPUnit\Framework\TestCase;

final class IncomeYearTest extends TestCase
{
    /** @return array<string, mixed> */
    private function line(string $on, int $net, int $tax, string $type = 'adjustment'): array
    {
        return ['effective_on' => $on, 'net_amount' => $net, 'tax_amount' => $tax, 'total_amount' => $net + $tax, 'line_type' => $type];
    }

    /** @return array<string, mixed> */
    private function cash(string $on, string $type, int $amount, ?int $fee = null): array
    {
        return ['effective_on' => $on, 'entry_type' => $type, 'amount_minor' => $amount, 'fee_minor' => $fee];
    }

    public function test_a_year_has_twelve_zeroed_months_and_totals(): void
    {
        $report = (new IncomeYear(2030))->toArray();

        $this->assertSame(2030, $report['year']);
        $this->assertSame(['2030-01', '2030-02', '2030-03', '2030-04', '2030-05', '2030-06', '2030-07', '2030-08', '2030-09', '2030-10', '2030-11', '2030-12'], array_column($report['months'], 'month'));
        $this->assertSame(array_fill_keys(IncomeYear::COLUMNS, 0), $report['totals']);
        $this->assertSame(['month', ...IncomeYear::COLUMNS], array_keys($report['months'][0]));
        $this->assertSame(1, IncomeYear::METHOD_VERSION);
    }

    public function test_invoiced_money_is_summed_by_month_with_tax_apart_and_expenses_counted_on_their_own(): void
    {
        $year = new IncomeYear(2030);
        $year->addLine($this->line('2030-03-01', 100000, 8000, 'retainer'));
        $year->addLine($this->line('2030-03-31', 5000, 0, 'expense'));
        // A correction raises the retainer; a credit and a void reverse money in other months.
        $year->addLine($this->line('2030-04-15', 10000, 800, 'retainer'));
        $year->addLine($this->line('2030-04-30', -2000, 0, 'credit'));
        $year->addLine($this->line('2030-04-30', -5000, 0, 'expense'));
        // Outside the year, and an unknown type, which is "overage and other", not an expense.
        $year->addLine($this->line('2029-12-31', 999, 99));
        $year->addLine($this->line('2031-01-01', 999, 99));
        $year->addLine($this->line('2030-12-31', 700, 0, 'mystery_type'));

        $report = $year->toArray();

        $this->assertSame([105000, 8000, 113000, 5000], [$report['months'][2]['invoiced_net_minor'], $report['months'][2]['invoiced_tax_minor'], $report['months'][2]['invoiced_total_minor'], $report['months'][2]['expenses_passed_through_minor']]);
        $this->assertSame([3000, 800, 3800, -5000], [$report['months'][3]['invoiced_net_minor'], $report['months'][3]['invoiced_tax_minor'], $report['months'][3]['invoiced_total_minor'], $report['months'][3]['expenses_passed_through_minor']]);
        $this->assertSame([700, 0], [$report['months'][11]['invoiced_net_minor'], $report['months'][11]['expenses_passed_through_minor']]);
        $this->assertSame([108700, 8800, 117500, 0], [$report['totals']['invoiced_net_minor'], $report['totals']['invoiced_tax_minor'], $report['totals']['invoiced_total_minor'], $report['totals']['expenses_passed_through_minor']]);
    }

    public function test_cash_is_summed_by_month_with_refunds_disputes_and_fees_apart(): void
    {
        $year = new IncomeYear(2030);
        $year->addCash($this->cash('2030-05-02', 'payment', 20000, 610));
        $year->addCash($this->cash('2030-05-03', 'payment', 10000));
        $year->addCash($this->cash('2030-05-10', 'refund', -5000));
        $year->addCash($this->cash('2030-05-11', 'refund_reversal', 1000));
        $year->addCash($this->cash('2030-05-12', 'dispute_debit', -4000, 1500));
        $year->addCash($this->cash('2030-06-01', 'dispute_credit', 4000));
        $year->addCash($this->cash('2030-06-02', 'fee', 0, 300));
        $year->addCash($this->cash('2030-06-03', 'fee_reversal', 0, -100));
        // Outside the year.
        $year->addCash($this->cash('2031-01-01', 'payment', 777, 7));

        $report = $year->toArray();
        $may = $report['months'][4];
        $june = $report['months'][5];

        $this->assertSame([30000, -4000, -4000, 2110, 19890], [$may['cash_received_minor'], $may['refunds_minor'], $may['disputes_minor'], $may['fees_minor'], $may['net_cash_minor']]);
        $this->assertSame([0, 0, 4000, 200, 3800], [$june['cash_received_minor'], $june['refunds_minor'], $june['disputes_minor'], $june['fees_minor'], $june['net_cash_minor']]);
        $this->assertSame([30000, -4000, 0, 2310, 23690], [$report['totals']['cash_received_minor'], $report['totals']['refunds_minor'], $report['totals']['disputes_minor'], $report['totals']['fees_minor'], $report['totals']['net_cash_minor']]);
    }

    public function test_the_csv_carries_exactly_the_figures_of_the_report(): void
    {
        $year = new IncomeYear(2030);
        $year->addLine($this->line('2030-01-15', 100000, 8000, 'retainer'));
        $year->addCash($this->cash('2030-02-02', 'payment', 20000, 610));

        $lines = IncomeYearCsv::lines($year->toArray());

        $this->assertCount(14, $lines);
        $this->assertSame('month,invoiced_net_minor,invoiced_tax_minor,invoiced_total_minor,expenses_passed_through_minor,cash_received_minor,refunds_minor,disputes_minor,fees_minor,net_cash_minor', $lines[0]);
        $this->assertSame('2030-01,100000,8000,108000,0,0,0,0,0,0', $lines[1]);
        $this->assertSame('2030-02,0,0,0,0,20000,0,0,610,19390', $lines[2]);
        $this->assertSame('2030-12,0,0,0,0,0,0,0,0,0', $lines[12]);
        $this->assertSame('total,100000,8000,108000,0,20000,0,0,610,19390', $lines[13]);
    }
}
