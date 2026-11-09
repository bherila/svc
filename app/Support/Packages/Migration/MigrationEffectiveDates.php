<?php

namespace App\Support\Packages\Migration;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The dates a move may take effect for one agreement, as
 * {@see MigrationDates} works them out on one day. Every date is a calendar
 * day at midnight UTC: read it with `toDateString()`.
 */
final readonly class MigrationEffectiveDates
{
    /**
     * @param  CarbonImmutable  $earliest  the first period start after the notice and anything already invoiced
     * @param  list<CarbonImmutable>  $offered  the earliest and the period starts after it, ascending, each on or before the agreement's end; empty when it ends first
     */
    public function __construct(
        public CarbonImmutable $earliest,
        public array $offered,
    ) {}

    public function offers(CarbonInterface $date): bool
    {
        return in_array($date->toDateString(), $this->offeredDateStrings(), true);
    }

    /** @return list<string> */
    public function offeredDateStrings(): array
    {
        return array_map(static fn (CarbonImmutable $date): string => $date->toDateString(), $this->offered);
    }
}
