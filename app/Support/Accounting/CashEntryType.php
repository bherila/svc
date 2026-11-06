<?php

declare(strict_types=1);

namespace App\Support\Accounting;

use App\Support\Finance\ProcessorMovementKind;

/**
 * What an entry of the cash-income feed records: money received, and the
 * signed entries that return, withdraw or charge against it. A refund or a
 * dispute is its own entry beside the payment, never an edit of it.
 */
enum CashEntryType: string
{
    case Payment = 'payment';
    case Refund = 'refund';
    case RefundReversal = 'refund_reversal';
    case DisputeDebit = 'dispute_debit';
    case DisputeCredit = 'dispute_credit';
    case Fee = 'fee';
    case FeeReversal = 'fee_reversal';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    public function label(): string
    {
        return StoredValueLabel::of($this->value);
    }

    /** The entry type of a processor movement. */
    public static function ofMovement(ProcessorMovementKind $kind): self
    {
        return match ($kind) {
            ProcessorMovementKind::Refund => self::Refund,
            ProcessorMovementKind::RefundReversal => self::RefundReversal,
            ProcessorMovementKind::DisputeDebit => self::DisputeDebit,
            ProcessorMovementKind::DisputeCredit => self::DisputeCredit,
            ProcessorMovementKind::Fee => self::Fee,
            ProcessorMovementKind::FeeReversal => self::FeeReversal,
        };
    }
}
