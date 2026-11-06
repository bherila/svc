<?php

namespace App\Support\Revenue;

use App\Services\Revenue\RecurringRevenueProjector;

/**
 * What {@see RecurringRevenueProjector} returns: one
 * {@see MonthlyProjection} per client per month of the window (the current
 * month and the twelve full months after it), and the per-agreement rows they
 * were summed from.
 */
final readonly class RevenueProjection
{
    /**
     * @param  list<string>  $months  `Y-m`, ascending, the current month first
     * @param  list<MonthlyProjection>  $rows
     * @param  list<AgreementProjection>  $agreements
     */
    public function __construct(
        public array $months,
        public array $rows,
        public array $agreements,
    ) {}

    /** @return list<MonthlyProjection> */
    public function rowsFor(string $month): array
    {
        return array_values(array_filter($this->rows, static fn (MonthlyProjection $row): bool => $row->month === $month));
    }

    public function contracted(string $month, ?int $clientId = null): int
    {
        return array_sum(array_map(
            static fn (MonthlyProjection $row): int => $row->contracted(),
            $this->matching($month, $clientId),
        ));
    }

    public function projectedOverage(string $month, ?int $clientId = null): int
    {
        return array_sum(array_map(
            static fn (MonthlyProjection $row): int => $row->projectedOverage,
            $this->matching($month, $clientId),
        ));
    }

    /** @return list<MonthlyProjection> */
    private function matching(string $month, ?int $clientId): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (MonthlyProjection $row): bool => $row->month === $month && ($clientId === null || $row->clientId === $clientId),
        ));
    }
}
