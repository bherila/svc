<?php

namespace App\Support\Billing;

/**
 * How the first (and last) partial billing cycle is handled when an agreement
 * starts (or ends) mid-cycle.
 */
enum FirstCycleProration: string
{
    /** Retainer hours and fee are prorated to the fraction of the cycle covered. */
    case ProrateHours = 'prorate_hours';

    /**
     * The first cycle is treated as a full cycle even though only part of the
     * period is covered (client agreed to pay the full fee).
     */
    case FullPeriod = 'full_period';

    /**
     * The days from the agreement start to the next cycle boundary are billed
     * as a short "alignment" stub using monthly-cadence semantics, then full
     * cadence cycles begin at the next calendar boundary.
     */
    case AlignNextCycle = 'align_next_cycle';
}
