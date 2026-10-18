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
        private readonly AgreementSelector $agreements = new AgreementSelector,
    ) {}

    /**
     * @param  list<string>  $periods  `Y-m` months for the trend, oldest first
     * @param  int|null  $carriedMinutes  the ledger's re-carried deferred balance; null where it was not read
     */
    public function forAgreement(Workspace $workspace, ClientAgreement $agreement, CarbonImmutable $through, array $periods = [], ?int $carriedMinutes = null): DeferredBacklog
    {
        return $this->forAgreements($workspace, collect([$agreement]), $through, $periods, [(int) $agreement->id => $carriedMinutes])[(int) $agreement->id];
    }

    /**
     * @param  Collection<int, ClientAgreement>  $agreements  every one of them in $workspace
     * @param  list<string>  $periods  `Y-m` months for the trend, oldest first
     * @param  array<int, int|null>  $carriedMinutes  re-carried balances by agreement id; absent is not read
     * @return array<int, DeferredBacklog> keyed by agreement id
     */
    public function forAgreements(Workspace $workspace, Collection $agreements, CarbonImmutable $through, array $periods = [], array $carriedMinutes = []): array
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
        $companyEntries = ClientTimeEntry::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('client_company_id', $companyIds)
            ->awaitingDeferredBilling()
            ->where('worked_on', '<=', $through->toDateString());

        // Checked over each client's whole deferred chain before any agreement
        // narrows it, as termination checks it, so a malformed row cannot
        // disappear behind a project scope. A refusal there is a withheld
        // figure here. The trend's history is checked too: an entry already
        // allocated is no longer awaiting billing, but would still be read.
        $history = $periods === [] ? null : $this->history($workspaceId, $companyIds, $through, CarbonImmutable::parse($periods[0].'-01'));
        $disagreeing = array_flip([
            ...$this->projectChainGuard->companiesClaimedOutsideWorkspace($workspaceId, $companyIds),
            ...$this->projectChainGuard->companiesWithDisagreeingChains($workspaceId, $companyEntries),
            ...($history === null ? [] : $this->projectChainGuard->companiesWithDisagreeingChains($workspaceId, $history)),
        ]);

        $outstanding = (clone $companyEntries)
            ->billableForInvoicing()
            ->orderBy('worked_on')
            ->orderBy('id')
            ->get();
        $moved = $history === null ? new Collection : $history->withCapacityPlacement($workspaceId)->get();
        $segments = $this->segments($workspaceId, $companyIds);

        $backlogs = [];
        foreach ($agreements as $agreement) {
            $clientSegments = $segments[(int) $agreement->client_company_id] ?? new Collection;
            $backlogs[(int) $agreement->id] = DeferredBacklog::calculate(
                $agreement,
                $this->segmentThrough($agreement, $clientSegments, $through),
                ! isset($disagreeing[(int) $agreement->client_company_id]),
                $outstanding->filter(static fn (ClientTimeEntry $entry): bool => $entry->isWithinAgreementScope($agreement))->values(),
                $moved->filter(static fn (ClientTimeEntry $entry): bool => $entry->isWithinAgreementScope($agreement))->values(),
                $periods,
                $carriedMinutes[(int) $agreement->id] ?? null,
                $this->segmentFrom($agreement, $clientSegments),
            );
        }

        return $backlogs;
    }

    /**
     * The last day an agreement's backlog runs to.
     *
     * The workspace's today, the agreement's own end - where its capacity
     * ledger stops - and, where a successor for the same project scope takes
     * over, the day before it starts, exactly as invoice generation hands one segment to the next
     * ({@see AgreementSelector::successorAgreementForGeneration()}); without
     * the bound, both agreements would quote the same later hours, the
     * outgoing one at a rate nobody bills any more.
     *
     * @param  Collection<int, ClientAgreement>  $segments  the client's agreements, in generation order
     */
    private function segmentThrough(ClientAgreement $agreement, Collection $segments, CarbonImmutable $through): CarbonImmutable
    {
        $bounds = [$through];
        // The agreement's own end, where its ledger stops too.
        $end = $agreement->termination_date;
        if ($end instanceof CarbonImmutable) {
            $bounds[] = $end->startOfDay();
        }
        $successor = $this->agreements->successorAgreementForGeneration($segments, $agreement);
        if ($successor instanceof ClientAgreement) {
            $bounds[] = $successor->starts_on->startOfDay()->subDay();
        }

        return min($bounds);
    }

    /**
     * The first day an agreement's backlog runs from: its own start, where a
     * predecessor for the same scope owns the work before it; otherwise no
     * bound, as termination applies none.
     *
     * @param  Collection<int, ClientAgreement>  $segments  the client's agreements, in generation order
     */
    private function segmentFrom(ClientAgreement $agreement, Collection $segments): ?CarbonImmutable
    {
        $hasPredecessor = $segments->contains(fn (ClientAgreement $segment): bool => $segment->id !== $agreement->id
            && $this->agreements->successorAgreementForGeneration($segments, $segment)?->id === $agreement->id);

        return $hasPredecessor ? $agreement->starts_on->startOfDay() : null;
    }

    /**
     * Every agreement of the clients read that invoice generation walks, in
     * its order, by client.
     *
     * @param  list<int>  $companyIds
     * @return array<int, Collection<int, ClientAgreement>>
     */
    private function segments(int $workspaceId, array $companyIds): array
    {
        $segments = [];
        foreach (ClientAgreement::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('client_company_id', $companyIds)
            ->whereIn('status', ['active', 'paused', 'terminated', 'expired'])
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get() as $segment) {
            $segments[(int) $segment->client_company_id] ??= new Collection;
            $segments[(int) $segment->client_company_id]->push($segment);
        }

        return $segments;
    }

    /**
     * Deferred work that moved inside the trend window: done there, or taken
     * off the shelf by a line of this workspace dated there. A superset is
     * harmless - the calculation places each entry by its own dates - but an
     * entry allocated inside the window must not be missed for having been
     * worked before it.
     *
     * @param  list<int>  $companyIds
     * @return Builder<ClientTimeEntry>
     */
    private function history(int $workspaceId, array $companyIds, CarbonImmutable $through, CarbonImmutable $from): Builder
    {
        return ClientTimeEntry::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('client_company_id', $companyIds)
            ->where('is_billable', true)
            ->where('is_deferred', true)
            ->billableForInvoicing()
            ->where('worked_on', '<=', $through->toDateString())
            ->where(fn (Builder $moved): Builder => $moved
                ->where('worked_on', '>=', $from->toDateString())
                ->orWhereHas('invoiceLines', fn (Builder $lines): Builder => $lines
                    ->where('client_invoice_lines.workspace_id', $workspaceId)
                    ->where('client_invoice_line_time_entries.workspace_id', $workspaceId)
                    ->where(fn (Builder $dated): Builder => $dated
                        ->where('client_invoice_lines.line_date', '>=', $from->toDateString())
                        ->orWhereHas('invoice', fn (Builder $invoice): Builder => $invoice
                            ->where('client_invoices.workspace_id', $workspaceId)
                            ->where('client_invoices.service_period_end', '>=', $from->toDateString())))))
            ->orderBy('worked_on')
            ->orderBy('id');
    }
}
