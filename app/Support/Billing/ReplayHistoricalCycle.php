<?php

namespace App\Support\Billing;

use Carbon\CarbonImmutable;

/** One imported invoice's cycle labels, detached from persistence. */
final readonly class ReplayHistoricalCycle
{
    public function __construct(
        public string $invoiceKind,
        public ?CarbonImmutable $cycleStart,
        public ?CarbonImmutable $cycleEnd,
        public ?CarbonImmutable $servicePeriodStart,
        public ?CarbonImmutable $servicePeriodEnd,
    ) {}
}
