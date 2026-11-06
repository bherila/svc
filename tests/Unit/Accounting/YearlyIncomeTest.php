<?php

namespace Tests\Unit\Accounting;

use App\Support\Accounting\InvoiceLineFacts;
use App\Support\Accounting\YearlyIncome;
use PHPUnit\Framework\TestCase;

final class YearlyIncomeTest extends TestCase
{
    public function test_reversals_preserve_identity_and_tax_rate_and_negate_only_money(): void
    {
        $original = new InvoiceLineFacts(['line_id' => 'synthetic-line', 'net_amount' => 1200, 'tax_amount' => 120, 'total_amount' => 1320,
            'kind' => 'issued', 'reverses_id' => null, 'tax_rate' => 10.0, 'taxable' => true]);
        $reversed = $original->reverse('void', 'synthetic-original');
        $this->assertSame(['line_id' => 'synthetic-line', 'net_amount' => -1200, 'tax_amount' => -120, 'total_amount' => -1320,
            'kind' => 'void', 'reverses_id' => 'synthetic-original', 'tax_rate' => 10.0, 'taxable' => true], $reversed->data);
        $this->assertSame(1200, $original->data['net_amount']);
    }

    public function test_yearly_figures_classify_signed_entries_without_database_reads(): void
    {
        $line = ['effective_on' => '2026-02-05', 'net_amount' => 1000, 'tax_amount' => 100, 'line_type' => 'expense'];
        $payment = ['effective_on' => '2026-03-10', 'kind' => 'payment', 'amount' => 1100, 'fee_amount' => 25, 'unattributed_refund_amount' => 12];
        $result = YearlyIncome::calculate(2026, [$line, [...$line, 'net_amount' => -400, 'tax_amount' => -40], [...$line, 'effective_on' => '2025-02-05']], [
            $payment, [...$payment, 'kind' => 'refund', 'amount' => -200, 'fee_amount' => 5],
            [...$payment, 'kind' => 'refund_reversal', 'amount' => 100, 'fee_amount' => -2],
            [...$payment, 'kind' => 'fee', 'amount' => 0, 'fee_amount' => 10],
            [...$payment, 'kind' => 'dispute_debit', 'amount' => -50, 'fee_amount' => 0],
            [...$payment, 'kind' => 'dispute_credit', 'amount' => 30, 'fee_amount' => 0],
            [...$payment, 'effective_on' => '2027-01-01'],
        ])->toArray();
        $this->assertSame(2026, $result['data']['year']);
        $months = $result['data']['months'];
        $this->assertCount(12, $months);
        $this->assertSame('2026-01', $months[0]['month']);
        $this->assertSame('2026-12', $months[11]['month']);
        $this->assertSame(0, $months[0]['invoiced_net']);
        $this->assertSame(600, $months[1]['invoiced_net']);
        $this->assertSame(60, $months[1]['invoiced_tax']);
        $this->assertSame(600, $months[1]['expenses_passed_through']);
        $this->assertSame(['month' => '2026-03', 'invoiced_net' => 0, 'invoiced_tax' => 0, 'cash_received' => 1100,
            'refunds' => 200, 'refund_reversals' => 100, 'fees' => 38, 'expenses_passed_through' => 0,
            'dispute_movements' => -20, 'net_cash' => 942, 'unattributed_refunds' => 12], $months[2]);
    }
}
