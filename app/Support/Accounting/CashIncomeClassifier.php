<?php

namespace App\Support\Accounting;

use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\PaymentRefundKind;
use App\Support\Finance\ProcessorMovementKind;

/**
 * Database-free classification of the three finance facts that make up cash
 * income: a payment, a hand-recorded refund (or its reversal) and a processor
 * movement. Callers load the facts with bounded, workspace-scoped queries and
 * hand over plain values; every amount is a signed integer of minor units.
 */
final class CashIncomeClassifier
{
    /**
     * @param  list<array{kind: PaymentRefundKind, amount: int}>  $refundLedger  the payment's hand-recorded refund history
     * @param  list<array{kind: ProcessorMovementKind, principal: int}>  $movements  the payment's processor movements
     */
    public static function payment(?InvoicePaymentStatus $status, int $amount, ?int $providerFeeAmount, int $refundedAmount, array $refundLedger, array $movements): CashAmounts
    {
        // A payment counts as received money in every state that once held it;
        // a pending, failed or canceled one never did. Exhaustive: no default.
        $received = match ($status) {
            InvoicePaymentStatus::Succeeded, InvoicePaymentStatus::Refunded, InvoicePaymentStatus::Disputed => $amount,
            InvoicePaymentStatus::Pending, InvoicePaymentStatus::Failed, InvoicePaymentStatus::Canceled, null => 0,
        };
        $ledger = 0;
        foreach ($refundLedger as $entry) {
            $ledger += self::signedRefund($entry['kind'], $entry['amount']);
        }
        $processor = 0;
        foreach ($movements as $movement) {
            $processor += self::refundPrincipal($movement['kind'], $movement['principal']);
        }

        return new CashAmounts('payment', $received, $providerFeeAmount ?? 0, $refundedAmount, $refundedAmount - $ledger + $processor);
    }

    /** A refund takes money out; the reversal of one puts it back. */
    public static function refund(PaymentRefundKind $kind, int $amount): CashAmounts
    {
        return match ($kind) {
            PaymentRefundKind::Refund => new CashAmounts('refund', -$amount, 0, 0, 0),
            PaymentRefundKind::Reversal => new CashAmounts('refund_reversal', $amount, 0, 0, 0),
        };
    }

    /** The processor reports its own signed principal and fee. */
    public static function movement(ProcessorMovementKind $kind, int $principal, int $fee): CashAmounts
    {
        return new CashAmounts($kind->value, $principal, $fee, 0, 0);
    }

    private static function signedRefund(PaymentRefundKind $kind, int $amount): int
    {
        return match ($kind) {
            PaymentRefundKind::Refund => $amount,
            PaymentRefundKind::Reversal => -$amount,
        };
    }

    /** Only refund principals explain a payment's refunded amount; fees, disputes and fee reversals do not. */
    private static function refundPrincipal(ProcessorMovementKind $kind, int $principal): int
    {
        return match ($kind) {
            ProcessorMovementKind::Refund, ProcessorMovementKind::RefundReversal => $principal,
            ProcessorMovementKind::DisputeDebit, ProcessorMovementKind::DisputeCredit, ProcessorMovementKind::Fee, ProcessorMovementKind::FeeReversal => 0,
        };
    }
}
