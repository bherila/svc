<?php

namespace App\Support\Billing;

use Carbon\CarbonInterface;

/** Deterministic client-facing description for one cadence retainer sale. */
final class RetainerLineDescription
{
    public static function for(
        BillingCadence $cadence,
        float $hours,
        CarbonInterface $cycleStart,
        CarbonInterface $cycleEnd,
    ): string {
        return sprintf(
            '%s Retainer (%s hours) - %s through %s',
            BillingCadenceLabel::for($cadence),
            HoursQuantity::format($hours),
            $cycleStart->format('M j, Y'),
            $cycleEnd->format('M j, Y'),
        );
    }
}
