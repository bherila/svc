<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\DisputeSettlement;
use PHPUnit\Framework\TestCase;

final class DisputeSettlementTest extends TestCase
{
    public function test_an_answered_row_bills_and_anyone_who_may_edit_it_answers(): void
    {
        $this->assertTrue(DisputeSettlement::Answered->bills());
        $this->assertFalse(DisputeSettlement::Answered->needsApprover());
        $this->assertSame('Answered', DisputeSettlement::Answered->label());
    }

    public function test_a_written_off_row_never_bills_and_only_an_approver_writes_it_off(): void
    {
        $this->assertFalse(DisputeSettlement::WrittenOff->bills());
        $this->assertTrue(DisputeSettlement::WrittenOff->needsApprover());
        $this->assertSame('Written off', DisputeSettlement::WrittenOff->label());
    }

    public function test_the_stored_values_are_the_api_outcomes(): void
    {
        $this->assertSame(['answered', 'written_off'], DisputeSettlement::values());
    }
}
