<?php

namespace App\Support\Finance;

/**
 * Where a processor payout stands, in svc's vocabulary: Stripe's words, which
 * Square's map onto ({@see PayoutFacts}).
 *
 * `paid` means the processor reports the money arrived; a `failed` payout
 * returned to the processor balance, and a `canceled` one never left it.
 */
enum PayoutStatus: string
{
    case Pending = 'pending';
    case InTransit = 'in_transit';
    case Paid = 'paid';
    case Failed = 'failed';
    case Canceled = 'canceled';

    /** @return list<string> */
    public static function all(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /**
     * How far along a payout is, for two reports stamped with the same time:
     * the one further along wins. A payout can fail after it was reported
     * paid, and is canceled only before it leaves, so the ends outrank `paid`.
     */
    public function progress(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::InTransit => 1,
            self::Paid => 2,
            self::Failed, self::Canceled => 3,
        };
    }
}
