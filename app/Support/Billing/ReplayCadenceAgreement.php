<?php

namespace App\Support\Billing;

use App\Contracts\RetainerAgreementTerms;
use Carbon\CarbonImmutable;

/** Immutable agreement facts used to prove a replayed cadence chain. */
final readonly class ReplayCadenceAgreement implements RetainerAgreementTerms
{
    public function __construct(
        public int $companyId,
        public int $agreementId,
        public string $currency,
        public CarbonImmutable $startsOn,
        public ?CarbonImmutable $endsOn,
        public BillingCadence $cadence,
        public FirstCycleProration $firstCycleProration,
        public float $monthlyHours,
        public float $monthlyFee,
        public ?float $periodHoursOverride,
        public ?float $periodFeeOverride,
        public int $hourlyRateAmount,
    ) {}

    public function effectiveBillingCadence(): BillingCadence
    {
        return $this->cadence;
    }

    public function effectiveFirstCycleProration(): FirstCycleProration
    {
        return $this->firstCycleProration;
    }

    public function retainerStartsOn(): CarbonImmutable
    {
        return $this->startsOn;
    }

    public function retainerEndsOn(): ?CarbonImmutable
    {
        return $this->endsOn;
    }

    public function retainerMonthlyHours(): float
    {
        return $this->monthlyHours;
    }

    public function retainerMonthlyFee(): float
    {
        return $this->monthlyFee;
    }

    public function periodRetainerHoursOverride(): ?float
    {
        return $this->periodHoursOverride;
    }

    public function periodRetainerFeeOverride(): ?float
    {
        return $this->periodFeeOverride;
    }

    public function periodRetainerHours(): float
    {
        return $this->periodHoursOverride
            ?? $this->monthlyHours * $this->cadence->monthsInCycle();
    }

    public function retainerHoursPerMonth(): float
    {
        return $this->periodHoursOverride === null
            ? $this->monthlyHours
            : round($this->periodHoursOverride / max(1, $this->cadence->monthsInCycle()), 4);
    }

    public function periodRetainerFee(): float
    {
        return $this->periodFeeOverride
            ?? $this->monthlyFee * $this->cadence->monthsInCycle();
    }
}
