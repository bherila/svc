<?php

namespace App\Support\Engagement;

use App\Contracts\RetainerAgreementTerms;
use App\Services\Billing\BillingCycleResolver;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\FirstCycleProration;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * The two facts that fix an agreement's billing periods - its cadence and its
 * start date - in the shape {@see BillingCycleResolver} reads.
 *
 * {@see CancellationDates} asks the resolver for period closes through this,
 * so a cancellation can only ever end where the billing engine itself closes
 * a period. The resolver reads nothing else today. Every other term refuses
 * rather than answering zero: if the resolver ever started to read proration
 * or retainer figures when placing a boundary, a zero would quietly move the
 * dates offered to a client, and a refusal makes that change fail here first.
 */
final readonly class CancellationCycleAnchor implements RetainerAgreementTerms
{
    public function __construct(
        public BillingCadence $cadence,
        public CarbonImmutable $startsOn,
    ) {}

    public function effectiveBillingCadence(): BillingCadence
    {
        return $this->cadence;
    }

    public function retainerStartsOn(): CarbonImmutable
    {
        return $this->startsOn;
    }

    /**
     * Never set. A scheduled or fixed end clips the engine's final cycle, but
     * the closes a cancellation may choose are the unclipped ones; the caller
     * filters them against the agreement's current end instead.
     */
    public function retainerEndsOn(): ?CarbonImmutable
    {
        return null;
    }

    public function effectiveFirstCycleProration(): FirstCycleProration
    {
        throw self::notABoundary(__FUNCTION__);
    }

    public function retainerMonthlyHours(): float
    {
        throw self::notABoundary(__FUNCTION__);
    }

    public function retainerMonthlyFee(): float
    {
        throw self::notABoundary(__FUNCTION__);
    }

    public function periodRetainerHoursOverride(): ?float
    {
        throw self::notABoundary(__FUNCTION__);
    }

    public function periodRetainerFeeOverride(): ?float
    {
        throw self::notABoundary(__FUNCTION__);
    }

    public function periodRetainerHours(): float
    {
        throw self::notABoundary(__FUNCTION__);
    }

    public function retainerHoursPerMonth(): float
    {
        throw self::notABoundary(__FUNCTION__);
    }

    public function periodRetainerFee(): float
    {
        throw self::notABoundary(__FUNCTION__);
    }

    private static function notABoundary(string $term): LogicException
    {
        return new LogicException("A cancellation cycle anchor carries only the cadence and start date; {$term}() is not one of them.");
    }
}
