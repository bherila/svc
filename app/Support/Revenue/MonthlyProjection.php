<?php

declare(strict_types=1);

namespace App\Support\Revenue;

/**
 * One client's projected revenue for one month, in minor units and before
 * tax. Contracted money (retainer fees and recurring items not yet on a
 * charged invoice) and projected overage are kept apart: only the first is
 * a commitment.
 */
final readonly class MonthlyProjection
{
    public function __construct(
        public string $month,
        public int $clientId,
        public string $clientPublicId,
        public string $clientName,
        public int $contractedRetainer,
        public int $contractedRecurringItems,
        public int $projectedOverage,
    ) {}

    public function contracted(): int
    {
        return $this->contractedRetainer + $this->contractedRecurringItems;
    }

    /** @return array{month: string, client_id: string, client_name: string, contracted: int, contracted_retainer: int, contracted_recurring_items: int, projected_overage: int} */
    public function toArray(): array
    {
        return [
            'month' => $this->month,
            'client_id' => $this->clientPublicId,
            'client_name' => $this->clientName,
            'contracted' => $this->contracted(),
            'contracted_retainer' => $this->contractedRetainer,
            'contracted_recurring_items' => $this->contractedRecurringItems,
            'projected_overage' => $this->projectedOverage,
        ];
    }
}
