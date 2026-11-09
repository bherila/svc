<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\ClientApprovalVerdict;
use PHPUnit\Framework\TestCase;

final class ClientApprovalVerdictTest extends TestCase
{
    public function test_nothing_stored_is_pending(): void
    {
        $this->assertSame(ClientApprovalVerdict::Pending, ClientApprovalVerdict::judge(null, false, false, false, false));
        $this->assertSame(ClientApprovalVerdict::Pending, ClientApprovalVerdict::judge(null, false, false, true, false));
    }

    public function test_a_persons_approval_stands_whether_or_not_any_invoice_is_paid(): void
    {
        $this->assertSame(ClientApprovalVerdict::Approved, ClientApprovalVerdict::judge('portal', true, false, false, false));
        $this->assertSame(ClientApprovalVerdict::Approved, ClientApprovalVerdict::judge('portal', true, false, true, false));
    }

    public function test_a_reviewers_approval_stands_like_a_persons_whatever_the_invoice(): void
    {
        $this->assertSame(ClientApprovalVerdict::Approved, ClientApprovalVerdict::judge('reviewer', true, false, false, false));
        $this->assertSame(ClientApprovalVerdict::Approved, ClientApprovalVerdict::judge('reviewer', true, false, true, false));
        $this->assertSame(ClientApprovalVerdict::Pending, ClientApprovalVerdict::judge('reviewer', false, false, false, false));
        $this->assertSame(ClientApprovalVerdict::Disputed, ClientApprovalVerdict::judge('reviewer', true, true, false, false));
    }

    public function test_a_payment_approval_holds_only_while_the_invoice_is_paid(): void
    {
        $this->assertSame(ClientApprovalVerdict::ApprovedByPayment, ClientApprovalVerdict::judge('payment', true, false, true, false));
        $this->assertSame(ClientApprovalVerdict::Pending, ClientApprovalVerdict::judge('payment', true, false, false, false));
    }

    public function test_a_source_nobody_recognises_approves_nothing(): void
    {
        $this->assertSame(ClientApprovalVerdict::Pending, ClientApprovalVerdict::judge('import', true, false, true, false));
        $this->assertSame(ClientApprovalVerdict::Pending, ClientApprovalVerdict::judge(null, true, false, true, false));
    }

    public function test_an_approval_with_no_stamp_is_not_an_approval(): void
    {
        $this->assertSame(ClientApprovalVerdict::Pending, ClientApprovalVerdict::judge('portal', false, false, true, false));
    }

    public function test_a_dispute_outranks_every_approval(): void
    {
        $this->assertSame(ClientApprovalVerdict::Disputed, ClientApprovalVerdict::judge('portal', true, true, true, false));
        $this->assertSame(ClientApprovalVerdict::Disputed, ClientApprovalVerdict::judge('payment', true, true, true, false));
        $this->assertSame(ClientApprovalVerdict::Disputed, ClientApprovalVerdict::judge(null, false, true, false, false));
    }

    public function test_a_write_off_settles_the_row_for_good_whatever_else_is_stored(): void
    {
        $this->assertSame(ClientApprovalVerdict::WrittenOff, ClientApprovalVerdict::judge(null, false, false, false, true));
        $this->assertSame(ClientApprovalVerdict::WrittenOff, ClientApprovalVerdict::judge('portal', true, true, true, true));
    }

    public function test_which_verdicts_approve_and_which_await_the_client(): void
    {
        $approves = [
            'pending' => false, 'approved' => true, 'approved_by_payment' => true, 'disputed' => false, 'written_off' => false,
        ];
        $awaits = [
            'pending' => true, 'approved' => false, 'approved_by_payment' => false, 'disputed' => false, 'written_off' => false,
        ];
        foreach (ClientApprovalVerdict::cases() as $verdict) {
            $this->assertSame($approves[$verdict->value], $verdict->approves(), $verdict->value);
            $this->assertSame($awaits[$verdict->value], $verdict->awaitsClient(), $verdict->value);
        }
    }
}
