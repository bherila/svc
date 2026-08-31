<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Support\Billing\UndatedCollectibleInvoiceCounts;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Count the collectible invoices that no overdue figure can ever include.
 *
 * `AgentReadController::summary()` builds the collectible set and then narrows
 * it:
 *
 * ```php
 * $overdue = (clone $collectible)->whereDate('due_date', '<', now()->toDateString());
 * ```
 *
 * SQL answers false for a null rather than unknown, so an invoice with no due
 * date stays in `collectible_balances` - which does not filter on that column -
 * and vanishes from `overdue_count` and `overdue_balances`. The two figures
 * disagree and nothing says why (#149).
 *
 * ## Why the null survives
 *
 * `due_date` is nullable by design: `StoreInvoiceRequest` accepts null and the
 * importer passes the source value through. `InvoiceLifecycleService::issue()`
 * defaults a null due date to the issue date, which is the native lifecycle
 * contract - but it returns early for an invoice that is already charged, so an
 * imported issued or paid invoice never passes through that transition and keeps
 * its null permanently.
 *
 * ## Why this counts rather than fixes
 *
 * The tempting fix is `orWhereNull`, matching how #135 resolved the same
 * SQL-null-drop shape on `service_period_end`. #149 is explicit that it is the
 * wrong instinct here: that case was fail-closed against charging a client
 * twice, while this one would move invoices into a collections-adjacent report
 * on no evidence. An invoice with no stated term is not self-evidently late, and
 * silently reclassifying it is a different wrong answer rather than a safer one.
 *
 * The preferred repair is to backfill `issue_date`, which is exactly what
 * `issue()` would have done had the row gone through it. So the population is
 * split by whether an issue date exists, because that split is the size of what
 * the repair can reach - and the rest is what option (2), a separate
 * `undated_collectible` bucket, would have to cover.
 *
 * ## Why this is a service and not just a console command
 *
 * The same reason as {@see UnplaceableInvoiceAuditor}: the column stays nullable,
 * the importer keeps passing source values through, and an operator can create an
 * invoice with no due date at any time. Scope is a parameter for the same reason -
 * unscoped is the operator reading, and anything tenant-facing must pass its own
 * workspace.
 */
final class UndatedCollectibleInvoiceAuditor
{
    /**
     * The statuses the summary calls collectible.
     *
     * Its own list, restated. A draft has been charged to nobody and a paid
     * invoice has no balance, so neither is in the set whose two figures
     * disagree.
     *
     * @var list<string>
     */
    private const COLLECTIBLE_STATUSES = ['issued', 'partially_paid'];

    public function __construct(
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * Count the affected invoices, optionally within one workspace.
     *
     * Passing null counts across every workspace. That is deliberate and is the
     * operator/CLI reading; any caller rendering to a tenant must pass that
     * tenant's workspace.
     */
    public function count(?Workspace $workspace = null): UndatedCollectibleInvoiceCounts
    {
        // The summary's own definition, both halves. Balance rather than status
        // alone, because a partially-paid invoice settled to zero is collectible
        // by status and owed by nobody.
        $collectible = $this->invoices($workspace)
            ->whereIn('status', self::COLLECTIBLE_STATUSES)
            ->where('balance_amount', '>', 0);

        $undated = (clone $collectible)->whereNull('due_date');

        // The split that decides how much option (1) can repair. An issue date
        // is what `issue()` would have defaulted the due date to, so an invoice
        // carrying one can be dated exactly as the lifecycle would have dated
        // it; one carrying neither has no defensible due date at all and is the
        // population option (2)'s separate bucket exists for.
        $repairable = (clone $undated)->whereNotNull('issue_date');
        $unrepairable = (clone $undated)->whereNull('issue_date');

        // Of the repairable ones, those the repair would move straight into
        // overdue reporting. This is #149's "live rather than historical"
        // question in the only form the data can answer it: not whether anyone
        // is chasing the invoice, but whether backfilling changes a number
        // today. An operator approving the repair needs to know it will not
        // quietly triple the overdue balance.
        $wouldBecomeOverdue = $this->issuedBeforeToday(clone $repairable);

        return new UndatedCollectibleInvoiceCounts(
            invoices: $this->invoices($workspace)->count(),
            collectible: $collectible->count(),
            undated: $undated->count(),
            withAnIssueDate: $repairable->count(),
            withoutAnIssueDate: $unrepairable->count(),
            wouldBecomeOverdueIfBackfilled: $wouldBecomeOverdue->count(),
            undatedBalances: $this->balances($undated),
            wouldBecomeOverdueBalances: $this->balances($wouldBecomeOverdue),
        );
    }

    /**
     * @return Builder<ClientInvoice>
     */
    private function invoices(?Workspace $workspace): Builder
    {
        $invoices = ClientInvoice::query();

        return $workspace === null
            ? $invoices
            : $invoices->where('workspace_id', $workspace->id);
    }

    /**
     * Narrow to invoices issued before today, in their own workspace's calendar.
     *
     * "Today" is not one date. An unscoped audit spans workspaces in different
     * timezones, and an invoice issued this morning in Auckland is not late
     * because it is still yesterday in Los Angeles - so the boundary is resolved
     * once per timezone present and applied to that timezone's rows.
     *
     * The reader being audited compares against a bare `now()`, which is a
     * defensible simplification inside a summary of one workspace. It is not one
     * here: this figure is read once, across every tenant, by someone deciding
     * whether to approve a data migration - and a boundary a day out in either
     * direction is a client moved into or out of a collections report on the
     * strength of an arbitrary server timezone. Resolving per workspace is not
     * stricter than the summary, just correct where the summary is approximate.
     *
     * @param  Builder<ClientInvoice>  $invoices
     * @return Builder<ClientInvoice>
     */
    private function issuedBeforeToday(Builder $invoices): Builder
    {
        /** @var array<string, list<int>> $byTimezone */
        $byTimezone = [];

        foreach (
            (clone $invoices)
                ->join('workspaces', 'workspaces.id', '=', 'client_invoices.workspace_id')
                ->select('workspaces.timezone', 'workspaces.id')
                ->distinct()
                ->get() as $row
        ) {
            $byTimezone[(string) $row->getAttribute('timezone')][] = (int) $row->getAttribute('id');
        }

        // No rows, so no groups. Left as an impossible condition rather than an
        // empty `where` closure, which SQL reads as no condition at all and
        // would return every invoice in the set.
        if ($byTimezone === []) {
            return $invoices->whereRaw('1 = 0');
        }

        return $invoices->where(function (Builder $query) use ($byTimezone): void {
            foreach ($byTimezone as $timezone => $workspaceIds) {
                $query->orWhere(function (Builder $group) use ($timezone, $workspaceIds): void {
                    $group->whereIn('client_invoices.workspace_id', $workspaceIds)
                        ->whereDate('issue_date', '<', $this->clock->today($timezone)->toDateString());
                });
            }
        });
    }

    /**
     * Outstanding balance by currency.
     *
     * By currency rather than summed, because these are money and a total
     * across currencies is not a quantity. The summary reports its balances the
     * same way, so a reader can hold the two side by side; a single number here
     * would have to be compared against several there.
     *
     * @param  Builder<ClientInvoice>  $invoices
     * @return array<string, int>
     */
    private function balances(Builder $invoices): array
    {
        $balances = [];

        foreach (
            (clone $invoices)
                ->select('currency', DB::raw('sum(balance_amount) as total'))
                ->groupBy('currency')
                ->get() as $row
        ) {
            $balances[(string) $row->getAttribute('currency')] = (int) $row->getAttribute('total');
        }

        ksort($balances);

        return $balances;
    }
}
