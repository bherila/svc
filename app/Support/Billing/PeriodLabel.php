<?php

namespace App\Support\Billing;

use Carbon\Carbon;

/**
 * How a billing period is named in generation results.
 *
 * The label is what an operator reads back after a generation run, so it is
 * written for recognition rather than precision: a period that happens to be a
 * calendar quarter says so instead of spelling out its two month keys.
 *
 * Shared rather than duplicated because the interim generator and the cadence
 * generator both report periods and their labels have to agree - the same cycle
 * reported two ways reads as two cycles.
 */
final class PeriodLabel
{
    public static function for(Carbon $start, Carbon $end): string
    {
        if ($start->isSameMonth($end)) {
            return $start->format('Y-m');
        }

        // Semantic Carbon boundaries, not string dates: a quarter is defined by
        // where it starts and ends, not by how the dates happen to render.
        if ($start->isSameDay($start->copy()->startOfQuarter())
            && $end->isSameDay($start->copy()->endOfQuarter()->startOfDay())) {
            return $start->format('Y').'-Q'.$start->quarter;
        }

        if ($start->isSameDay($start->copy()->startOfYear())
            && $end->isSameDay($start->copy()->endOfYear()->startOfDay())) {
            return $start->format('Y');
        }

        return $start->format('Y-m').'..'.$end->format('Y-m');
    }
}
