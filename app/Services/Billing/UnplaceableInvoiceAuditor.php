<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\UnplaceableInvoiceCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Count invoices whose period or cycle cannot be placed on a calendar.
 *
 * `service_period_end` is nullable and stays that way (#73): an invoice can be
 * created by hand without one, and the external importer passes the source
 * value through unchanged. Everything downstream, though, decides which period
 * an invoice belongs to by comparing that column, and SQL comparison answers
 * false for a null rather than unknown. So an unplaceable invoice does not
 * raise, does not warn, and does not appear - it is quietly treated as being
 * outside whatever window is being asked about.
 *
 * The sum in `ClientInvoicingService::totalBilledOveragesThrough()` is where
 * that costs money, and its read is now fail-closed (#135), which turns a
 * double charge into capacity credited a period early. This exists to find the
 * rows behind that fallback so they can be given a real period instead.
 *
 * The same class applies to `cycle_start`/`cycle_end` (#141), reported in two
 * counts because they endanger two different things - see the funnel below.
 *
 * ## Why this is a service and not just a console command
 *
 * The counting is the durable part. These are standing data-quality questions
 * rather than migration one-offs: the columns stay nullable, the importer keeps
 * passing source values through, and an operator can create an invoice with no
 * period at any time - which is why the command's own documentation says to run
 * it after every import and bulk edit. Anything that wants to show that drift,
 * console or screen, should consume one definition of "affected" rather than
 * re-deriving it and drifting from this one.
 *
 * Scope is a constructor-free parameter for the same reason. The console runs
 * unscoped across every workspace, which is what an operator sizing a migration
 * needs; a tenant-facing surface must pass its own workspace and see only that.
 * Building that in now is the difference between adding a screen later and
 * reimplementing the audit for it.
 */
final class UnplaceableInvoiceAuditor
{
    /**
     * Count the affected rows, optionally within one workspace.
     *
     * Passing null counts across every workspace. That is deliberate and is the
     * operator/CLI reading; any caller rendering to a tenant must pass that
     * tenant's workspace, because nothing else here scopes for them.
     */
    public function count(?Workspace $workspace = null): UnplaceableInvoiceCounts
    {
        // The same conditions the overage sum applies, in the same order. Each
        // one alone overstates: a draft has charged nobody; an invoice is only
        // summed against an agreement that exists in its own workspace, since
        // the sum filters on both keys and the agreement column is
        // unconstrained lineage that can dangle or cross tenants; and a row
        // with zero overage hours contributes nothing whichever side of the
        // window it lands on. Zero, not positive: negative hours move the sum
        // too - they shrink it - so the hours at stake are a magnitude, kept
        // from cancelling against the positive rows.
        $unplaceable = $this->invoices($workspace)->whereNull('service_period_end');
        $charged = (clone $unplaceable)->whereIn('status', InvoiceStatus::charged());
        $onAgreement = $this->onAnAgreementInItsOwnWorkspace(clone $charged);
        $affected = (clone $onAgreement)->where('hours_billed_at_rate', '!=', 0);

        // The cycle columns are the same class on different columns (#141), and
        // they endanger two different things, so they are counted twice.
        //
        // `InterimOverageGenerator::cycleInvoices()` matches on both, so a row
        // missing either is invisible to every caller. The charged funnel is
        // the money one: it feeds the already-billed subtraction and
        // `interimOverageHoursForCycle()`, and a row that drops out of those is
        // charged a second time. The live count is the guard one: the duplicate
        // checks that refuse to create a second invoice for a cycle read live
        // and settled statuses, and a row they cannot see costs a whole invoice
        // rather than a wrong number.
        $noCycle = $this->invoices($workspace)
            ->where(function (Builder $missing): void {
                $missing->whereNull('cycle_start')->orWhereNull('cycle_end');
            });

        // Kind first, exactly as those lookups apply it. Running this audit
        // against real data is what put this condition here: all three
        // null-cycle rows in the replay corpus are ad-hoc, and no cycle lookup
        // reads an ad-hoc invoice, so reporting them as exposed would have been
        // an overcount of a population that is in fact empty.
        $readByCycle = (clone $noCycle)->where(function (Builder $kind): void {
            $kind->whereNull('invoice_kind')->orWhereIn('invoice_kind', InvoiceKind::matchedByCycle());
        });

        $liveNoCycle = $this->onAnAgreementInItsOwnWorkspace(
            (clone $readByCycle)->whereIn('status', InvoiceStatus::live()),
        );
        $chargedNoCycle = $this->onAnAgreementInItsOwnWorkspace(
            (clone $readByCycle)->whereIn('status', InvoiceStatus::charged()),
        );
        $cycleAffected = (clone $chargedNoCycle)->where('hours_billed_at_rate', '!=', 0);

        return new UnplaceableInvoiceCounts(
            invoices: $this->invoices($workspace)->count(),
            withoutAServicePeriod: $unplaceable->count(),
            chargedOfThose: $charged->count(),
            onAnAgreementOfThose: $onAgreement->count(),
            affected: $affected->count(),
            overageHoursAtStake: $this->magnitude($affected),
            withoutACycle: $noCycle->count(),
            ofAKindReadByCycle: $readByCycle->count(),
            liveWithoutACycle: $liveNoCycle->count(),
            cycleAffected: $cycleAffected->count(),
            cycleOverageHoursAtStake: $this->magnitude($cycleAffected),
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
     * Overage hours as a magnitude, so negative rows do not cancel positive
     * ones and understate what is at stake.
     *
     * @param  Builder<ClientInvoice>  $invoices
     */
    private function magnitude(Builder $invoices): float
    {
        return round((float) $invoices->sum(DB::raw('abs(hours_billed_at_rate)')), 4);
    }

    /**
     * Narrow to invoices whose named agreement exists in their own workspace.
     *
     * Every sum and guard this reports on filters agreement and workspace
     * together. `client_agreement_id` is unconstrained lineage, so a row can
     * name an agreement that has been deleted or one belonging to another
     * tenant; no sum ever reads such a row, and counting it would overstate the
     * population this exists to bound.
     *
     * @param  Builder<ClientInvoice>  $invoices
     * @return Builder<ClientInvoice>
     */
    private function onAnAgreementInItsOwnWorkspace(Builder $invoices): Builder
    {
        return $invoices->whereExists(
            fn (QueryBuilder $query): QueryBuilder => $query
                ->select(DB::raw(1))
                ->from('client_agreements')
                ->whereColumn('client_agreements.id', 'client_invoices.client_agreement_id')
                ->whereColumn('client_agreements.workspace_id', 'client_invoices.workspace_id'),
        );
    }
}
