<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\PaymentCorrection;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_an_unknown_field_is_refused_with_the_whole_allow_list(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The payment field "colour" cannot be corrected here. Only method, reference, notes, received_on can be corrected. Nothing has been changed.');

        PaymentCorrection::parse(['colour' => 'blue'], 'Synthetic');
    }

    public function test_a_refusal_ends_by_saying_nothing_changed(): void
    {
        try {
            PaymentCorrection::parse(['amount' => 1], 'Synthetic');
            $this->fail('An amount was accepted.');
        } catch (DomainException $exception) {
            $this->assertStringEndsWith('record the correct one. Nothing has been changed.', $exception->getMessage());
        }
    }

    public function test_text_fields_are_trimmed_and_kept(): void
    {
        $correction = PaymentCorrection::parse([
            'method' => str_repeat('é', 40),
            'reference' => ' '.str_repeat('ř', 255).' ',
            'notes' => "  Synthetic note\n",
        ], str_repeat('ü', 500));

        $this->assertSame([
            'method' => str_repeat('é', 40),
            'reference' => str_repeat('ř', 255),
            'notes' => 'Synthetic note',
        ], $correction->changes);
        $this->assertSame(str_repeat('ü', 500), $correction->reason);
    }

    public function test_the_length_bounds_are_inclusive(): void
    {
        $correction = PaymentCorrection::parse(['method' => str_repeat('m', 40)], str_repeat('r', 500));

        $this->assertSame(str_repeat('m', 40), $correction->changes['method']);
        $this->assertSame(str_repeat('r', 500), $correction->reason);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function refusedValues(): iterable
    {
        yield 'blank method' => [['method' => '   '], 'Synthetic', 'method is required'];
        yield 'method not text' => [['method' => 12], 'Synthetic', 'method is required'];
        yield 'method too long' => [['method' => str_repeat('m', 41)], 'Synthetic', 'method may not be longer than 40 characters.'];
        yield 'reason too long' => [['method' => 'ach'], str_repeat('r', 501), 'The reason may not be longer than 500 characters.'];
        yield 'reference too long' => [['reference' => str_repeat('r', 256)], 'Synthetic', 'reference may not be longer than 255 characters.'];
        yield 'blank reason' => [['method' => 'ach'], '  ', 'A reason is required'];
        yield 'nothing named' => [[], 'Synthetic', 'Name at least one of method, reference, notes, received_on to correct.'];
        yield 'date cleared' => [['received_on' => null], 'Synthetic', 'received_on cannot be cleared'];
        yield 'notes too long' => [['notes' => str_repeat('n', 10001)], 'Synthetic', 'notes may not be longer than 10000'];
        yield 'reference not text' => [['reference' => 5], 'Synthetic', 'reference must be text or null.'];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('refusedValues')]
    public function test_malformed_values_are_refused(array $changes, string $reason, string $message): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage($message);

        PaymentCorrection::parse($changes, $reason);
    }

    public function test_an_excerpt_counts_characters_not_bytes_and_keeps_the_start(): void
    {
        $exactly = str_repeat('é', 120);
        $longer = 'Ab'.str_repeat('é', 150);

        $this->assertSame(
            ['notes' => ['old' => $exactly, 'new' => 'Ab'.str_repeat('é', 118).'…']],
            PaymentCorrection::forActivity(['notes' => ['old' => $exactly, 'new' => $longer]]),
        );
        $this->assertSame(
            ['notes' => ['old' => null, 'new' => 'short']],
            PaymentCorrection::forActivity(['notes' => ['old' => null, 'new' => 'short']]),
        );
    }
}
