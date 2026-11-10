<?php

namespace App\Support\Billing;

/**
 * How frequently a recurring item is billed.
 *
 * Decoupled from the engagement's `BillingCadence`: e.g., a hosting fee can
 * be monthly while the overall engagement bills quarterly.
 */
enum ChargeCadence: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi_annual';
    case Annual = 'annual';
    case OneTime = 'one_time';

    /**
     * The months in one period of this cadence: how far apart its charges
     * fall. Null for a one-time charge, which recurs on none.
     */
    public function monthsInPeriod(): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::SemiAnnual => 6,
            self::Annual => 12,
            self::OneTime => null,
        };
    }
}
