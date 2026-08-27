<?php

namespace App\Services\Billing\Balances;

use Carbon\Carbon;

/**
 * Represents a single billing cycle produced by BillingCycleResolver.
 *
 * A cycle spans [$start, $end] inclusive and covers $monthCount touched
 * calendar months. $isProrated marks cycles clipped by an agreement boundary
 * or the requested generation ceiling.
 */
class BillingCycle
{
    /**
     * @param  Carbon[]  $monthStarts  First day of each calendar month in this cycle, sorted ascending.
     */
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly bool $isProrated,
        public readonly int $monthCount,
        public readonly array $monthStarts,
    ) {}
}
