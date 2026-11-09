<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\DisputeHold;
use PHPUnit\Framework\TestCase;

final class DisputeHoldTest extends TestCase
{
    public function test_a_disputed_row_no_charged_invoice_carries_is_held(): void
    {
        $this->assertTrue(DisputeHold::holds(disputed: true, onChargedInvoice: false));
    }

    public function test_a_charge_already_issued_stands_until_a_correction(): void
    {
        $this->assertFalse(DisputeHold::holds(disputed: true, onChargedInvoice: true));
    }

    public function test_an_undisputed_row_is_never_held_whether_or_not_it_was_approved(): void
    {
        $this->assertFalse(DisputeHold::holds(disputed: false, onChargedInvoice: false));
        $this->assertFalse(DisputeHold::holds(disputed: false, onChargedInvoice: true));
    }
}
