<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Support\Billing\UndatedAgreementCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Count the agreements with no start date, and the work they are pricing.
 *
 * `client_agreements.starts_on` is nullable and the code has at least seven
 * answers for what a null means, several incompatible (#147). Two of them
 * matter here and they contradict each other directly:
 * `AgreementBillingRateResolver::resolve()` treats a null as **in force** -
 * `whereNull('starts_on')->orWhereDate('starts_on', '<=', $workedOn)` - so an
 * undated agreement can stamp its hourly rate onto approved time, while
 * `TimeSheetController::capacityByMonth` writes `whereNotNull('starts_on')` and
 * gives it no capacity at all, and the date-based selectors drop it on a bare
 * `starts_on <= date` that SQL answers false for.
 *
 * So work can be priced against an agreement the rest of the system treats as
 * not in force. This counts how much.
 *
 * ## Why an audit and not a fix
 *
 * #147 proposes the contract "a null start date means the agreement is not yet
 * in force", which would make the resolver the wrong one. That is the right
 * shape, and it is exactly the change that must not be made blind: if migrated
 * hourly-only agreements are using a null deliberately as a timeless rate,
 * adopting it stops them pricing work that they are pricing correctly today.
 * The repair there is to backfill an explicit historical start date, not to keep
 * two meanings for the null - and the size of that group is what this reports.
 *
 * Agreements created natively always carry one, since `ProposalWorkflow::accept()`
 * sets `starts_on` to the acceptance date. The undated population is an imported
 * one, which is why sizing it comes before changing anything.
 *
 * ## Two bounds rather than one number
 *
 * The resolver does not pick by query alone - it collects candidates, then sorts
 * them, preferring a project-specific agreement over a company-wide one. There
 * is no honest single count of "entries priced by an undated agreement", so this
 * reports the bracket instead: {@see UndatedAgreementCounts::$entriesWithAnUndatedCandidate}
 * is every entry one of these could be selected for, an upper bound; and
 * {@see UndatedAgreementCounts::$entriesWithNoOtherCandidate} is the entries
 * where nothing else is eligible, so the undated agreement is certainly the one
 * chosen. A single number in the middle would have to be wrong in one direction
 * and would not say which.
 *
 * ## Why this is a service and not just a console command
 *
 * The same reason as {@see UnplaceableInvoiceAuditor}: the column stays nullable
 * until #73 closes, the importer keeps passing source values through, and the
 * question is a standing one rather than a migration one-off. Scope is a
 * parameter for the same reason - unscoped is the operator reading, and anything
 * tenant-facing must pass its own workspace.
 */
final class UndatedAgreementAuditor
{
    /**
     * Statuses an agreement can hold and still be selected for pricing.
     *
     * The resolver's own list. A draft was never in force, so an undated draft
     * is not this defect - it is a row someone has not finished writing.
     *
     * @var list<string>
     */
    private const PRICING_STATUSES = ['active', 'paused', 'terminated', 'expired'];

    /**
     * Count the affected agreements and the work they touch.
     *
     * Passing null counts across every workspace. That is deliberate and is the
     * operator/CLI reading; any caller rendering to a tenant must pass that
     * tenant's workspace.
     */
    public function count(?Workspace $workspace = null): UndatedAgreementCounts
    {
        $undated = $this->agreements($workspace)
            ->whereNull('starts_on')
            ->whereIn('status', self::PRICING_STATUSES);

        // Grouped rather than funnelled: status and cadence are not narrowings
        // of each other, and #147 asks for both because they bound different
        // things. Status says whether anyone is still working under it; cadence
        // says which of the cycle readers touch it, and `one_time` is the
        // default rather than a statement, so a large bucket there is a sign the
        // cadence was never set rather than that it was chosen.
        $byStatus = $this->grouped(clone $undated, fn (ClientAgreement $a): string => $a->status);
        $byCadence = $this->grouped(clone $undated, fn (ClientAgreement $a): string => $a->billing_cadence);

        // Two different blast radii, which is why #147 asks for the split. An
        // hourly-only agreement reaches the rate resolver and nothing else. One
        // carrying retainer terms also reaches capacity and cycle generation,
        // where `BillingCycleResolver` throws on a null start rather than
        // quietly excluding it.
        $withRetainerTerms = (clone $undated)->where(function (Builder $terms): void {
            $terms->whereNotNull('retainer_minutes')
                ->orWhereNotNull('retainer_amount')
                ->orWhereNotNull('period_retainer_minutes')
                ->orWhereNotNull('period_retainer_amount');
        });

        $hourlyOnly = (clone $undated)
            ->whereNotNull('hourly_rate_amount')
            ->whereNull('retainer_minutes')
            ->whereNull('retainer_amount')
            ->whereNull('period_retainer_minutes')
            ->whereNull('period_retainer_amount');

        $candidates = $this->entriesWithAnUndatedCandidate($workspace);

        return new UndatedAgreementCounts(
            agreements: $this->agreements($workspace)->count(),
            undated: $undated->count(),
            byStatus: $byStatus,
            byCadence: $byCadence,
            hourlyOnly: $hourlyOnly->count(),
            withRetainerTerms: $withRetainerTerms->count(),
            entriesWithAnUndatedCandidate: $candidates->count(),
            entriesWithNoOtherCandidate: $this->withNoDatedCandidate($candidates)->count(),
            billedLinesOnAnUndatedAgreement: $this->billedLines($workspace),
        );
    }

    /**
     * @return Builder<ClientAgreement>
     */
    private function agreements(?Workspace $workspace): Builder
    {
        $agreements = ClientAgreement::query();

        return $workspace === null
            ? $agreements
            : $agreements->where('workspace_id', $workspace->id);
    }

    /**
     * Tally the agreements by some string property of each.
     *
     * The property is read through a closure rather than named as a string and
     * fetched with `getAttribute`. That returns `mixed`, and casting it to
     * string is exactly what the strict analysis lane forbids for good reason:
     * it would silently stringify whatever the column turned out to hold. A
     * closure reading a declared property is checked instead of coerced.
     *
     * Both properties this is called with are NOT NULL - `status` has no
     * default and `billing_cadence` defaults to `one_time` - so there is no
     * absent case to fold in, and inventing a bucket for one would be a branch
     * nothing can reach.
     *
     * @param  Builder<ClientAgreement>  $agreements
     * @param  callable(ClientAgreement): string  $key
     * @return array<string, int>
     */
    private function grouped(Builder $agreements, callable $key): array
    {
        $counts = [];

        foreach ($agreements->get() as $agreement) {
            $bucket = $key($agreement);
            $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Time entries an undated agreement is eligible to price.
     *
     * The resolver's own eligibility, restated: same workspace and company, a
     * status that can price, the agreement either company-wide or on the
     * entry's project, and an end date that has not passed the worked-on day.
     * The start-date clause is the one thing left out, because an undated row
     * passes it by definition and that is the subject.
     *
     * An upper bound. Eligibility is not selection - the resolver sorts its
     * candidates and prefers a project-specific agreement - so an entry counted
     * here may in fact be priced by a dated agreement that outranks this one.
     *
     * @return Builder<ClientTimeEntry>
     */
    private function entriesWithAnUndatedCandidate(?Workspace $workspace): Builder
    {
        $entries = ClientTimeEntry::query();

        if ($workspace !== null) {
            $entries->where('workspace_id', $workspace->id);
        }

        return $entries->whereExists(
            fn (QueryBuilder $query): QueryBuilder => $this->eligibleAgreement($query)->whereNull('client_agreements.starts_on'),
        );
    }

    /**
     * Of those, the entries nothing else is eligible for.
     *
     * A lower bound, and the number that decides urgency: with no dated
     * candidate there is nothing for the resolver's ordering to prefer, so the
     * undated agreement is the one selected and the work is certainly priced by
     * a row the timesheet and the selectors treat as not in force.
     *
     * @param  Builder<ClientTimeEntry>  $entries
     * @return Builder<ClientTimeEntry>
     */
    private function withNoDatedCandidate(Builder $entries): Builder
    {
        return (clone $entries)->whereNotExists(
            fn (QueryBuilder $query): QueryBuilder => $this->eligibleAgreement($query)
                ->whereNotNull('client_agreements.starts_on')
                // Column against column, like the `ends_on` clause it mirrors.
                // Both are date columns, so this is the same comparison the
                // resolver makes against a date string.
                ->whereColumn('client_agreements.starts_on', '<=', 'client_time_entries.worked_on'),
        );
    }

    /**
     * The eligibility an agreement must satisfy to price a given entry, minus
     * the start-date clause the callers add themselves.
     */
    private function eligibleAgreement(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->select(DB::raw(1))
            ->from('client_agreements')
            ->whereColumn('client_agreements.workspace_id', 'client_time_entries.workspace_id')
            ->whereColumn('client_agreements.client_company_id', 'client_time_entries.client_company_id')
            ->whereIn('client_agreements.status', self::PRICING_STATUSES)
            ->where(function (QueryBuilder $scope): void {
                $scope->whereNull('client_agreements.client_project_id')
                    ->orWhereColumn('client_agreements.client_project_id', 'client_time_entries.client_project_id');
            })
            ->where(function (QueryBuilder $ends): void {
                $ends->whereNull('client_agreements.ends_on')
                    ->orWhereColumn('client_agreements.ends_on', '>=', 'client_time_entries.worked_on');
            });
    }

    /**
     * Invoice lines already billed against an undated agreement.
     *
     * #147's fourth question: whether anything generated has already depended
     * on the effective-date fallback. A line naming one of these agreements is
     * that dependency made concrete - work already invoiced under a row the
     * proposed contract would say was not in force when it was billed.
     */
    private function billedLines(?Workspace $workspace): int
    {
        $lines = DB::table('client_invoice_lines')->whereExists(
            fn (QueryBuilder $query): QueryBuilder => $query
                ->select(DB::raw(1))
                ->from('client_agreements')
                ->whereColumn('client_agreements.id', 'client_invoice_lines.client_agreement_id')
                ->whereColumn('client_agreements.workspace_id', 'client_invoice_lines.workspace_id')
                ->whereNull('client_agreements.starts_on'),
        );

        if ($workspace !== null) {
            $lines->where('client_invoice_lines.workspace_id', $workspace->id);
        }

        return $lines->count();
    }
}
