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
 * The chronological `BilledOverageLedger` is where that costs money, and its
 * read is fail-closed (#135): a positive charge with no service month stops
 * pricing rather than being dropped or guessed. This exists to find those rows
 * so they can be given a real period instead.
 *
 * The same class applies to `cycle_start`/`cycle_end` (#141), reported in two
 * counts because they endanger two different things - see the funnel below.
 *
 * ## Why `service_period_start` is counted apart from `service_period_end`
 *
 * The end boundary is the money one and owns the funnel below it: the overage
 * ledger reads it, so a row missing it is measured all the way down to the
 * hours at stake. The start boundary endangers something else - the duplicate
 * guards. `BillingScheduleService::generateDue()` and
 * `ClientInvoicingService::assertNoOverlappingInvoice()` both place an invoice
 * by comparing its period, and a row stating an end but no start is invisible
 * to them for exactly the reason everything else here exists: SQL compares a
 * null to a date as UNKNOWN. What it costs is a whole duplicate invoice rather
 * than a wrong number.
 *
 * The second count carries no status filter, because the schedule guard it
 * measures carries none either - see the comment on it below.
 *
 * Counted separately rather than folded into `withoutAServicePeriod`, because
 * widening that would drag start-only rows into the overage funnel and
 * overstate the money exposure - the same overcount this class already refuses
 * to make for ad-hoc null-cycle rows. #219/#224 asked whether such rows exist
 * in production and the audit could not answer: it only ever looked at the end
 * boundary, so a zero there was read as evidence about a column it never
 * examined.
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

        // The start boundary, on its own terms. `InvoiceKind::cycleGuardExclusions()`
        // rather than `matchedByCycle()`: this is the population the *period*
        // guards read, and they exclude the kinds that must not block a cadence
        // invoice instead of naming the kinds they accept. A null kind is not
        // representable in either list and is admitted here, as everywhere - a
        // migrated invoice carries none and those guards still read it.
        //
        // **No status filter**, unlike every other funnel here, because the
        // guard this measures has none. `BillingScheduleService::generateDue()`
        // matches on tenant, period and ownership and never looks at status, so
        // a *voided* invoice blocks its period - deliberately, since the
        // replacement would collide with
        // `billing_schedule_service_period_unique`. A voided row missing its
        // start therefore defeats that guard exactly as a live one does, and
        // the schedule bills a waived period again with nothing to reject the
        // write, because a unique index does not constrain the null that caused
        // it. Filtering to `live()` here by analogy with the cycle counts above
        // reported that row as no exposure at all.
        //
        // `ClientInvoicingService::assertNoOverlappingInvoice()` *is* scoped to
        // `live()`, so this over-reports for that one guard. That is the safe
        // direction for an audit: it is a ceiling on the affected population,
        // and a count that hides a real exposure is worse than one that names a
        // row already harmless.
        $noPeriodStart = $this->invoices($workspace)->whereNull('service_period_start');
        $readByAPeriodGuard = (clone $noPeriodStart)
            ->where(function (Builder $kind): void {
                $kind
                    ->whereNull('invoice_kind')
                    ->orWhereNotIn('invoice_kind', InvoiceKind::cycleGuardExclusions());
            });
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
            withoutAServicePeriodStart: $noPeriodStart->count(),
            ofAKindReadByAPeriodGuard: $readByAPeriodGuard->count(),
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
                ->select(DB::raw('1'))
                ->from('client_agreements')
                ->whereColumn('client_agreements.id', 'client_invoices.client_agreement_id')
                ->whereColumn('client_agreements.workspace_id', 'client_invoices.workspace_id'),
        );
    }
}
