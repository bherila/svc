<?php

namespace App\Support\AgentApi\Presenters;

use App\Services\Billing\Balances\MonthSummary;

/** Allowlisted serialization of a computed invoice-capacity ledger row. */
final class CapacityLedgerPresenter
{
    /** @return array<string, int|float|string|bool|null> */
    public function present(MonthSummary $summary): array
    {
        return [
            'period' => $summary->yearMonth,
            'cycle_start' => $summary->cycleStart,
            'retainer_hours' => $summary->retainerHours,
            'hours_worked' => $summary->hoursWorked,
            'opening_retainer_hours' => $summary->opening->retainerHours,
            'opening_rollover_hours' => $summary->opening->rolloverHours,
            'opening_expired_hours' => $summary->opening->expiredHours,
            'opening_available_hours' => $summary->opening->totalAvailable,
            'hours_used_from_retainer' => $summary->closing->hoursUsedFromRetainer,
            'hours_used_from_rollover' => $summary->closing->hoursUsedFromRollover,
            'unused_hours' => $summary->closing->unusedHours,
            'excess_hours' => $summary->closing->excessHours,
            'negative_hours' => $summary->closing->negativeBalance,
            'signed_available_hours' => round(
                $summary->closing->unusedHours - $summary->closing->negativeBalance,
                4,
            ),
            'remaining_rollover_hours' => $summary->closing->remainingRollover,
            'bill_excess_immediately' => $summary->billExcessImmediately,
        ];
    }
}
