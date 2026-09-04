<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Support\Billing\EligibleSetChanged;
use App\Support\Billing\UndatedCollectibleInvoiceRepairCounts;
use App\Support\Concurrency\Locks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Date the collectible invoices that were never dated (#149, option 1).
 *
 * A collectible invoice with a null `due_date` sits in collectible balances and
 * in no overdue figure, because SQL answers false for a null rather than
 * unknown. The repair is to give it the due date the lifecycle would have given
 * it: {@see InvoiceLifecycleService::issue()} defaults a null due date to the
 * issue date, and returns early for an invoice that is already charged - which
 * is exactly how an imported issued or paid row keeps its null forever.
 *
 * So this is not inventing a date. It is applying the native contract to rows
 * that never passed through the transition that states it.
 *
 * ## Why not `orWhereNull` in the query
 *
 * #149 is explicit, and this file exists to keep that reasoning next to the
 * code. Widening the overdue predicate would move invoices into a
 * collections-adjacent report on no evidence. An invoice with no stated term is
 * not self-evidently late; calling it late is a different wrong answer, not a
 * safer one. Repairing the data leaves every query honest.
 *
 * ## What it will not touch
 *
 * An invoice with no issue date either. There is no defensible date to give it,
 * and guessing one is the thing this avoids. Those are counted and left alone -
 * the population option (2)'s separate `undated_collectible` bucket exists for.
 * The audit found none, so that bucket is currently unnecessary rather than
 * merely unbuilt.
 *
 * The population is the auditor's, restated nowhere: this asks
 * {@see UndatedCollectibleInvoiceAuditor} for the same predicate rather than
 * writing a second version of "collectible", because a repair that sizes itself
 * differently from the audit that justified it is not the repair that was
 * approved.
 */
final class UndatedCollectibleInvoiceRepairer
{
    public function __construct(
        private readonly UndatedCollectibleInvoiceAuditor $auditor = new UndatedCollectibleInvoiceAuditor,
    ) {}

    /**
     * Set each repairable invoice's due date to its issue date, in one workspace.
     *
     * The workspace is required, unlike on the auditor beside it, and the
     * asymmetry is deliberate: an unscoped *read* is the operator's view of
     * every tenant at once, while an unscoped *write* is a single statement
     * mutating billing records across every tenant, with no way to validate one
     * or roll the correction out gradually. A caller wanting every workspace
     * iterates them and is told what happened in each.
     *
     * `$apply` false counts without writing, so the same call that reports is
     * the one that acts - a dry run cannot drift from the real thing when it is
     * the same code path with one branch flipped.
     *
     * `$expected`, when given, is the count an operator approved. If the
     * eligible set has changed under the lock since then the repair aborts
     * rather than writing rows nobody agreed to - an undated paid invoice
     * becoming partially paid mid-prompt is enough to change it.
     *
     * @throws EligibleSetChanged when `$expected` no longer matches
     */
    public function repair(Workspace $workspace, bool $apply = false, ?int $expected = null): UndatedCollectibleInvoiceRepairCounts
    {
        return DB::transaction(function () use ($workspace, $apply, $expected): UndatedCollectibleInvoiceRepairCounts {
            // Locked before counting, so the number reported is the number
            // written. Without this an invoice could be issued between the count
            // and the update and be repaired without ever being counted, or
            // counted and then paid.
            $eligible = $this->repairable($workspace)->tap(Locks::forUpdate())->count();

            if ($apply && $expected !== null && $expected !== $eligible) {
                throw new EligibleSetChanged($expected, $eligible);
            }

            if (! $apply) {
                return new UndatedCollectibleInvoiceRepairCounts(
                    eligible: $eligible,
                    repaired: 0,
                    skippedWithoutAnIssueDate: $this->undatable($workspace)->count(),
                    applied: false,
                );
            }

            // Column to column, in one statement. Reading each row and saving it
            // back would fire model events on invoices that are already charged,
            // and the repair is a correction to a stored fact rather than a
            // lifecycle transition - nothing about the invoice has happened.
            $repaired = $this->repairable($workspace)->update([
                'due_date' => DB::raw('issue_date'),
            ]);

            return new UndatedCollectibleInvoiceRepairCounts(
                eligible: $eligible,
                repaired: $repaired,
                skippedWithoutAnIssueDate: $this->undatable($workspace)->count(),
                applied: true,
            );
        });
    }

    /**
     * Collectible, undated, and carrying an issue date to be dated from.
     *
     * @return Builder<ClientInvoice>
     */
    private function repairable(Workspace $workspace): Builder
    {
        return $this->auditor->charged($workspace)
            ->whereNull('due_date')
            ->whereNotNull('issue_date');
    }

    /**
     * Collectible and undated, with nothing to date it from.
     *
     * @return Builder<ClientInvoice>
     */
    private function undatable(Workspace $workspace): Builder
    {
        return $this->auditor->charged($workspace)
            ->whereNull('due_date')
            ->whereNull('issue_date');
    }
}
