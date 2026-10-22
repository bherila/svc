<?php

namespace App\Support\Finance;

/** Separate processor balance entries, never inferred from a payment status. */
enum ProcessorMovementKind: string
{
    case Refund = 'refund';
    case RefundReversal = 'refund_reversal';
    case DisputeDebit = 'dispute_debit';
    case DisputeCredit = 'dispute_credit';
    case Fee = 'fee';
    case FeeReversal = 'fee_reversal';

    /** @return list<string> */
    public static function all(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
