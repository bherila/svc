<?php

namespace App\Support\Billing;

final class BillingCadenceLabel
{
    public static function for(BillingCadence $cadence): string
    {
        return match ($cadence) {
            BillingCadence::Monthly => 'Monthly',
            BillingCadence::Quarterly => 'Quarterly',
            BillingCadence::SemiAnnual => 'Semiannual',
            BillingCadence::Annual => 'Annual',
        };
    }
}
