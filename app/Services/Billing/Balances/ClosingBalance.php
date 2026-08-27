<?php

namespace App\Services\Billing\Balances;

readonly class ClosingBalance
{
    public function __construct(
        public float $hoursUsedFromRetainer,
        public float $hoursUsedFromRollover,
        public float $unusedHours,
        public float $excessHours,
        public float $negativeBalance,
        public float $remainingRollover,
    ) {}
}
