<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\SelfApproval;
use PHPUnit\Framework\TestCase;

final class SelfApprovalTest extends TestCase
{
    public function test_the_author_is_refused(): void
    {
        $this->assertTrue(SelfApproval::refuses(7, 7));
    }

    public function test_another_person_is_not(): void
    {
        $this->assertFalse(SelfApproval::refuses(7, 8));
        $this->assertFalse(SelfApproval::refuses(8, 7));
    }

    public function test_an_unknown_author_refuses_nobody(): void
    {
        $this->assertFalse(SelfApproval::refuses(null, 7));
    }

    public function test_an_id_of_zero_is_still_an_id(): void
    {
        $this->assertTrue(SelfApproval::refuses(0, 0));
        $this->assertFalse(SelfApproval::refuses(0, 1));
    }

    public function test_the_messages_say_who_approves_instead(): void
    {
        $this->assertStringContainsString('draft invoice', SelfApproval::TIME_MESSAGE);
        $this->assertStringContainsString('client approves', SelfApproval::EXPENSE_MESSAGE);
    }
}
