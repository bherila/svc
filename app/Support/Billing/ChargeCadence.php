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
}
