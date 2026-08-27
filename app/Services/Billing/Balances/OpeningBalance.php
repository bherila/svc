<?php

namespace App\Services\Billing\Balances;

readonly class OpeningBalance
{
    public function __construct(
        public float $retainerHours,
        public float $rolloverHours,
        public float $expiredHours,
        public float $totalAvailable,
        public float $negativeOffset,
        public float $invoicedNegativeBalance,
        public float $effectiveRetainerHours,
        public float $remainingNegativeBalance,
    ) {}
}
