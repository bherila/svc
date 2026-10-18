<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\ManualPaymentChange;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The transition table of a hand-recorded payment (#455), every row of it,
 * without a database.
 */
final class ManualPaymentChangeTest extends TestCase
{
    /** @return iterable<string, array{string, int, string, string|null}> */
    public static function statusChanges(): iterable
    {
        // [current status, refunded so far, target, the result or null for refused]
        yield 'pending clears' => ['pending', 0, 'succeeded', 'succeeded'];
        yield 'pending bounces' => ['pending', 0, 'failed', 'failed'];
        yield 'pending withdrawn' => ['pending', 0, 'canceled', 'canceled'];
        yield 'succeeded bounces' => ['succeeded', 0, 'failed', 'failed'];
        yield 'succeeded withdrawn' => ['succeeded', 0, 'canceled', 'canceled'];
        yield 'partly refunded cannot fail' => ['succeeded', 100, 'failed', null];
        yield 'partly refunded cannot be withdrawn' => ['succeeded', 100, 'canceled', null];
        yield 'failed is final' => ['failed', 0, 'succeeded', null];
        yield 'failed cannot be withdrawn' => ['failed', 0, 'canceled', null];
        yield 'canceled is final' => ['canceled', 0, 'succeeded', null];
        yield 'canceled cannot fail' => ['canceled', 0, 'failed', null];
        yield 'refunded is final' => ['refunded', 1000, 'succeeded', null];
        yield 'refunded cannot fail' => ['refunded', 1000, 'failed', null];
        yield 'disputed is the processor\'s' => ['disputed', 0, 'succeeded', null];
    }

    #[DataProvider('statusChanges')]
    public function test_each_status_transition(string $current, int $refunded, string $target, ?string $result): void
    {
        $change = ManualPaymentChange::status($target, 'Synthetic reason');
        if ($result === null) {
            $this->expectException(DomainException::class);
        }

        $decision = $change->decide($current, 1000, $refunded, null);
        $this->assertSame(['status' => InvoicePaymentStatus::from((string) $result), 'refunded_amount' => $refunded], $decision);
    }

    /** @return iterable<string, array{string, int}> */
    public static function unchanged(): iterable
    {
        yield 'already succeeded' => ['succeeded', 100];
        yield 'already failed' => ['failed', 0];
        yield 'already canceled' => ['canceled', 0];
    }

    /** Setting the status a payment already has changes nothing, even where moving to it would be refused. */
    #[DataProvider('unchanged')]
    public function test_setting_the_current_status_is_no_change(string $status, int $refunded): void
    {
        $this->assertNull(ManualPaymentChange::status($status, 'Synthetic reason')->decide($status, 1000, $refunded, null));
    }

    /** @return iterable<string, array{string, int, int, array{0: string, 1: int}|null}> */
    public static function refunds(): iterable
    {
        yield 'part of a succeeded payment' => ['succeeded', 0, 400, ['succeeded', 400]];
        yield 'the rest of a partly refunded one' => ['succeeded', 400, 600, ['refunded', 1000]];
        yield 'the whole amount at once' => ['succeeded', 0, 1000, ['refunded', 1000]];
        yield 'more than is left' => ['succeeded', 400, 601, null];
        yield 'an already refunded payment' => ['refunded', 1000, 1, null];
        yield 'a pending payment' => ['pending', 0, 1, null];
        yield 'a failed payment' => ['failed', 0, 1, null];
        yield 'a canceled payment' => ['canceled', 0, 1, null];
        yield 'a disputed payment' => ['disputed', 0, 1, null];
    }

    /** @param array{0: string, 1: int}|null $result */
    #[DataProvider('refunds')]
    public function test_each_refund(string $current, int $refunded, int $amount, ?array $result): void
    {
        $change = ManualPaymentChange::refund($amount, 'Synthetic reason');
        $this->assertTrue($change->isRefund());
        if ($result === null) {
            $this->expectException(DomainException::class);
        }

        $decision = $change->decide($current, 1000, $refunded, null);
        $this->assertSame(['status' => InvoicePaymentStatus::from($result[0] ?? ''), 'refunded_amount' => $result[1] ?? 0], $decision);
    }

    public function test_a_processor_payment_is_refused_whatever_the_change(): void
    {
        foreach ([ManualPaymentChange::status('failed', 'Synthetic'), ManualPaymentChange::refund(1, 'Synthetic')] as $change) {
            try {
                $change->decide('succeeded', 1000, 0, 'stripe');
                $this->fail('A processor payment must follow its processor.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('stripe', $exception->getMessage());
            }
        }
    }

    public function test_an_unreadable_status_is_refused(): void
    {
        $this->expectException(DomainException::class);
        ManualPaymentChange::status('failed', 'Synthetic')->decide('paid', 1000, 0, null);
    }

    /** @return iterable<string, array{mixed}> */
    public static function unsettable(): iterable
    {
        yield 'pending' => ['pending'];
        yield 'refunded' => ['refunded'];
        yield 'disputed' => ['disputed'];
        yield 'unknown' => ['paid'];
        yield 'not text' => [1];
        yield 'nothing' => [null];
    }

    #[DataProvider('unsettable')]
    public function test_only_succeeded_failed_and_canceled_can_be_set(mixed $status): void
    {
        $this->expectException(DomainException::class);
        ManualPaymentChange::status($status, 'Synthetic');
    }

    /** @return iterable<string, array{mixed}> */
    public static function badReasons(): iterable
    {
        yield 'missing' => [null];
        yield 'blank' => ['   '];
        yield 'too long' => [str_repeat('r', ManualPaymentChange::REASON_MAX_LENGTH + 1)];
        yield 'not text' => [12];
    }

    #[DataProvider('badReasons')]
    public function test_a_reason_is_required_and_bounded(mixed $reason): void
    {
        $this->expectException(DomainException::class);
        ManualPaymentChange::status('failed', $reason);
    }

    public function test_a_reason_is_trimmed_and_may_be_its_full_length(): void
    {
        $this->assertSame('Synthetic', ManualPaymentChange::status('failed', '  Synthetic  ')->reason);
        $full = str_repeat('r', ManualPaymentChange::REASON_MAX_LENGTH);
        $this->assertSame($full, ManualPaymentChange::refund(1, $full)->reason);
    }

    /** @return iterable<string, array{mixed}> */
    public static function badAmounts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'fractional' => [1.5];
        yield 'text' => ['100'];
    }

    #[DataProvider('badAmounts')]
    public function test_a_refund_is_a_positive_whole_number_of_minor_units(mixed $amount): void
    {
        $this->expectException(DomainException::class);
        ManualPaymentChange::refund($amount, 'Synthetic');
    }

    public function test_the_settable_statuses_are_listed_for_the_contract(): void
    {
        $this->assertSame(['succeeded', 'failed', 'canceled'], ManualPaymentChange::settableValues());
        $this->assertFalse(ManualPaymentChange::status('canceled', 'Synthetic')->isRefund());
    }
}
