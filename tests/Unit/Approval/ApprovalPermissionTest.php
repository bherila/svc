<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\ApprovalPermission;
use PHPUnit\Framework\TestCase;

final class ApprovalPermissionTest extends TestCase
{
    public function test_an_explicit_grant_decides_in_either_direction(): void
    {
        $this->assertTrue(ApprovalPermission::allows(true, false));
        $this->assertFalse(ApprovalPermission::allows(false, true));
        $this->assertTrue(ApprovalPermission::allows(true, true));
        $this->assertFalse(ApprovalPermission::allows(false, false));
    }

    public function test_without_a_grant_the_billing_contact_may_and_nobody_else(): void
    {
        $this->assertTrue(ApprovalPermission::allows(null, true));
        $this->assertFalse(ApprovalPermission::allows(null, false));
    }

    public function test_the_billing_address_is_matched_without_regard_to_case_or_space(): void
    {
        $this->assertTrue(ApprovalPermission::isBillingContact('Ap@Example.test', 'ap@example.test'));
        $this->assertTrue(ApprovalPermission::isBillingContact(' ap@example.test ', ' AP@example.test '));
    }

    public function test_the_billing_field_may_list_several_addresses(): void
    {
        $billing = 'first@example.test, second@example.test;third@example.test fourth@example.test';

        foreach (['first', 'second', 'third', 'fourth'] as $name) {
            $this->assertTrue(ApprovalPermission::isBillingContact("{$name}@example.test", $billing), $name);
        }
        $this->assertFalse(ApprovalPermission::isBillingContact('fifth@example.test', $billing));
    }

    public function test_a_longer_or_shorter_address_is_not_the_same_address(): void
    {
        $this->assertFalse(ApprovalPermission::isBillingContact('ap@example.test', 'xap@example.test'));
        $this->assertFalse(ApprovalPermission::isBillingContact('xap@example.test', 'ap@example.test'));
        $this->assertFalse(ApprovalPermission::isBillingContact('ap@example.test', 'ap@example.test.au'));
    }

    public function test_an_empty_address_or_no_billing_address_matches_nothing(): void
    {
        $this->assertFalse(ApprovalPermission::isBillingContact('', ''));
        $this->assertFalse(ApprovalPermission::isBillingContact('  ', '  '));
        $this->assertFalse(ApprovalPermission::isBillingContact('ap@example.test', null));
        $this->assertFalse(ApprovalPermission::isBillingContact('', 'ap@example.test'));
    }
}
