<?php

namespace App\Support\Billing;

use App\Services\Billing\OpeningRolloverAuditor;

/**
 * Aggregate-only size of the opening-rollover population.
 *
 * No identifier, name, company, or workspace can enter this DTO, so the CLI
 * and tenant-facing read surface share one privacy-safe result contract.
 *
 * @see OpeningRolloverAuditor
 */
final readonly class OpeningRolloverCounts
{
    public function __construct(
        public int $agreements,
        public int $withInitialRollover,
        public int $legacyMonthlyOfThose,
        public int $affected,
        public int $capacityAtStakeMinutes,
        public int $longestRolloverMonths,
    ) {}

    /**
     * @return array{
     *     agreements: int,
     *     with_initial_rollover: int,
     *     legacy_monthly_of_those: int,
     *     affected: int,
     *     capacity_at_stake_minutes: int,
     *     longest_rollover_months: int,
     * }
     */
    public function toArray(): array
    {
        return [
            'agreements' => $this->agreements,
            'with_initial_rollover' => $this->withInitialRollover,
            'legacy_monthly_of_those' => $this->legacyMonthlyOfThose,
            'affected' => $this->affected,
            'capacity_at_stake_minutes' => $this->capacityAtStakeMinutes,
            'longest_rollover_months' => $this->longestRolloverMonths,
        ];
    }
}
