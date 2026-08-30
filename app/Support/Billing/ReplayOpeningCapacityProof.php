<?php

namespace App\Support\Billing;

/** Complete, database-free proof of an opening-capacity allocation correction. */
final readonly class ReplayOpeningCapacityProof
{
    public function __construct(
        public int $movedMinutes,
        public int $moneyDelta,
        public bool $alsoCorrectsHistoricalMinuteRounding,
    ) {}
}
