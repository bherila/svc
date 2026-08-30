<?php

namespace App\Support\Billing;

/** Deterministic client-facing description for cadence overage. */
final class CadenceOverageLineDescription
{
    public static function for(BillingCadence $cadence): string
    {
        return match ($cadence) {
            BillingCadence::Monthly => 'Catch-up hours for prior month overage and minimum availability',
            BillingCadence::Quarterly,
            BillingCadence::SemiAnnual,
            BillingCadence::Annual => 'Additional hours beyond cadence retainer',
        };
    }
}
