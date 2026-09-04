<?php

namespace App\Support\Billing;

use Carbon\CarbonImmutable;

/** Contract evidence for capacity granted by a replay-only opening month. */
final readonly class ReplayOpeningCapacityContext
{
    private function __construct(
        public int $agreementId,
        public string $currency,
        public int $capacityMinutes,
        public int $retainerAmount,
        public int $hourlyRateAmount,
        public int $catchUpThresholdMinutes,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $expiresAt,
    ) {}

    public static function fromOpeningInvoice(
        ReplayHistorySeed $seed,
        ReplayInvoiceSnapshot $openingInvoice,
    ): ?self {
        if ($seed->retainerMinutes <= 0
            || $seed->retainerAmount <= 0
            || $openingInvoice->currency !== $seed->currency) {
            return null;
        }

        $matchingFees = array_filter(
            $openingInvoice->linesOfType('retainer'),
            static fn (ReplayInvoiceLineSnapshot $line): bool => $line->agreementId === (string) $seed->agreementId
                && $line->unitAmount === $seed->retainerAmount
                && $line->taxAmount === 0
                && $line->totalAmount === $seed->retainerAmount
                && is_numeric($line->quantity)
                && (float) $line->quantity === 1.0
                && $line->hoursMinutes() === $seed->retainerMinutes
                && $line->lineDate === $seed->agreementStart->toDateString()
                && $line->recurringItemId === ''
                && $line->projectId === ''
                && $line->claimedBy === ''
                && $line->sourceMinutes === 0
                && $line->agreementRateSourceMinutes === 0,
        );
        if (count($matchingFees) !== 1) {
            return null;
        }

        return new self(
            agreementId: $seed->agreementId,
            currency: $seed->currency,
            capacityMinutes: $seed->retainerMinutes,
            retainerAmount: $seed->retainerAmount,
            hourlyRateAmount: $seed->hourlyRateAmount,
            catchUpThresholdMinutes: $seed->catchUpThresholdMinutes,
            startsAt: $seed->seedStart,
            expiresAt: $seed->capacityExpiresAt,
        );
    }

    public function forRemainingMinutes(int $remainingMinutes): self
    {
        return new self(
            agreementId: $this->agreementId,
            currency: $this->currency,
            capacityMinutes: max(0, $remainingMinutes),
            retainerAmount: $this->retainerAmount,
            hourlyRateAmount: $this->hourlyRateAmount,
            catchUpThresholdMinutes: $this->catchUpThresholdMinutes,
            startsAt: $this->startsAt,
            expiresAt: $this->expiresAt,
        );
    }

    public function covers(?CarbonImmutable $servicePeriodStart): bool
    {
        return $servicePeriodStart !== null
            && $servicePeriodStart->betweenIncluded($this->startsAt, $this->expiresAt);
    }
}
