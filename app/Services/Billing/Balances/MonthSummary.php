<?php

namespace App\Services\Billing\Balances;

readonly class MonthSummary
{
    /**
     * @param  string|null  $cycleStart  ISO date of the owning billing cycle's start. Set only by the period-retainer ledger; null for the legacy monthly-rollover ledger.
     * @param  float  $billedOverageHours  Signed hours charged at the hourly rate against this month, from the invoices that settled it. Retainer hours are bought by the retainer fee; these are the ones bought on top of it, and without them the screen cannot say how many hours the client actually paid for.
     */
    public function __construct(
        public OpeningBalance $opening,
        public ClosingBalance $closing,
        public float $hoursWorked,
        public string $yearMonth,
        public float $retainerHours,
        public bool $billExcessImmediately = false,
        public ?string $cycleStart = null,
        public float $billedOverageHours = 0.0,
    ) {}
}
