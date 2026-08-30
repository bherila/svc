<?php

namespace App\Support\Billing;

/** Immutable contract for one recurring-item incidence the biller expects. */
final readonly class ReplayRecurringItemIncidence
{
    public function __construct(
        public int $companyId,
        public int $agreementId,
        public int $itemId,
        public string $currency,
        public bool $taxable,
        public bool $opensItem,
        public string $lineDate,
        public int $unitAmount,
        public string $quantity,
        public int $taxAmount,
        public int $totalAmount,
    ) {}
}
