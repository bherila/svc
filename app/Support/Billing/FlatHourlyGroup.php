<?php

namespace App\Support\Billing;

use App\Models\ClientTimeEntry;

/**
 * Flat-hourly subcontractor work that bills as one invoice line.
 *
 * One person, one project and one snapshotted rate in one currency: the key
 * {@see FlatHourlyPricing::groups()} groups by, so a mid-period rate change
 * prices each rate's minutes at that rate rather than blending them.
 */
final readonly class FlatHourlyGroup
{
    /**
     * @param  non-empty-list<ClientTimeEntry>  $entries
     */
    public function __construct(
        public array $entries,
        public int $minutes,
        public int $unitAmount,
        public string $currency,
        public int $totalAmount,
    ) {}

    public function sample(): ClientTimeEntry
    {
        return $this->entries[0];
    }

    public function hours(): float
    {
        return round($this->minutes / 60, 4);
    }
}
