<?php

namespace App\Support\Billing;

/** One month's movement of the deferred shelf. */
final readonly class DeferredBacklogMonth
{
    public function __construct(
        /** `Y-m`, as the capacity ledger names its months. */
        public string $period,
        /** Deferred work done this month, whatever has happened to it since. */
        public int $minutesAdded,
        /** Deferred work a retainer pool of this month absorbed. */
        public int $minutesApplied,
        /** Deferred work billed at an hourly price this month instead. */
        public int $minutesBilled,
        /** Buy-down subset of minutesBilled, never subtracted from net twice. */
        public int $minutesBoughtDown = 0,
    ) {}

    public function netMinutes(): int
    {
        return $this->minutesAdded - $this->minutesApplied - $this->minutesBilled;
    }
}
