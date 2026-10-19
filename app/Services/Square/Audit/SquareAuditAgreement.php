<?php

namespace App\Services\Square\Audit;

use App\Support\Billing\BillingCadence;

/**
 * An agreement billed in a Square series, as the billing audit compares it
 * (#438). `cadence` is the cadence svc bills on, or null when the agreement
 * does not bill on a repeating cycle; `periodAmount` is the retainer fee for
 * one whole cycle in minor units - positive, or null when the agreement
 * sells none. Dates are calendar days; `endsOn` is null while it runs on.
 */
final readonly class SquareAuditAgreement
{
    public function __construct(
        public string $agreementId,
        public string $companyId,
        public string $seriesKey,
        public string $startsOn,
        public ?BillingCadence $cadence,
        /** @var positive-int|null */
        public ?int $periodAmount,
        public string $currency,
        public ?string $endsOn = null,
    ) {}

    /**
     * Whether a Square invoice dated `$date` falls within this agreement:
     * on or after its start and, once it has ended, on or before its end.
     * An undated invoice cannot be placed outside it.
     */
    public function governs(?string $date): bool
    {
        return $date === null || ($date >= $this->startsOn && ($this->endsOn === null || $date <= $this->endsOn));
    }

    /** Whether `$date` is after the agreement ended. */
    public function endedBefore(?string $date): bool
    {
        return $date !== null && $this->endsOn !== null && $date > $this->endsOn;
    }
}
