<?php

namespace App\Services\Billing;

use App\Models\ClientCompany;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Puts split time entries back together once nothing is billing them.
 *
 * Allocating time across capacity pools splits one entry into several rows.
 * When an invoice is voided or regenerated those fragments come unlinked, and
 * leaving them split would show a client four ten-minute lines where they did
 * one forty-minute piece of work.
 *
 * The predecessor matched fragments on date, user, description, project and
 * task. That folds together two genuinely separate entries whenever someone
 * logs the same description twice against one project on one day. Here every
 * fragment records the entry it came from, so a group is exactly the rows that
 * were once one row, and nothing else can join it.
 *
 * A group is only merged when no member is billed and the members still agree
 * on the things that made them one entry. A fragment approved or deferred
 * independently of its siblings has become its own record of work, and merging
 * it away would erase that decision.
 */
final class AllocationService
{
    public function __construct(
        private readonly TimeEntryProjectChainGuard $projectChainGuard = new TimeEntryProjectChainGuard,
    ) {}

    /**
     * Recombine every fully unbilled fragment group for a company.
     *
     * @return int Rows removed by merging
     */
    public function recombineUnlinkedFragments(Workspace $workspace, ClientCompany $company): int
    {
        return DB::transaction(function () use ($workspace, $company): int {
            // Discovering lineage roots through the current workspace would
            // hide a root moved elsewhere while leaving its siblings here.
            // Refuse the complete company chain before a destructive merge
            // can sever those visible fragments from the omitted root.
            $this->projectChainGuard->assertCompanyProjectChainsAgree($company);

            $rootIds = ClientTimeEntry::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $company->id)
                ->whereNotNull('split_from_time_entry_id')
                ->distinct()
                ->pluck('split_from_time_entry_id');

            $recombined = 0;

            foreach ($rootIds as $rootId) {
                $group = $this->group($workspace, $company, (int) $rootId);

                if ($group->count() < 2 || ! $this->canMerge($group)) {
                    continue;
                }

                $recombined += $group->count() - 1;
                $this->merge($group);
            }

            return $recombined;
        });
    }

    /**
     * Every surviving row that was once part of one entry: the root and its
     * fragments, locked together so a concurrent invoice run cannot bill one
     * of them while this decides they are all free.
     *
     * @return Collection<int, ClientTimeEntry>
     */
    private function group(Workspace $workspace, ClientCompany $company, int $rootId): Collection
    {
        $group = ClientTimeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->where(function ($query) use ($rootId): void {
                $query->whereKey($rootId)->orWhere('split_from_time_entry_id', $rootId);
            })
            ->with('invoiceLines')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // Validate after taking the same row locks recombination relies on, so
        // a concurrent edit cannot move a fragment to another project's chain
        // between the integrity check and the destructive merge.
        $this->projectChainGuard->assertProjectChainsAgree(
            $company,
            ClientTimeEntry::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($group->modelKeys()),
        );

        return $group;
    }

    /**
     * @param  Collection<int, ClientTimeEntry>  $group
     */
    private function canMerge(Collection $group): bool
    {
        if ($group->count() < 2) {
            return false;
        }

        if ($group->contains(fn (ClientTimeEntry $entry): bool => $entry->invoiceLines->isNotEmpty())) {
            return false;
        }

        // Fragments start identical. If one has since been approved, deferred, or
        // made billable on its own, it is no longer the same piece of work.
        // Every field merge() would discard belongs here. It keeps the
        // survivor's values and deletes the rest, so a fragment edited on its
        // own - a corrected date, a rewritten description, a reassignment -
        // would have that edit silently thrown away and its minutes folded in
        // under the survivor's version.
        // A typed tuple compared with `===`, not a joined string.
        //
        // Two encodings failed here in turn. A `|` join collides whenever a
        // free-text field contains the delimiter, because the boundaries move.
        // Encoding that same array as JSON fixes the boundaries but not the
        // sentinel: `?? 'null'` makes a real null and the literal string
        // "null" indistinguishable, and `job_type` is free text a person can
        // type that into.
        //
        // Comparing the arrays directly removes the question. There is no
        // encoding to collide, nulls stay null, and an int stays an int - and
        // because a match here *deletes* a fragment, a false equality is
        // destructive rather than merely conservative.
        $signature = fn (ClientTimeEntry $entry): array => [
            'status' => $entry->status,
            'is_billable' => $entry->is_billable,
            'is_deferred' => $entry->is_deferred,
            'is_visible_to_client' => $entry->is_visible_to_client,
            'billing_rate_amount' => $entry->billing_rate_amount,
            'currency' => $entry->currency,
            'worked_on' => $entry->worked_on->toDateString(),
            'description' => $entry->description,
            'client_visible_description' => $entry->client_visible_description,
            'job_type' => $entry->job_type,
            'client_project_id' => $entry->client_project_id,
            'client_task_id' => $entry->client_task_id,
            'user_id' => $entry->user_id,
            'billing_rate_source' => $entry->billing_rate_source,
            'approved_by_user_id' => $entry->approved_by_user_id,
            'approved_at' => $entry->approved_at?->toIso8601String(),
            'subcontractor_billing_mode' => $entry->subcontractor_billing_mode?->value,
            'subcontractor_cost_amount' => $entry->subcontractor_cost_amount,
            'subcontractor_cost_currency' => $entry->subcontractor_cost_currency,
            'subcontractor_cost_metadata' => $entry->subcontractor_cost_metadata,
        ];

        $first = $signature($group->first());

        return $group->every(fn (ClientTimeEntry $entry): bool => $signature($entry) === $first);
    }

    /**
     * Fold a group into its lowest-id member and delete the rest.
     *
     * @param  Collection<int, ClientTimeEntry>  $group
     */
    private function merge(Collection $group): ClientTimeEntry
    {
        if (! $this->canMerge($group)) {
            throw new InvalidArgumentException('Cannot merge fragments: one is billed, or they no longer agree.');
        }

        $survivor = $group->first();
        $totalMinutes = (int) $group->sum('minutes');

        foreach ($group->skip(1) as $fragment) {
            $fragment->delete();
        }

        $survivor->forceFill([
            'minutes' => $totalMinutes,
            // The survivor is whole again, so it is nobody's fragment.
            'split_from_time_entry_id' => null,
        ])->save();

        return $survivor->refresh();
    }
}
