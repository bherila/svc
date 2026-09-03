<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\Workspace;
use App\Support\Billing\OpeningRolloverCounts;
use Illuminate\Database\Eloquent\Builder;

/**
 * Count agreements whose ledger changes when opening rollover is restored.
 *
 * The optional workspace is the tenant boundary for application and MCP
 * callers. A null scope is reserved for the operator CLI, which deliberately
 * reports the aggregate across the installation.
 */
final class OpeningRolloverAuditor
{
    public function count(?Workspace $workspace = null): OpeningRolloverCounts
    {
        $agreements = $this->agreements($workspace);
        $withRollover = (clone $agreements)->where('initial_rollover_minutes', '>', 0);

        // Read the stored period override directly so this remains one bounded
        // aggregate query family rather than loading agreements to call model
        // accessors. This is the same branch InvoiceLedgerBuilder evaluates.
        $legacyMonthly = (clone $withRollover)->whereNull('period_retainer_minutes');
        $affected = (clone $legacyMonthly)->where('rollover_months', '>', 0);
        $agreementCount = $agreements->count();
        $withRolloverCount = $withRollover->count();
        $legacyMonthlyCount = $legacyMonthly->count();
        $affectedCount = $affected->count();
        // @infection-ignore-all PDO may return an integer-column SUM as a numeric string; the DTO requires one stable integer type.
        $capacityAtStakeMinutes = (int) $affected->sum('initial_rollover_minutes');
        $longest = $affected
            ->orderByDesc('rollover_months')
            ->first(['rollover_months']);

        return new OpeningRolloverCounts(
            agreements: $agreementCount,
            withInitialRollover: $withRolloverCount,
            legacyMonthlyOfThose: $legacyMonthlyCount,
            affected: $affectedCount,
            capacityAtStakeMinutes: $capacityAtStakeMinutes,
            longestRolloverMonths: $longest->rollover_months ?? 0,
        );
    }

    /** @return Builder<ClientAgreement> */
    private function agreements(?Workspace $workspace): Builder
    {
        $agreements = ClientAgreement::query();

        return $workspace === null
            ? $agreements
            : $agreements->where('workspace_id', $workspace->id);
    }
}
