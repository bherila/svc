<?php

namespace App\Support\Billing;

/** One independently proved retainer sale, deduplicated across replay snapshots. */
final readonly class ReplayRetainerCapacityLot
{
    public function __construct(
        public string $identity,
        public int $minutes,
    ) {}
}
