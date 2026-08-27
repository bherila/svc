<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\Balances\OverpaymentLedger;
use PHPUnit\Framework\TestCase;

class OverpaymentLedgerTest extends TestCase
{
    public function test_empty_ledger_is_zero(): void
    {
        $ledger = OverpaymentLedger::empty();
        $this->assertSame([], $ledger->entries);
        $this->assertSame(0.0, $ledger->totalRemaining);
    }

    public function test_entries_are_preserved_readonly(): void
    {
        $ledger = new OverpaymentLedger(
            entries: [
                ['invoice_id' => 1, 'invoice_number' => 'X-1', 'overpaid' => 300.0, 'consumed' => 100.0, 'remaining' => 200.0],
            ],
            totalRemaining: 200.0,
        );

        $this->assertCount(1, $ledger->entries);
        $this->assertSame('X-1', $ledger->entries[0]['invoice_number']);
        $this->assertSame(200.0, $ledger->totalRemaining);
    }
}
