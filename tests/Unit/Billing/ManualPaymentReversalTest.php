<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\ManualPaymentChange;
use App\Support\Billing\PaymentRefundKind;
use App\Support\Billing\RecordedRefund;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reversing a refund recorded in error (#472): the rule, every refusal of it,
 * without a database.
 */
final class ManualPaymentReversalTest extends TestCase
{
    private const string REFUND = 'abcdef11-1111-4111-8111-11111111abcd';

    private const string REVERSAL = '22222222-2222-4222-8222-222222222222';

    /** @return iterable<string, array{string, int, int|null, int, array{string, int}}> */
    public static function reversals(): iterable
    {
        // [status, refunded so far, amount asked (null: all), the refund's amount, [status, refunded after]]
        yield 'a whole refund of a whole payment' => ['refunded', 1000, null, 1000, ['succeeded', 0]];
        yield 'a whole refund of part of a payment' => ['succeeded', 400, null, 400, ['succeeded', 0]];
        yield 'part of a refund' => ['succeeded', 400, 150, 400, ['succeeded', 250]];
        yield 'all of a refund, named' => ['succeeded', 400, 400, 400, ['succeeded', 0]];
        yield 'one of two refunds of a fully refunded payment' => ['refunded', 1000, null, 300, ['succeeded', 700]];
        yield 'one unit of a fully refunded payment' => ['refunded', 1000, 1, 600, ['succeeded', 999]];
    }

    /** @param array{string, int} $result */
    #[DataProvider('reversals')]
    public function test_each_reversal(string $status, int $refunded, ?int $amount, int $refundAmount, array $result): void
    {
        $change = ManualPaymentChange::reversal(self::REFUND, $amount, 'Synthetic refund entered twice');
        $target = new RecordedRefund(self::REFUND, PaymentRefundKind::Refund, $refundAmount, null);

        $this->assertSame(['status' => InvoicePaymentStatus::from($result[0]), 'refunded_amount' => $result[1]], $change->decide($status, 1000, $refunded, null, $target));
        $this->assertTrue($change->isReversal());
        $this->assertFalse($change->isRefund());
        $this->assertNull($change->status);
        $this->assertSame(self::REFUND, $change->reverses);
        $this->assertSame($amount, $change->reversal);
    }

    public function test_the_refund_is_named_case_insensitively(): void
    {
        $this->assertSame(self::REFUND, ManualPaymentChange::reversal(strtoupper(self::REFUND), null, 'Synthetic')->reverses);
        $this->assertFalse(ManualPaymentChange::refund(1, 'Synthetic')->isReversal());
        $this->assertFalse(ManualPaymentChange::status('failed', 'Synthetic')->isReversal());
    }

    /**
     * @return iterable<string, array{callable(): mixed, string}>
     */
    public static function refusals(): iterable
    {
        $refund = new RecordedRefund(self::REFUND, PaymentRefundKind::Refund, 400, null);
        $change = ManualPaymentChange::reversal(self::REFUND, null, 'Synthetic');

        yield 'a processor payment' => [static fn () => $change->decide('refunded', 1000, 1000, 'stripe', $refund), 'This payment was recorded by stripe, and its status and refunds follow stripe. Only a payment recorded by hand can be changed here. Nothing has been changed.'];
        yield 'an unreadable status' => [static fn () => $change->decide('paid', 1000, 400, null, $refund), 'The payment carries the unrecognised status "paid", so what it is worth cannot be established. Classify or correct it first. Nothing has been changed.'];
        yield 'a pending payment' => [static fn () => $change->decide('pending', 1000, 400, null, $refund), 'A pending payment has no refund to reverse. Nothing has been changed.'];
        yield 'a failed payment' => [static fn () => $change->decide('failed', 1000, 400, null, $refund), 'A failed payment has no refund to reverse. Nothing has been changed.'];
        yield 'a canceled payment' => [static fn () => $change->decide('canceled', 1000, 400, null, $refund), 'A canceled payment has no refund to reverse. Nothing has been changed.'];
        yield 'a disputed payment' => [static fn () => $change->decide('disputed', 1000, 400, null, $refund), 'A disputed payment has no refund to reverse. Nothing has been changed.'];
        yield 'no such refund' => [static fn () => $change->decide('succeeded', 1000, 400, null, null), 'This payment has no refund with that id. Nothing has been changed.'];
        yield 'a reversal' => [static fn () => $change->decide('succeeded', 1000, 400, null, new RecordedRefund(self::REFUND, PaymentRefundKind::Reversal, 400, null)), 'That entry is itself a reversal and cannot be reversed. If the money was returned after all, record the refund again. Nothing has been changed.'];
        yield 'a refund reversed already' => [static fn () => $change->decide('succeeded', 1000, 400, null, new RecordedRefund(self::REFUND, PaymentRefundKind::Refund, 400, self::REVERSAL)), 'That refund has already been reversed, by '.self::REVERSAL.'. Nothing has been changed.'];
        yield 'more than the refund' => [static fn () => ManualPaymentChange::reversal(self::REFUND, 401, 'Synthetic')->decide('succeeded', 1000, 800, null, $refund), 'That refund was 400, so at most 400 of it can be reversed. Nothing has been changed.'];
        yield 'more than is refunded' => [static fn () => $change->decide('succeeded', 1000, 399, null, $refund), 'Only 399 of this payment is recorded as refunded, so 400 cannot be reversed. Nothing has been changed.'];
        yield 'no refund named' => [static fn () => ManualPaymentChange::reversal('not-a-uuid', null, 'Synthetic'), 'A reversal names the refund it reverses by its id. Nothing has been changed.'];
        yield 'a refund named by number' => [static fn () => ManualPaymentChange::reversal(7, null, 'Synthetic'), 'A reversal names the refund it reverses by its id. Nothing has been changed.'];
        yield 'nothing reversed' => [static fn () => ManualPaymentChange::reversal(self::REFUND, 0, 'Synthetic'), 'A reversal amount is a whole number of minor units, at least 1. Nothing has been changed.'];
        yield 'an amount as text' => [static fn () => ManualPaymentChange::reversal(self::REFUND, '100', 'Synthetic'), 'A reversal amount is a whole number of minor units, at least 1. Nothing has been changed.'];
        yield 'a fractional amount' => [static fn () => ManualPaymentChange::reversal(self::REFUND, 1.5, 'Synthetic'), 'A reversal amount is a whole number of minor units, at least 1. Nothing has been changed.'];
        yield 'no reason' => [static fn () => ManualPaymentChange::reversal(self::REFUND, null, '  '), 'A reason is required to change a payment. Nothing has been changed.'];
    }

    /** @param callable(): mixed $attempt */
    #[DataProvider('refusals')]
    public function test_each_refusal_says_why(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail('The reversal must be refused.');
        } catch (DomainException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    /** A payment's refund history is bounded: the last entry it may hold is allowed, the next refused. */
    public function test_a_payment_holds_a_bounded_history(): void
    {
        $this->assertSame(50, ManualPaymentChange::MAX_REFUND_ENTRIES);
        $changes = [ManualPaymentChange::refund(100, 'Synthetic'), ManualPaymentChange::reversal(self::REFUND, null, 'Synthetic')];
        foreach ($changes as $change) {
            $change->assertHistoryHasRoom(ManualPaymentChange::MAX_REFUND_ENTRIES - 1);
        }
        // A status change writes no entry, so the bound does not apply to it.
        ManualPaymentChange::status('failed', 'Synthetic')->assertHistoryHasRoom(ManualPaymentChange::MAX_REFUND_ENTRIES);

        foreach ($changes as $change) {
            try {
                $change->assertHistoryHasRoom(ManualPaymentChange::MAX_REFUND_ENTRIES);
                $this->fail('A full history must refuse another entry.');
            } catch (DomainException $exception) {
                $this->assertSame('This payment already holds 50 refunds and reversals, the most one payment can. Nothing has been changed.', $exception->getMessage());
            }
        }
    }

    /** A status change or a refund ignores whatever entry it is handed. */
    public function test_only_a_reversal_reads_the_entry(): void
    {
        $reversed = new RecordedRefund(self::REFUND, PaymentRefundKind::Refund, 400, self::REVERSAL);

        $this->assertSame(['status' => InvoicePaymentStatus::Succeeded, 'refunded_amount' => 500], ManualPaymentChange::refund(100, 'Synthetic')->decide('succeeded', 1000, 400, null, $reversed));
        $this->assertSame(['status' => InvoicePaymentStatus::Failed, 'refunded_amount' => 0], ManualPaymentChange::status('failed', 'Synthetic')->decide('succeeded', 1000, 0, null, $reversed));
    }
}
