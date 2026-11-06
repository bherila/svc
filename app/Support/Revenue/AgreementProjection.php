<?php

namespace App\Support\Revenue;

/**
 * One agreement's projection: its terms, the overage method applied, and
 * what it contributes to each month of the window.
 */
final readonly class AgreementProjection
{
    /**
     * @param  array<string, array{retainer: int, recurring_items: int, projected_overage: int}>  $months  by `Y-m`, every month of the window
     * @param  array<int, string|null>  $nextChargeOn  first unbilled charge date in the window, by recurring item id
     */
    public function __construct(
        public ProjectedAgreement $agreement,
        public ProjectedOverage $overage,
        public array $months,
        public array $nextChargeOn,
        public int $monthlyRecurring,
    ) {}

    public function contractedTotal(): int
    {
        return array_sum(array_map(static fn (array $month): int => $month['retainer'] + $month['recurring_items'], $this->months));
    }

    public function projectedOverageTotal(): int
    {
        return array_sum(array_column($this->months, 'projected_overage'));
    }

    /** @return array<string, mixed> */
    public function toArray(bool $includeProjectedOverage): array
    {
        $agreement = $this->agreement;
        $items = [];
        foreach ($agreement->recurringItems as $item) {
            $items[] = [
                'item_id' => $item->publicId,
                'description' => $item->description,
                'cadence' => $item->cadence,
                'amount' => $item->incidenceAmount(),
                'monthly_equivalent' => $item->monthlyEquivalent(),
                'is_taxable' => $item->isTaxable,
                'is_active' => $item->isActive,
                'effective_on' => $item->effectiveOn?->toDateString(),
                'expires_on' => $item->expiresOn?->toDateString(),
                'next_charge_on' => $this->nextChargeOn[$item->id] ?? null,
            ];
        }

        $months = [];
        foreach ($this->months as $month => $amounts) {
            $months[] = [
                'month' => $month,
                'contracted' => $amounts['retainer'] + $amounts['recurring_items'],
                'contracted_retainer' => $amounts['retainer'],
                'contracted_recurring_items' => $amounts['recurring_items'],
                'projected_overage' => $includeProjectedOverage ? $amounts['projected_overage'] : null,
            ];
        }

        return [
            'agreement_id' => $agreement->publicId,
            'client_id' => $agreement->clientPublicId,
            'client_name' => $agreement->clientName,
            'title' => $agreement->title,
            'status' => $agreement->status,
            'billing_cadence' => $agreement->effectiveBillingCadence()->value,
            'starts_on' => $agreement->startsOn->toDateString(),
            'ends_on' => $agreement->effectiveEnd()?->toDateString(),
            'signed' => $agreement->signedAt !== null,
            'retainer' => [
                'period_fee' => (int) round($agreement->periodRetainerFee() * 100),
                'monthly_equivalent' => $agreement->monthlyRetainerEquivalent(),
                'first_cycle_proration' => $agreement->effectiveFirstCycleProration()->value,
            ],
            'monthly_recurring' => $this->monthlyRecurring,
            'recurring_items' => $items,
            'overage' => $this->overage->toArray(),
            'months' => $months,
            'totals' => [
                'contracted' => $this->contractedTotal(),
                'projected_overage' => $includeProjectedOverage ? $this->projectedOverageTotal() : null,
            ],
        ];
    }
}
