<?php

namespace App\Support\Billing;

/**
 * Does a charged invoice's `hours_billed_at_rate` say what its lines charge?
 *
 * The capacity ledger reads only the recorded figure, so a charged invoice
 * whose hourly lines say otherwise moves every later month. Not every hourly
 * line is part of that figure, though: the deferred work force-billed on
 * termination is charged at rate without ever having drawn on the pool, and
 * `addDeferredTerminationLine()` deliberately leaves the figure alone. Such a
 * line is recognised by what it links - deferred work - and left out.
 */
final class RecordedOverage
{
    /**
     * Hours of the invoice's additional-hours lines the figure represents.
     *
     * @param  iterable<array{hours: float, links_deferred: bool}>  $lines
     */
    public static function chargedHours(iterable $lines): float
    {
        $hours = 0.0;
        foreach ($lines as $line) {
            if (! $line['links_deferred']) {
                $hours += $line['hours'];
            }
        }

        return round($hours, 4);
    }

    /**
     * A figure that is missing, or differs from the lines by more than the
     * four places a `decimal:4` column can hold, disagrees.
     */
    public static function disagrees(?float $recorded, float $chargedHours): bool
    {
        return $recorded === null || round(abs($recorded - $chargedHours), 4) > 0.0;
    }
}
