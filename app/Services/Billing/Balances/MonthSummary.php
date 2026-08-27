<?php

namespace App\Services\Billing\Balances;

readonly class MonthSummary
{
    /**
     * @param  string|null  $cycleStart  ISO date of the owning billing cycle's start. Set only by the period-retainer ledger; null for the legacy monthly-rollover ledger.
     */
    public function __construct(
        public OpeningBalance $opening,
        public ClosingBalance $closing,
        public float $hoursWorked,
        public string $yearMonth,
        public float $retainerHours,
        public bool $billExcessImmediately = false,
        public ?string $cycleStart = null,
    ) {}
}
