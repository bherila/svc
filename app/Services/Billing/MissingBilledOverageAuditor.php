<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\MissingBilledOverageCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Count charged invoices whose billed-overage figure is null (#144).
 *
 * The shared billed-overage ledger and the interim generator read what an
 * agreement has already charged so the next period does not charge it again.
 * SQL aggregation contributes nothing for a null, so every reader first asks
 * the invoice for a known figure and refuses rather than silently treating an
 * unknown charge as zero.
 *
 * This is the same defect class as #135 by a different route. There, a `<=`
 * answered false for a null and the whole row fell out of the window. Here the
 * row is inside the window and the value it contributes vanishes.
 *
 * ## Why this cannot be fixed the way #135 was
 *
 * `service_period_end` could be read fail-closed, because the question was
 * which side of a window a row falls on and counting an unplaceable row as
 * *inside* turns a double charge into capacity credited early. There is no
 * equivalent here: the question is *how much* was billed, and a null is not a
 * quantity. Coercing it to zero is the current behaviour and is the bug;
 * coercing it to anything else invents a number. So this counts, and the fix is
 * a decision - refuse on unknown, or establish the column is never null on a
 * charged invoice and make it `NOT NULL` for that status.
 *
 * The agreement count is the one that sizes the exposure, because the sums are
 * per agreement: ten bad invoices on one agreement corrupt one figure, and one
 * bad invoice on each of ten agreements corrupts ten.
 */
final class MissingBilledOverageAuditor
{
    public function count(?Workspace $workspace = null): MissingBilledOverageCounts
    {
        // The same funnel shape the unplaceable audit uses, and each stage
        // alone overstates for the same reasons: a draft has charged nobody,
        // and a row is only summed against an agreement that exists in its own
        // workspace, since every one of the three sums filters on both keys and
        // `client_agreement_id` is unconstrained lineage that can dangle or
        // cross tenants.
        $missing = $this->invoices($workspace)->whereNull('hours_billed_at_rate');
        $charged = (clone $missing)->whereIn('status', InvoiceStatus::charged());
        $onAgreement = $this->onAnAgreementInItsOwnWorkspace(clone $charged);

        // Counted into locals rather than inline, so the order the two reads of
        // `$onAgreement` happen in is stated rather than inherited from
        // argument evaluation - `distinct()` mutates the builder, and the
        // plain count has to happen first.
        $onAgreementCount = $onAgreement->count();

        // Distinct agreements, not invoices: the sums are per agreement, so
        // this is how many already-billed figures are wrong rather than how
        // many rows are missing a value.
        $agreementsAffected = $onAgreement->distinct()->count('client_agreement_id');

        return new MissingBilledOverageCounts(
            invoices: $this->invoices($workspace)->count(),
            withoutABilledOverage: $missing->count(),
            chargedOfThose: $charged->count(),
            onAnAgreementOfThose: $onAgreementCount,
            agreementsAffected: $agreementsAffected,
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
     * @param  Builder<ClientInvoice>  $invoices
     * @return Builder<ClientInvoice>
     */
    private function onAnAgreementInItsOwnWorkspace(Builder $invoices): Builder
    {
        return $invoices->whereExists(
            fn (QueryBuilder $query): QueryBuilder => $query
                // A string, because `DB::raw()` takes a SQL fragment - and an
                // integer literal here is a mutation target that can never be
                // killed, since EXISTS does not care what constant it selects.
                ->select(DB::raw('1'))
                ->from('client_agreements')
                ->whereColumn('client_agreements.id', 'client_invoices.client_agreement_id')
                ->whereColumn('client_agreements.workspace_id', 'client_invoices.workspace_id'),
        );
    }
}
