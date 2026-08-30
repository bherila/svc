<?php

namespace App\Support\Billing;

use Carbon\CarbonImmutable;

/**
 * A replay-only ledger opening proved from a complete imported invoice chain.
 *
 * Keeping the proof in this DTO makes it independent of Eloquent and lets the
 * command load every candidate chain in one bounded query.
 */
final readonly class ReplayHistorySeed
{
    private function __construct(
        public int $workspaceId,
        public int $companyId,
        public int $agreementId,
        public string $currency,
        public int $retainerMinutes,
        public int $retainerAmount,
        public CarbonImmutable $agreementStart,
        public CarbonImmutable $seedStart,
    ) {}

    /**
     * @param  list<ReplayHistoricalCycle>  $cycles
     */
    public static function fromHistory(
        int $workspaceId,
        int $companyId,
        int $agreementId,
        string $currency,
        int $retainerMinutes,
        int $retainerAmount,
        BillingCadence $cadence,
        CarbonImmutable $agreementStart,
        array $cycles,
    ): ?self {
        if ($cadence !== BillingCadence::Monthly || $cycles === []) {
            return null;
        }

        $expectedServiceStart = null;
        $sawLegacy = false;
        $sawCurrent = false;

        foreach ($cycles as $cycle) {
            if ($cycle->invoiceKind !== InvoiceKind::CadencePeriod->value
                || $cycle->cycleStart === null
                || $cycle->cycleEnd === null
                || $cycle->servicePeriodStart === null
                || $cycle->servicePeriodEnd === null) {
                return null;
            }

            $serviceStart = $cycle->servicePeriodStart;
            $serviceEnd = $cycle->servicePeriodEnd;
            if (! $serviceStart->isSameDay($serviceStart->startOfMonth())
                || ! $serviceEnd->isSameDay($serviceStart->endOfMonth()->startOfDay())
                || ($expectedServiceStart instanceof CarbonImmutable
                    && ! $serviceStart->isSameDay($expectedServiceStart))) {
                return null;
            }

            $legacy = $cycle->cycleStart->isSameDay($serviceStart)
                && $cycle->cycleEnd->isSameDay($serviceEnd);
            $currentCycleStart = $serviceEnd->addDay()->startOfDay();
            $current = $cycle->cycleStart->isSameDay($currentCycleStart)
                && $cycle->cycleEnd->isSameDay($currentCycleStart->endOfMonth()->startOfDay());

            if (! $legacy && ! $current) {
                return null;
            }
            if ($legacy) {
                if ($sawCurrent) {
                    return null;
                }
                $sawLegacy = true;
            } else {
                if (! $sawLegacy) {
                    return null;
                }
                $sawCurrent = true;
            }

            $expectedServiceStart = $serviceEnd->addDay()->startOfDay();
        }

        $first = $cycles[0];
        if ($first->servicePeriodStart === null
            || ! $first->servicePeriodStart->lt($agreementStart)) {
            return null;
        }

        return new self(
            workspaceId: $workspaceId,
            companyId: $companyId,
            agreementId: $agreementId,
            currency: $currency,
            retainerMinutes: $retainerMinutes,
            retainerAmount: $retainerAmount,
            agreementStart: $agreementStart,
            seedStart: $first->servicePeriodStart,
        );
    }
}
