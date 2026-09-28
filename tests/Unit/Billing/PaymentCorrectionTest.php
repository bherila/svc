<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\PaymentCorrection;
use DomainException;
use PHPUnit\Framework\TestCase;

/** The parsing and diffing half of a payment correction, without a database. */
final class PaymentCorrectionTest extends TestCase
{
    public function test_only_named_fields_are_parsed_in_a_fixed_order(): void
    {
        $correction = PaymentCorrection::parse(['received_on' => ' 2026-08-18 ', 'reference' => '', 'method' => ' ach '], ' Synthetic ');

        $this->assertSame(['method' => 'ach', 'reference' => null, 'received_on' => '2026-08-18'], $correction->changes);
        $this->assertSame('Synthetic', $correction->reason);
    }

    public function test_the_date_only_door_carries_no_reason(): void
    {
        $this->assertNull(PaymentCorrection::parse(['received_on' => '2026-08-18'], null)->reason);
    }

    public function test_a_refused_field_is_named_even_beside_allowed_ones(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The payment field "refunded_amount" cannot be corrected here. A refund is recorded with setRefundedAmount');

        PaymentCorrection::parse(['method' => 'ach', 'refunded_amount' => 1], 'Synthetic');
    }

    public function test_the_diff_keeps_only_real_changes(): void
    {
        $current = ['method' => 'wire', 'reference' => 'SYN-1', 'notes' => null, 'received_on' => '2026-08-25'];

        $this->assertSame([], PaymentCorrection::diff($current, ['method' => 'wire', 'notes' => null]));
        $this->assertSame(
            ['reference' => ['old' => 'SYN-1', 'new' => null], 'received_on' => ['old' => '2026-08-25', 'new' => '2026-08-18']],
            PaymentCorrection::diff($current, ['received_on' => '2026-08-18', 'method' => 'wire', 'reference' => null]),
        );
    }

    public function test_a_note_is_excerpted_for_the_activity_and_nothing_else_is(): void
    {
        $long = str_repeat('n', 121);
        $diff = [
            'reference' => ['old' => str_repeat('r', 255), 'new' => null],
            'notes' => ['old' => str_repeat('o', 120), 'new' => $long],
        ];

        $this->assertSame([
            'reference' => ['old' => str_repeat('r', 255), 'new' => null],
            'notes' => ['old' => str_repeat('o', 120), 'new' => str_repeat('n', 120).'…'],
        ], PaymentCorrection::forActivity($diff));
    }
}
