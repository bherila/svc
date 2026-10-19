<?php

namespace App\Support\Finance;

/**
 * How a processor payout was started: on the processor's schedule
 * (`automatic`), by hand (`manual`), or as an instant payout, which carries a
 * fee of its own. Only an automatic payout's member payments can be read back
 * from Stripe, so the others carry no member payments in svc.
 */
enum PayoutType: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
    case Instant = 'instant';

    /** @return list<string> */
    public static function all(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
