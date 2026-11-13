<?php

namespace App\Support\Billing;

use Carbon\CarbonImmutable;

/** Retainer capacity ends at termination; explicit settlement can happen later. */
final readonly class DeferredWorkDates
{
    private function __construct(
        public CarbonImmutable $capacityThrough,
        public CarbonImmutable $settlementThrough,
    ) {}

    public static function through(CarbonImmutable $through, ?CarbonImmutable $endsOn, ?CarbonImmutable $successorStartsOn): self
    {
        $settlement = $successorStartsOn === null ? $through : min($through, $successorStartsOn->startOfDay()->subDay());

        return new self(
            capacityThrough: $endsOn === null ? $settlement : min($settlement, $endsOn->startOfDay()),
            settlementThrough: $settlement,
        );
    }
}
