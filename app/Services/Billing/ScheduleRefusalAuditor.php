<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\ScheduleRefusalCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Count the invoices that would stop a billing schedule, and say why.
 *
 * {@see BillingPeriodCollisionResolver} refuses rather than guessing whenever
 * it cannot establish who owns an overlapping invoice. That is the right answer
 * - the alternatives are charging a client twice or silently skipping a period
 * nobody billed - but it is a *halt*, and not every halt clears itself: a
 * refusal naming a paid invoice stops that schedule on every run until someone
 * makes a financial correction, because `InvoiceLifecycleService::void()`
 * throws once `paid_amount > 0` and `updateDraft()` rewrites no period or
 * lineage column at any status.
 *
 * So the blast radius has to be measurable *before* the refusals are deployed,
 * not discovered afterwards one failed cron run at a time. That is what this
 * exists for. It answers, across a real database: how many rows would refuse,
 * which reason each would refuse for, and - the number that actually matters -
 * how many schedules would stop.
 *
 * ## Why it classifies in PHP rather than in SQL
 *
 * For the same three reasons the resolver does, and the third is not optional.
 *
 * The tenant is matched in SQL because that is a join. Everything after it -
 * ownership, kind, status - is read in PHP, where "this column is null" and
 * "this value is not one I recognise" are cases that can be handled rather than
 * rows that silently vanish from a predicate.
 *
 * It also has to *agree* with the resolver, and an independent SQL derivation
 * of the same six decisions is a second implementation that drifts. Here the
 * order of the branches is the order of `classify()`, readable side by side,
 * and `ScheduleRefusalAuditorTest` runs `generateDue()` against each shape to
 * pin the agreement rather than trusting it.
 *
 * And a status test cannot be written in SQL at all without the negation
 * `DisallowStatusNegationRule` forbids in this namespace. "Not void" and
 * "carries a status no case matches" are both negations over an open varchar
 * set, which is the fail-open shape that rule exists to prevent - and it is
 * right: `InvoiceStatus::tryFrom()` in PHP answers the same question without
 * ever having to enumerate what it does not know.
 *
 * ## Why this is a separate audit and not another column on the other one
 *
 * {@see UnplaceableInvoiceAuditor} asks a data-quality question: which rows
 * cannot be placed on a calendar at all. Its period-guard count is a *repair
 * ceiling* - it deliberately counts every status, including voided rows this
 * resolver clears and schedule-linked rows whose link may not resolve, because
 * a row worth investigating is not the same as a row that stops a run. Merging
 * the two would force one number to be both, and it would be wrong as whichever
 * one it was not.
 *
 * ## This is a ceiling too, and in one direction it is a floor
 *
 * A refusal happens for a `(schedule, period)` pair, not for a row on its own,
 * so nothing can be exact about it without enumerating every period every
 * schedule will ever bill. The two errors are deliberate and go in opposite
 * directions, which is why both are documented rather than averaged into one
 * reassuring number:
 *
 * - **Over-counts.** A row is counted if any schedule for its company could
 *   reach it. Whether one ever does depends on the periods that come due, so a
 *   row counted here may sit untouched forever.
 * - **Under-counts, and this is the important one.** The last refusal in
 *   `classify()` - a live invoice that overlaps a period without matching it -
 *   depends entirely on which period is being billed, and cannot be counted
 *   here at all. A zero from this audit is therefore *not* a promise that no
 *   schedule will halt. It bounds the lineage, status and unplaceable-period
 *   reasons, which are the ones a data repair can clear ahead of time.
 */
final class ScheduleRefusalAuditor
{
    private const NONE = '';

    private const DANGLING_SCHEDULE = 'dangling_schedule_link';

    private const DANGLING_AGREEMENT = 'dangling_agreement_link';

    private const CONTRADICTORY = 'contradictory_lineage';

    private const UNKNOWN_STATUS = 'unknown_status';

    private const INCOMPLETE_PERIOD = 'incomplete_period_on_an_owned_row';

    private const UNATTRIBUTED = 'unattributed_and_contested';

    /**
     * Count the rows that would refuse, optionally within one workspace.
     *
     * Passing null counts across every workspace, which is the operator/CLI
     * reading and the one a deployment gate wants. Any caller rendering to a
     * tenant must pass that tenant's workspace; nothing else here scopes for
     * them.
     */
    public function count(?Workspace $workspace = null): ScheduleRefusalCounts
    {
        $schedules = $this->schedules($workspace)->get();

        // Keyed by "workspace:company", which is the pair
        // `possiblyOverlapping()` matches on. An invoice for a company nobody
        // bills on a cadence is never a candidate however malformed it is, so
        // narrowing to these keys is what keeps this a useful number rather
        // than a restatement of the invoice table.
        $scheduledCompanies = [];
        $companyIds = [];
        foreach ($schedules as $schedule) {
            $scheduledCompanies[$this->tenantKey($schedule)][] = $schedule;
            $companyIds[(int) $schedule->client_company_id] = true;
        }
        $companyIds = array_keys($companyIds);

        $agreements = $this->agreementsFor($companyIds);

        $reasons = array_fill_keys([
            self::DANGLING_SCHEDULE, self::DANGLING_AGREEMENT, self::CONTRADICTORY,
            self::UNKNOWN_STATUS, self::INCOMPLETE_PERIOD, self::UNATTRIBUTED,
        ], 0);
        $haltedCompanies = [];
        $candidates = 0;

        // Chunked: this runs against a whole production invoice table, and the
        // point of the audit is defeated if running it is itself an incident.
        $this->candidates($workspace, $companyIds)
            ->chunkById(500, function (Collection $chunk) use (
                $scheduledCompanies, $agreements, &$reasons, &$haltedCompanies, &$candidates
            ): void {
                foreach ($chunk as $invoice) {
                    $key = $this->tenantKey($invoice);
                    // A company id alone is not a tenant, so the SQL narrowing
                    // above can pull in another workspace's row that happens to
                    // share one. It is not a candidate for anything here.
                    if (! isset($scheduledCompanies[$key])) {
                        continue;
                    }

                    $reason = $this->reasonFor(
                        $invoice,
                        $scheduledCompanies[$key],
                        $agreements[$key] ?? [],
                    );

                    if ($reason === self::NONE) {
                        $candidates++;

                        continue;
                    }

                    $candidates++;
                    $reasons[$reason]++;
                    $haltedCompanies[$key] = true;
                }
            });

        return new ScheduleRefusalCounts(
            invoices: $this->invoices($workspace)->count(),
            candidates: $candidates,
            wouldRefuse: array_sum($reasons),
            danglingScheduleLink: $reasons[self::DANGLING_SCHEDULE],
            danglingAgreementLink: $reasons[self::DANGLING_AGREEMENT],
            contradictoryLineage: $reasons[self::CONTRADICTORY],
            unknownStatus: $reasons[self::UNKNOWN_STATUS],
            incompletePeriodOnAnOwnedRow: $reasons[self::INCOMPLETE_PERIOD],
            unattributedAndContested: $reasons[self::UNATTRIBUTED],
            schedulesHalted: $schedules
                ->filter(fn (ClientBillingSchedule $schedule): bool => isset($haltedCompanies[$this->tenantKey($schedule)]))
                ->count(),
            schedules: $schedules->count(),
        );
    }

    /**
     * Why this invoice would refuse, or `NONE` if the resolver clears it.
     *
     * The branches are `BillingPeriodCollisionResolver::classify()`'s, in its
     * order, so the two can be read side by side - and so a row matching two
     * reasons is attributed to the first that would fire rather than counted
     * twice. Reasons that overlap sum to more than the total they explain,
     * which is the kind of number that gets a deployment gate ignored.
     *
     * The one thing deliberately not reproduced is the final period
     * comparison. Whether a live invoice overlaps without matching depends on
     * the period being billed, and no audit can answer it for periods that
     * have not come due; the class docblock says so out loud, and so does the
     * command's green line.
     *
     * @param  list<ClientBillingSchedule>  $schedules  every schedule for this invoice's company
     * @param  list<ClientAgreement>  $agreements  every agreement for this invoice's company
     */
    private function reasonFor(ClientInvoice $invoice, array $schedules, array $agreements): string
    {
        $scheduleId = $invoice->client_billing_schedule_id;
        $agreementId = $invoice->client_agreement_id;

        // `tryFrom` rather than any SQL test: a status this code cannot read is
        // a case to handle, not a row to drop.
        $status = InvoiceStatus::tryFrom((string) $invoice->status);

        // A known void never refuses. One covering the period exactly reports
        // it as already billed; any other is cleared before ownership is
        // resolved at all, so that voiding stays the way out.
        if ($status === InvoiceStatus::Void) {
            return self::NONE;
        }

        // An unlinked interim or ad-hoc invoice is exempted before the lineage
        // refusals, so it cannot halt anything either.
        if ($scheduleId === null
            && $invoice->invoice_kind !== null
            && in_array($invoice->invoice_kind, InvoiceKind::cycleGuardExclusions(), true)) {
            return self::NONE;
        }

        // No dates and no lineage is no evidence at all.
        if ($invoice->service_period_start === null && $invoice->service_period_end === null
            && $scheduleId === null && $agreementId === null) {
            return self::NONE;
        }

        $named = $this->named($schedules, $scheduleId);
        if ($scheduleId !== null && ! $named instanceof ClientBillingSchedule) {
            return self::DANGLING_SCHEDULE;
        }

        if ($agreementId !== null && ! in_array($agreementId, $this->ids($agreements), true)) {
            return self::DANGLING_AGREEMENT;
        }

        if ($named instanceof ClientBillingSchedule
            && $agreementId !== null
            && $named->client_agreement_id !== $agreementId) {
            return self::CONTRADICTORY;
        }

        // Past here the row reaches `mine()` for some schedule, which is the
        // only place status and an incomplete period are read - so both reasons
        // below are scoped to rows a schedule would actually claim.
        $claimed = $named instanceof ClientBillingSchedule
            || ($agreementId !== null && in_array($agreementId, $this->billedAgreementIds($schedules), true));

        if (! $claimed) {
            // Neither column set, so no schedule can prove the row is or is not
            // its own. It refuses only when some *other* owner could plausibly
            // claim it; with one agreement and one schedule there is no rival,
            // and the sole schedule treats the row as its own.
            return $this->aRivalCouldClaimIt($schedules, $agreements) ? self::UNATTRIBUTED : self::NONE;
        }

        if ($status === null) {
            return self::UNKNOWN_STATUS;
        }

        if ($invoice->service_period_start === null || $invoice->service_period_end === null) {
            return self::INCOMPLETE_PERIOD;
        }

        return self::NONE;
    }

    /**
     * Whether anyone but the schedule running could own an unattributed row.
     *
     * An agreement in the same company that no schedule bills is the ordinary
     * case: a second plausible owner the resolver will not guess between.
     * `billing_schedule_agreement_unique` makes a second schedule on the *same*
     * agreement impossible, so a company with two schedules necessarily has two
     * agreements and is caught by the same test - but both are asked, because
     * that index is the only thing standing between them and this is not the
     * place to depend on it silently.
     *
     * @param  list<ClientBillingSchedule>  $schedules
     * @param  list<ClientAgreement>  $agreements
     */
    private function aRivalCouldClaimIt(array $schedules, array $agreements): bool
    {
        if (count($schedules) > 1) {
            return true;
        }

        $billed = $this->billedAgreementIds($schedules);

        return array_filter(
            $this->ids($agreements),
            static fn (int $id): bool => ! in_array($id, $billed, true),
        ) !== [];
    }

    /**
     * @param  list<ClientBillingSchedule>  $schedules
     */
    private function named(array $schedules, ?int $scheduleId): ?ClientBillingSchedule
    {
        if ($scheduleId === null) {
            return null;
        }

        foreach ($schedules as $schedule) {
            if ((int) $schedule->id === $scheduleId) {
                return $schedule;
            }
        }

        return null;
    }

    /**
     * @param  list<ClientBillingSchedule>  $schedules
     * @return list<int>
     */
    private function billedAgreementIds(array $schedules): array
    {
        return array_map(
            static fn (ClientBillingSchedule $schedule): int => (int) $schedule->client_agreement_id,
            $schedules,
        );
    }

    /**
     * @param  list<ClientAgreement>  $agreements
     * @return list<int>
     */
    private function ids(array $agreements): array
    {
        return array_map(
            static fn (ClientAgreement $agreement): int => (int) $agreement->id,
            $agreements,
        );
    }

    /**
     * Invoices a schedule could reach, with only the columns that decide it.
     *
     * @param  list<int>  $companyIds
     * @return Builder<ClientInvoice>
     */
    private function candidates(?Workspace $workspace, array $companyIds): Builder
    {
        return $this->invoices($workspace)
            ->select([
                'id', 'workspace_id', 'client_company_id', 'status', 'invoice_kind',
                'client_billing_schedule_id', 'client_agreement_id',
                'service_period_start', 'service_period_end',
            ])
            ->whereIn('client_company_id', $companyIds);
    }

    /**
     * Agreements grouped by tenant key, for the companies that have schedules.
     *
     * @param  list<int>  $companyIds
     * @return array<string, list<ClientAgreement>>
     */
    private function agreementsFor(array $companyIds): array
    {
        if ($companyIds === []) {
            return [];
        }

        $grouped = [];
        foreach (ClientAgreement::query()
            ->select(['id', 'workspace_id', 'client_company_id'])
            ->whereIn('client_company_id', $companyIds)
            ->get() as $agreement) {
            $grouped[$this->tenantKey($agreement)][] = $agreement;
        }

        return $grouped;
    }

    /**
     * Workspace *and* company, exactly as `possiblyOverlapping()` matches.
     *
     * A company id alone is not a tenant: `client_company_id` is unique only
     * within a workspace, so keying on it would let one tenant's schedules
     * resolve another's lineage - the cross-tenant read every lookup in this
     * area is written to prevent.
     */
    private function tenantKey(ClientBillingSchedule|ClientAgreement|ClientInvoice $row): string
    {
        return $row->workspace_id.':'.$row->client_company_id;
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
     * @return Builder<ClientBillingSchedule>
     */
    private function schedules(?Workspace $workspace): Builder
    {
        $schedules = ClientBillingSchedule::query();

        return $workspace === null
            ? $schedules
            : $schedules->where('workspace_id', $workspace->id);
    }
}
