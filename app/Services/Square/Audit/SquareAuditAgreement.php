<?php

namespace App\Services\Square\Audit;

use App\Support\Billing\BillingCadence;

/**
 * An agreement billed in a Square series, as the billing audit compares it
 * (#438). `cadence` is the cadence svc bills on, or null when the agreement
 * does not bill on a repeating cycle; `periodAmount` is the retainer fee for
 * one whole cycle in minor units, or null when the agreement sells none.
 */
final readonly class SquareAuditAgreement
{
    public function __construct(
        public string $agreementId,
        public string $companyId,
        public string $seriesKey,
        public string $startsOn,
        public ?BillingCadence $cadence,
        public ?int $periodAmount,
        public string $currency,
    ) {}
}
