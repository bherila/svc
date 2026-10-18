<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Support\Billing\DeferredBacklog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Loads what {@see DeferredBacklog} computes, inside one workspace.
 *
 * The persistence half of the read model: a fixed number of queries however
 * many agreements are read, then the calculation runs on what came back. The
 * outstanding set uses the termination collection's own predicates
 * ({@see DeferredBillingAllocator::collectForTermination()}) - the shared
 * `awaitingDeferredBilling` and `billableForInvoicing` scopes, the same
 * project-chain check over the whole client before any agreement narrows it,
 * and the agreement's project scope - so the backlog is the set termination
 * would bill today and nothing else (`DeferredBacklogParityTest`).
 */
final class DeferredBacklogReader
{
    public function __construct(
        private readonly TimeEntryProjectChainGuard $projectChainGuard = new TimeEntryProjectChainGuard,
    ) {}

    /**
     * @param  list<string>  $periods  `Y-m` months for the trend, oldest first
     */
    public function forAgreement(Workspace $workspace, ClientAgreement $agreement, CarbonImmutable $through, array $periods = []): DeferredBacklog
    {
        return $this->forAgreements($workspace, collect([$agreement]), $through, $periods)[(int) $agreement->id];
    }

    /**
     * @param  Collection<int, ClientAgreement>  $agreements  every one of them in $workspace
     * @param  list<string>  $periods  `Y-m` months for the trend, oldest first
     * @return array<int, DeferredBacklog> keyed by agreement id
     */
    public function forAgreements(Workspace $workspace, Collection $agreements, CarbonImmutable $through, array $periods = []): array
    {
        if ($agreements->isEmpty()) {
            return [];
        }
        $workspaceId = (int) $workspace->id;
        foreach ($agreements as $agreement) {
            if ((int) $agreement->workspace_id !== $workspaceId) {
                throw new LogicException('A deferred backlog is read inside the agreement\'s own workspace.');
            }
        }
        $companyIds = array_values(array_unique($agreements->map(static fn (ClientAgreement $agreement): int => (int) $agreement->client_company_id)->all()));
        $upTo = $through->toDateString();

        $companyEntries = ClientTimeEntry::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('client_company_id', $companyIds)
            ->awaitingDeferredBilling()
            ->where('worked_on', '<=', $upTo);

        // Checked over each client's whole deferred chain before any agreement
        // narrows it, as termination checks it, so a malformed row cannot
        // disappear behind a project scope. A refusal there is a withheld
        // figure here.
        $disagreeing = array_flip($this->projectChainGuard->companiesWithDisagreeingChains($workspaceId, $companyEntries));

        $outstanding = (clone $companyEntries)
            ->billableForInvoicing()
            ->orderBy('worked_on')
            ->orderBy('id')
            ->get();

        $history = $periods === []
            ? new Collection
            : $this->history($workspaceId, $companyIds, $upTo, $periods[0].'-01');

        $backlogs = [];
        foreach ($agreements as $agreement) {
            $backlogs[(int) $agreement->id] = DeferredBacklog::calculate(
                $agreement,
                $through,
                ! isset($disagreeing[(int) $agreement->client_company_id]),
                $outstanding->filter(static fn (ClientTimeEntry $entry): bool => $entry->isWithinAgreementScope($agreement))->values(),
                $history->filter(static fn (ClientTimeEntry $entry): bool => $entry->isWithinAgreementScope($agreement))->values(),
                $periods,
            );
        }

        return $backlogs;
    }

    /**
     * Deferred work that moved inside the trend window: done there, or taken
     * off the shelf by a line of this workspace dated there. A superset is
     * harmless - the calculation places each entry by its own dates - but an
     * entry allocated inside the window must not be missed for having been
     * worked before it.
     *
     * @param  list<int>  $companyIds
     * @return Collection<int, ClientTimeEntry>
     */
    private function history(int $workspaceId, array $companyIds, string $upTo, string $from): Collection
    {
        return ClientTimeEntry::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('client_company_id', $companyIds)
            ->where('is_billable', true)
            ->where('is_deferred', true)
            ->billableForInvoicing()
            ->where('worked_on', '<=', $upTo)
            ->where(fn (Builder $moved): Builder => $moved
                ->where('worked_on', '>=', $from)
                ->orWhereHas('invoiceLines', fn (Builder $lines): Builder => $lines
                    ->where('client_invoice_lines.workspace_id', $workspaceId)
                    ->where('client_invoice_line_time_entries.workspace_id', $workspaceId)
                    ->where(fn (Builder $dated): Builder => $dated
                        ->where('client_invoice_lines.line_date', '>=', $from)
                        ->orWhereHas('invoice', fn (Builder $invoice): Builder => $invoice
                            ->where('client_invoices.workspace_id', $workspaceId)
                            ->where('client_invoices.service_period_end', '>=', $from)))))
            ->withCapacityPlacement($workspaceId)
            ->orderBy('worked_on')
            ->orderBy('id')
            ->get();
    }
}
