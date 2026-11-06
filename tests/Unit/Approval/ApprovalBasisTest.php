<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\ApprovalBasis;
use App\Support\Approval\ApprovalPermission;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApprovalBasisTest extends TestCase
{
    /** @return array<string, array{0: bool|null, 1: bool, 2: ApprovalBasis}> */
    public static function boundary(): array
    {
        return [
            'granted, not the billing contact' => [true, false, ApprovalBasis::Granted],
            'granted, the billing contact' => [true, true, ApprovalBasis::Granted],
            'denied, not the billing contact' => [false, false, ApprovalBasis::Denied],
            'denied, the billing contact' => [false, true, ApprovalBasis::Denied],
            'no grant, the billing contact' => [null, true, ApprovalBasis::BillingContact],
            'no grant, anyone else' => [null, false, ApprovalBasis::NotBillingContact],
        ];
    }

    #[DataProvider('boundary')]
    public function test_the_basis_always_agrees_with_the_decision(?bool $granted, bool $isBillingContact, ApprovalBasis $expected): void
    {
        $basis = ApprovalPermission::basis($granted, $isBillingContact);

        $this->assertSame($expected, $basis);
        $this->assertSame(ApprovalPermission::allows($granted, $isBillingContact), $basis->allows());
    }

    public function test_each_basis_prints_its_own_effective_state(): void
    {
        $this->assertSame([
            'granted' => 'Can approve (set by a manager)',
            'denied' => 'Cannot approve (set by a manager)',
            'billing_contact' => 'Billing contact (default)',
            'not_billing_contact' => 'Not the billing contact (default)',
        ], array_combine(
            array_map(fn (ApprovalBasis $basis): string => $basis->value, ApprovalBasis::cases()),
            array_map(fn (ApprovalBasis $basis): string => $basis->label(), ApprovalBasis::cases()),
        ));
    }
}
