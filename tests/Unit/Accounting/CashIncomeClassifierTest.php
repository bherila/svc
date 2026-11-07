<?php

namespace Tests\Unit\Accounting;

use App\Support\Accounting\CashAmounts;
use App\Support\Accounting\CashIncomeClassifier;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\PaymentRefundKind;
use App\Support\Finance\ProcessorMovementKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Cash classification needs no database: plain facts in, signed integer minor units out. */
final class CashIncomeClassifierTest extends TestCase
{
    /** @return iterable<string, array{?InvoicePaymentStatus, int}> */
    public static function statuses(): iterable
    {
        yield 'succeeded counts' => [InvoicePaymentStatus::Succeeded, 11000];
        yield 'refunded still counts as received' => [InvoicePaymentStatus::Refunded, 11000];
        yield 'disputed still counts as received' => [InvoicePaymentStatus::Disputed, 11000];
        yield 'pending never held money' => [InvoicePaymentStatus::Pending, 0];
        yield 'failed never held money' => [InvoicePaymentStatus::Failed, 0];
        yield 'canceled never held money' => [InvoicePaymentStatus::Canceled, 0];
        yield 'an unreadable status counts nothing' => [null, 0];
    }

    #[DataProvider('statuses')]
    public function test_only_states_that_held_money_contribute_the_received_amount(?InvoicePaymentStatus $status, int $expected): void
    {
        $this->assertSame($expected, CashIncomeClassifier::payment($status, 11000, null, 0, [], [])->amount);
    }

    public function test_a_payment_row_carries_its_kind_fee_and_refunded_amount(): void
    {
        $row = CashIncomeClassifier::payment(InvoicePaymentStatus::Succeeded, 11000, 150, 1000, [], []);

        $this->assertSame('payment', $row->kind);
        $this->assertSame(150, $row->feeAmount);
        $this->assertSame(1000, $row->refundedAmount);
    }

    public function test_a_missing_provider_fee_is_zero_and_a_zero_fee_stays_zero(): void
    {
        $this->assertSame(0, CashIncomeClassifier::payment(InvoicePaymentStatus::Succeeded, 100, null, 0, [], [])->feeAmount);
        $this->assertSame(0, CashIncomeClassifier::payment(InvoicePaymentStatus::Succeeded, 100, 0, 0, [], [])->feeAmount);
    }

    public function test_a_partial_refund_fully_recorded_in_the_ledger_leaves_nothing_unattributed(): void
    {
        $row = CashIncomeClassifier::payment(InvoicePaymentStatus::Succeeded, 11000, null, 5000, [
            ['kind' => PaymentRefundKind::Refund, 'amount' => 4000],
            ['kind' => PaymentRefundKind::Refund, 'amount' => 1000],
        ], []);

        $this->assertSame(5000, $row->refundedAmount);
        $this->assertSame(0, $row->unattributedRefundAmount);
    }

    public function test_refunded_money_without_dated_evidence_is_unattributed(): void
    {
        $this->assertSame(1000, CashIncomeClassifier::payment(InvoicePaymentStatus::Succeeded, 11000, null, 1000, [], [])->unattributedRefundAmount);
        $this->assertSame(600, CashIncomeClassifier::payment(InvoicePaymentStatus::Succeeded, 11000, null, 1000, [['kind' => PaymentRefundKind::Refund, 'amount' => 400]], [])->unattributedRefundAmount);
    }

    public function test_a_reversal_takes_a_refund_back_out_of_the_ledger(): void
    {
        $row = CashIncomeClassifier::payment(InvoicePaymentStatus::Succeeded, 11000, null, 1000, [
            ['kind' => PaymentRefundKind::Refund, 'amount' => 4000],
            ['kind' => PaymentRefundKind::Refund, 'amount' => 1000],
            ['kind' => PaymentRefundKind::Reversal, 'amount' => 4000],
        ], []);

        // 1000 refunded, 1000 evidenced (4000 + 1000 - 4000).
        $this->assertSame(0, $row->unattributedRefundAmount);
    }

    public function test_processor_refund_principals_explain_the_refunded_amount(): void
    {
        $row = CashIncomeClassifier::payment(InvoicePaymentStatus::Succeeded, 9000, 100, 500, [], [
            ['kind' => ProcessorMovementKind::Refund, 'principal' => -500],
        ]);

        $this->assertSame(0, $row->unattributedRefundAmount);

        $reversed = CashIncomeClassifier::payment(InvoicePaymentStatus::Succeeded, 9000, null, 0, [], [
            ['kind' => ProcessorMovementKind::Refund, 'principal' => -500],
            ['kind' => ProcessorMovementKind::RefundReversal, 'principal' => 500],
        ]);
        $this->assertSame(0, $reversed->unattributedRefundAmount);
    }

    public function test_only_refund_movements_count_toward_the_refund_evidence(): void
    {
        $movements = array_map(
            static fn (ProcessorMovementKind $kind): array => ['kind' => $kind, 'principal' => -700],
            [ProcessorMovementKind::DisputeDebit, ProcessorMovementKind::DisputeCredit, ProcessorMovementKind::Fee, ProcessorMovementKind::FeeReversal],
        );

        $this->assertSame(300, CashIncomeClassifier::payment(InvoicePaymentStatus::Succeeded, 9000, null, 300, [], $movements)->unattributedRefundAmount);
    }

    public function test_a_canceled_payment_counts_no_money_but_keeps_its_known_fee(): void
    {
        $row = CashIncomeClassifier::payment(InvoicePaymentStatus::Canceled, 5000, 25, 0, [], []);

        $this->assertSame(0, $row->amount);
        $this->assertSame(25, $row->feeAmount);
    }

    public function test_a_refund_is_a_negative_amount_and_its_reversal_a_positive_one(): void
    {
        $this->assertEquals(new CashAmounts('refund', -4000, 0, 0, 0), CashIncomeClassifier::refund(PaymentRefundKind::Refund, 4000));
        $this->assertEquals(new CashAmounts('refund_reversal', 4000, 0, 0, 0), CashIncomeClassifier::refund(PaymentRefundKind::Reversal, 4000));
    }

    public function test_a_refund_of_zero_is_zero_not_negative_zero(): void
    {
        $this->assertSame(0, CashIncomeClassifier::refund(PaymentRefundKind::Refund, 0)->amount);
    }

    /** @return iterable<string, array{ProcessorMovementKind, int, int}> */
    public static function movements(): iterable
    {
        yield 'a refund takes principal out and its fee stays' => [ProcessorMovementKind::Refund, -500, 10];
        yield 'a reversal brings principal back' => [ProcessorMovementKind::RefundReversal, 500, 0];
        yield 'a dispute debit' => [ProcessorMovementKind::DisputeDebit, -2500, 1500];
        yield 'a dispute credit' => [ProcessorMovementKind::DisputeCredit, 2500, 0];
        yield 'a fee has no principal' => [ProcessorMovementKind::Fee, 0, 30];
        yield 'a fee reversal gives the fee back' => [ProcessorMovementKind::FeeReversal, 0, -30];
    }

    #[DataProvider('movements')]
    public function test_a_movement_keeps_the_processors_signed_principal_and_fee(ProcessorMovementKind $kind, int $principal, int $fee): void
    {
        $this->assertEquals(new CashAmounts($kind->value, $principal, $fee, 0, 0), CashIncomeClassifier::movement($kind, $principal, $fee));
    }

    public function test_ledger_entries_do_not_leak_between_calls(): void
    {
        $with = CashIncomeClassifier::payment(InvoicePaymentStatus::Succeeded, 100, null, 50, [['kind' => PaymentRefundKind::Refund, 'amount' => 50]], []);
        $without = CashIncomeClassifier::payment(InvoicePaymentStatus::Succeeded, 100, null, 50, [], []);

        $this->assertSame(0, $with->unattributedRefundAmount);
        $this->assertSame(50, $without->unattributedRefundAmount);
    }
}
