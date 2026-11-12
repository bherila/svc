<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Support\Billing\DeferredBacklog;
use App\Support\Billing\DeferredWorkDates;
use App\Support\Concurrency\Locks;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Loads what {@see DeferredBacklog} computes, inside one workspace.
 *
 * The persistence half of the read model: a fixed number of queries however
 * many agreements are read, then the calculation runs on what came back. The
 * outstanding set uses the termination collection's eligibility predicates
 * ({@see DeferredBillingAllocator::collectForTermination()}) - the shared
 * `awaitingDeferredBilling` and `billableForInvoicing` scopes, the same
 * project-chain check over the whole client before any agreement narrows it,
 * and the agreement's project scope. Work after an agreement's end remains
 * visible for an explicit buy-down, bounded by any successor's start. Retainer
 * capacity and automatic termination collection still stop at the end.
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

        // The integrity check invoice generation makes before it bills anything
        // (`assertCompanyProjectChainsAgree()`): every time entry of each
        // client, inside the workspace and claiming it from outside, before any
        // agreement narrows the set - so a malformed row cannot disappear
        // behind a project scope, a status, or the trend's window. Where
        // generation would refuse, the figure is withheld here.
        $history = $periods === [] ? null : $this->history($workspaceId, $companyIds, $through, CarbonImmutable::parse($periods[0].'-01'));
        $disagreeing = array_flip([
            ...$this->projectChainGuard->companiesClaimedOutsideWorkspace($workspaceId, $companyIds),
            ...$this->projectChainGuard->companiesWithDisagreeingChains($workspaceId, ClientTimeEntry::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('client_company_id', $companyIds)),
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
                $this->dates($agreement, $clientSegments, $through)->settlementThrough,
                ! isset($disagreeing[(int) $agreement->client_company_id]),
                $outstanding->filter(static fn (ClientTimeEntry $entry): bool => $entry->isWithinAgreementScope($agreement))->values(),
                $moved->filter(static fn (ClientTimeEntry $entry): bool => $entry->isWithinAgreementScope($agreement))->values(),
                $periods,
                $carriedMinutes[(int) $agreement->id] ?? null,
            );
        }

        return $backlogs;
    }

    /**
     * The last day of retainer capacity and automatic termination collection.
     * The ledger's re-carried balance must never extend beyond this day.
     */
    public function segmentEnd(Workspace $workspace, ClientAgreement $agreement, CarbonImmutable $through): CarbonImmutable
    {
        $companyId = (int) $agreement->client_company_id;

        return $this->dates($agreement, $this->segments((int) $workspace->id, [$companyId])[$companyId] ?? new Collection, $through)->capacityThrough;
    }

    /** Work through this day may be disclosed and explicitly settled, even after termination. */
    public function settlementEnd(Workspace $workspace, ClientAgreement $agreement, CarbonImmutable $through): CarbonImmutable
    {
        if ((int) $agreement->workspace_id !== (int) $workspace->id) {
            throw new LogicException('Deferred settlement is read inside the agreement\'s own workspace.');
        }
        $companyId = (int) $agreement->client_company_id;

        return $this->dates($agreement, $this->segments((int) $workspace->id, [$companyId])[$companyId] ?? new Collection, $through)->settlementThrough;
    }

    /**
     * The last day before a successor for the same project scope takes over
     * from this agreement, or null when none does. From that day on its
     * ordinary work is the successor's to bill, at the successor's terms; a
     * termination without a successor hands nothing over, and the outgoing
     * agreement still bills what is logged after it.
     */
    /**
     * @param  Collection<int, ClientAgreement>|null  $segments  the client's agreements in generation
     *                                                           order, when the caller read them itself - with row locks, at issue
     */
    public function handedOverAfter(int $workspaceId, ClientAgreement $agreement, ?Collection $segments = null): ?CarbonImmutable
    {
        $companyId = (int) $agreement->client_company_id;
        $successor = $this->agreements->successorAgreementForGeneration($segments ?? $this->segments($workspaceId, [$companyId])[$companyId] ?? new Collection, $agreement);

        return $successor instanceof ClientAgreement ? $successor->starts_on->startOfDay()->subDay() : null;
    }

    /**
     * The client's agreements in generation order, read with row locks: the
     * current rows, whatever snapshot the caller's transaction holds. For an
     * issue, which takes them before the invoice, where agreements rank.
     *
     * @return Collection<int, ClientAgreement>
     */
    public function lockedSegments(int $workspaceId, int $companyId): Collection
    {
        return ClientAgreement::query()
            ->where('workspace_id', $workspaceId)
            ->where('client_company_id', $companyId)
            ->whereIn('status', ['active', 'paused', 'terminated', 'expired'])
            ->orderBy('starts_on')
            ->orderBy('id')
            ->tap(Locks::forUpdate())
            ->get();
    }

    /**
     * The day this agreement took over from a predecessor for the same project
     * scope - its own start - or null when it succeeds nobody. Work before it
     * is the predecessor's to bill.
     */
    /** @param  Collection<int, ClientAgreement>|null  $segments  as for {@see self::handedOverAfter()} */
    public function tookOverOn(int $workspaceId, ClientAgreement $agreement, ?Collection $segments = null): ?CarbonImmutable
    {
        $companyId = (int) $agreement->client_company_id;
        $segments ??= $this->segments($workspaceId, [$companyId])[$companyId] ?? new Collection;
        foreach ($segments as $candidate) {
            $successor = $this->agreements->successorAgreementForGeneration($segments, $candidate);
            if ($successor instanceof ClientAgreement && $successor->id === $agreement->id) {
                return $agreement->starts_on->startOfDay();
            }
        }

        return null;
    }

    /**
     * Settlement follows work ownership through a handover; capacity also
     * stops at the agreement's own end. Without a successor, post-end work
     * stays on the shelf for explicit settlement at the existing terms.
     *
     * @param  Collection<int, ClientAgreement>  $segments  the client's agreements, in generation order
     */
    private function dates(ClientAgreement $agreement, Collection $segments, CarbonImmutable $through): DeferredWorkDates
    {
        $successor = $this->agreements->successorAgreementForGeneration($segments, $agreement);

        return DeferredWorkDates::through($through, $agreement->termination_date, $successor?->starts_on);
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
