<?php

namespace App\Support\Billing;

use Carbon\CarbonInterface;

/**
 * How much of the retainer an invoice sells may absorb the overflow of the
 * work it reconciles.
 *
 * A monthly invoice offers its work two pools in turn: the month the work was
 * done in, then the retainer it sells for the month after. That second offer
 * is only a second pool when it *is* another month. A work period that ends
 * before its month does - a correction range, a final period cut short by
 * termination - derives "the month after" from the day after it ends, which is
 * the same month again. Lending that month's retainer a second time let one
 * pool absorb the same overflow twice: work beyond it went unbilled, and the
 * minimum availability was measured against hours that did not exist.
 *
 * Database-free, so the rule is unit-tested on its own.
 */
final class NextRetainerLending
{
    /**
     * @param  float  $retainerHours  the retainer hours of the month being sold
     * @param  float  $unabsorbedDebt  debt the work month could not absorb, which that retainer repays first
     */
    public static function capacity(
        CarbonInterface $workEnd,
        CarbonInterface $retainerMonthStart,
        bool $retainerMonthIsPostTermination,
        float $retainerHours,
        float $unabsorbedDebt,
    ): float {
        if ($retainerMonthIsPostTermination || $retainerMonthStart->isSameMonth($workEnd)) {
            return 0.0;
        }

        return max(0.0, $retainerHours - $unabsorbedDebt);
    }
}
