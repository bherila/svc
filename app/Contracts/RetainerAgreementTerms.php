<?php

namespace App\Contracts;

use App\Support\Billing\BillingCadence;
use App\Support\Billing\FirstCycleProration;
use Carbon\CarbonImmutable;

/** Persistence-free retainer terms consumed by billing arithmetic. */
interface RetainerAgreementTerms
{
    public function effectiveBillingCadence(): BillingCadence;

    public function effectiveFirstCycleProration(): FirstCycleProration;

    public function retainerStartsOn(): ?CarbonImmutable;

    public function retainerEndsOn(): ?CarbonImmutable;

    public function retainerMonthlyHours(): float;

    public function retainerMonthlyFee(): float;

    public function periodRetainerHoursOverride(): ?float;

    public function periodRetainerFeeOverride(): ?float;

    public function periodRetainerHours(): float;

    public function retainerHoursPerMonth(): float;

    public function periodRetainerFee(): float;
}
