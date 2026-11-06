<?php

namespace App\Services\Revenue;

use App\Models\ClientAgreement;
use App\Models\Workspace;
use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\InvoiceLedgerBuilder;
use App\Support\Revenue\ProjectionInputs;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The capacity ledger of each agreement in force, for the roster's agreement
 * health: the ledger `InvoiceLedgerBuilder` builds for generation and
 * `capacity_ledger.get`, through the day read.
 *
 * The agreements are read in one query, scoped to the workspace with their
 * client. Each ledger then costs the builder's own reads, so this is bounded
 * by the number of agreements in force, never by invoice or time rows. A
 * ledger that refuses to build (a charged overage with no service period, for
 * one) is reported as unreadable rather than failing the roster.
 */
final class AgreementLedgerRepository
{
    public function __construct(private readonly InvoiceLedgerBuilder $ledgers) {}

    /**
     * @return array<int, list<MonthSummary>|null> by agreement id
     */
    public function inForce(Workspace $workspace, ProjectionInputs $inputs): array
    {
        $ids = [];
        foreach ($inputs->agreements as $agreement) {
            if ($agreement->billsOnARecurringCadence() && $agreement->isInForceOn($inputs->asOf)) {
                $ids[] = $agreement->id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $workspaceId = (int) $workspace->getKey();
        $models = ClientAgreement::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $ids)
            ->whereHas('clientCompany', fn (Builder $company): Builder => $company->where('workspace_id', $workspaceId))
            ->with(['clientCompany' => fn (BelongsTo $company) => $company->where('workspace_id', $workspaceId)])
            ->orderBy('id')
            ->get();

        $ledgers = [];
        foreach ($models as $agreement) {
            $company = $agreement->clientCompany;
            if ($company === null) {
                continue;
            }
            try {
                $ledgers[(int) $agreement->getKey()] = array_values($this->ledgers->buildAgreementLedgerThrough($company, $agreement, Carbon::instance($inputs->asOf)));
            } catch (RuntimeException|DomainException) {
                $ledgers[(int) $agreement->getKey()] = null;
            }
        }

        return $ledgers;
    }
}
