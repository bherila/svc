<?php

namespace App\Support\Billing;

/**
 * How a partial opening calendar month is handled.
 *
 * Longer cadence dates stay anchored on the agreement's start under every
 * setting. Legacy monthly accrual still reads this setting for the opening
 * calendar-month grant; native period overrides describe one whole cycle pool.
 * Choosing a setting does not convert an agreement between those models.
 */
enum FirstCycleProration: string
{
    /** Opening calendar-month hours and fee follow the covered part of the month. */
    case ProrateHours = 'prorate_hours';

    /**
     * A partial opening calendar-month grant is treated as a whole month's
     * hours and fee. This still affects monthly-accrual terms billed quarterly;
     * it does not change the longer cadence's start-date grid.
     */
    case FullPeriod = 'full_period';

    /**
     * Retained for stored agreements. The opening calendar-month grant follows
     * ordinary monthly proration; this value inserts no extra alignment period
     * and does not realign a longer cadence to a calendar-quarter boundary.
     */
    case AlignNextCycle = 'align_next_cycle';
}
