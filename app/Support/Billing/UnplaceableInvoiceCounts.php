<?php

namespace App\Support\Billing;

use App\Services\Billing\UnplaceableInvoiceAuditor;

/**
 * How many invoices cannot be placed on a calendar, and how much they carry.
 *
 * Counts and aggregate hours only - never a row, an id, an invoice number, a
 * company or a workspace. That is a property of this type rather than of the
 * code that renders it: a caller physically cannot leak an identifier through
 * it, so the console command, a future operator screen, and anything else that
 * consumes this are all safe against a database of real client billing records
 * without each having to be careful in its own way.
 *
 * The funnels are cumulative and each stage alone overstates, which is why they
 * are reported separately rather than as one number. See
 * {@see UnplaceableInvoiceAuditor} for what each stage
 * removes and why.
 */
final readonly class UnplaceableInvoiceCounts
{
    public function __construct(
        public int $invoices,
        public int $withoutAServicePeriod,
        public int $withoutAServicePeriodStart,
        public int $ofAKindReadByAPeriodGuard,
        public int $chargedOfThose,
        public int $onAnAgreementOfThose,
        public int $affected,
        public float $overageHoursAtStake,
        public int $withoutACycle,
        public int $ofAKindReadByCycle,
        public int $liveWithoutACycle,
        public int $cycleAffected,
        public float $cycleOverageHoursAtStake,
    ) {}

    /**
     * The machine-readable shape, stable for the `--format=json` contract.
     *
     * @return array{
     *     invoices: int,
     *     without_a_service_period: int,
     *     without_a_service_period_start: int,
     *     of_a_kind_read_by_a_period_guard: int,
     *     charged_of_those: int,
     *     on_an_agreement_of_those: int,
     *     affected: int,
     *     overage_hours_at_stake: float,
     *     without_a_cycle: int,
     *     of_a_kind_read_by_cycle: int,
     *     live_without_a_cycle: int,
     *     cycle_affected: int,
     *     cycle_overage_hours_at_stake: float,
     * }
     */
    public function toArray(): array
    {
        return [
            'invoices' => $this->invoices,
            'without_a_service_period' => $this->withoutAServicePeriod,
            'without_a_service_period_start' => $this->withoutAServicePeriodStart,
            'of_a_kind_read_by_a_period_guard' => $this->ofAKindReadByAPeriodGuard,
            'charged_of_those' => $this->chargedOfThose,
            'on_an_agreement_of_those' => $this->onAnAgreementOfThose,
            'affected' => $this->affected,
            'overage_hours_at_stake' => $this->overageHoursAtStake,
            'without_a_cycle' => $this->withoutACycle,
            'of_a_kind_read_by_cycle' => $this->ofAKindReadByCycle,
            'live_without_a_cycle' => $this->liveWithoutACycle,
            'cycle_affected' => $this->cycleAffected,
            'cycle_overage_hours_at_stake' => $this->cycleOverageHoursAtStake,
        ];
    }
}
