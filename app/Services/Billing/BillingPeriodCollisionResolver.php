<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientInvoice;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\PeriodClaim;
use App\Support\Billing\PeriodClaimVerdict;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Tests\Feature\Concurrency\LockOrderConformanceTest;

/**
 * Decide whether a billing period is already covered, and by whose invoice.
 *
 * Extracted from {@see BillingScheduleService::generateDue()}, where it had
 * grown into one nested `where` closure carrying five separate decisions. Three
 * successive reviews each found a real defect inside that predicate - an
 * unqualified null read an operator's ad-hoc invoice as the cadence one, then
 * an unqualified null matched every schedule the company had - and each fix
 * added another nesting level to SQL that could not be read or tested branch by
 * branch. The decisions are the same ones; they are in PHP so that each is
 * separately visible and separately pinned by a test.
 *
 * ## The one rule underneath all of it
 *
 * `client_billing_schedule_id` and `client_agreement_id` are nullable, and SQL
 * compares a null to a value as UNKNOWN - so a predicate naming either column
 * silently drops the rows that most need to be found. The original guard was
 * `where('client_billing_schedule_id', $schedule->id)` plus two date matches,
 * which an unlinked invoice for exactly that period could never satisfy: the
 * schedule concluded the period was unbilled and issued a second invoice for
 * it. `billing_schedule_service_period_unique` did not reject the second,
 * because a unique index does not constrain a null.
 *
 * So the period and the tenant are matched in SQL, and ownership is read in
 * PHP, where "this column is null" is a case that can be handled rather than a
 * row that vanishes.
 *
 * ## Ownership is resolved, never assumed
 *
 * Both lineage columns are unconstrained integers. A non-null value is a claim
 * that some schedule or agreement owns the row, not proof that one does, and
 * treating an unresolvable claim as "belongs to someone else" hands back
 * exactly the invisibility this class exists to remove: a dangling or foreign
 * id would exclude the invoice from both arms, and the schedule would bill the
 * period again. {@see UnplaceableInvoiceAuditor} already refuses to trust the
 * same column for the same reason.
 *
 * Every non-null id is therefore resolved against the invoice's *own* workspace
 * and client, and a row whose lineage does not resolve - or whose schedule and
 * agreement contradict each other - is refused rather than skipped.
 *
 * ## Overlap, not equality
 *
 * A period is covered when an invoice covers any of it, not only when the dates
 * match exactly. `ClientInvoicingService::assertNoOverlappingInvoice()` has
 * always used inclusive overlap; this used to use equality on both boundaries,
 * so an invoice for July-August did not stop the schedule billing August. A
 * partial overlap is not silently billed and not silently skipped - billing
 * double-charges the shared days, skipping loses the days the other invoice
 * does not cover - so it is refused.
 *
 * ## Status is deliberately not read
 *
 * No status filter anywhere here. A voided invoice still blocks its period,
 * because regenerating it would write the same
 * `(client_billing_schedule_id, service_period_start, service_period_end)` and
 * collide with the unique index - a hard failure rather than a silent skip.
 * Whether a voided cadence invoice should free its period is a product question
 * with its own issue; it is not decided by omission here.
 */
final class BillingPeriodCollisionResolver
{
    /**
     * What the schedule should do about the period `$start`..`$end`.
     *
     * The schedule must already be locked by the caller. Nothing here takes a
     * lock: these are reads inside the caller's transaction, and taking one
     * would put a second resource into an order
     * {@see LockOrderConformanceTest} has not been
     * told about.
     */
    public function resolve(ClientBillingSchedule $schedule, CarbonImmutable $start, CarbonImmutable $end): PeriodClaim
    {
        $unplaceable = $this->anUnplaceableInvoiceOfMine($schedule);
        if ($unplaceable instanceof ClientInvoice) {
            return PeriodClaim::refused(sprintf(
                'Invoice %s belongs to this billing schedule or its agreement but states no complete service period, '
                .'so no date comparison can tell whether it covers %s to %s. A period guard cannot see it and the '
                .'unique index does not constrain the missing date, so billing this period could duplicate it. '
                .'Give that invoice a service period start and end before billing.',
                $unplaceable->invoice_number,
                $start->toDateString(),
                $end->toDateString(),
            ));
        }

        $candidates = $this->overlapping($schedule, $start, $end);
        if ($candidates->isEmpty()) {
            return PeriodClaim::clear();
        }

        $schedules = $this->resolvedSchedules($schedule, $candidates);
        $agreements = $this->resolvedAgreements($schedule, $candidates);

        $billed = null;
        foreach ($candidates as $candidate) {
            $claim = $this->classify($schedule, $candidate, $start, $end, $schedules, $agreements);
            if ($claim->verdict === PeriodClaimVerdict::Refused) {
                // A refusal wins over a match. Candidates are ordered by id, so
                // which refusal is reported does not depend on how the database
                // happened to return the rows.
                return $claim;
            }
            if ($claim->verdict === PeriodClaimVerdict::AlreadyBilled && ! $billed instanceof PeriodClaim) {
                $billed = $claim;
            }
        }

        return $billed ?? PeriodClaim::clear();
    }

    /**
     * Classify one overlapping invoice against this schedule.
     *
     * @param  Collection<int, ClientBillingSchedule>  $schedules  resolved, keyed by id
     * @param  Collection<int, ClientAgreement>  $agreements  resolved, keyed by id
     */
    private function classify(
        ClientBillingSchedule $schedule,
        ClientInvoice $candidate,
        CarbonImmutable $start,
        CarbonImmutable $end,
        Collection $schedules,
        Collection $agreements,
    ): PeriodClaim {
        $scheduleId = $candidate->client_billing_schedule_id;
        $agreementId = $candidate->client_agreement_id;

        // Lineage first, because every question below reads it. An id that does
        // not resolve is not evidence of anything, and the safe reading of "I
        // cannot tell whose this is" is never "not mine".
        if ($scheduleId !== null && ! $schedules->has($scheduleId)) {
            return PeriodClaim::refused($this->danglingMessage($candidate, 'billing schedule', $start, $end));
        }
        if ($agreementId !== null && ! $agreements->has($agreementId)) {
            return PeriodClaim::refused($this->danglingMessage($candidate, 'agreement', $start, $end));
        }

        // A schedule always names exactly one agreement -
        // `client_billing_schedules.client_agreement_id` is non-nullable behind
        // a composite-tenant foreign key - so a resolved schedule always has an
        // agreement to disagree with.
        $named = $scheduleId === null ? null : $schedules->get($scheduleId);
        if ($named instanceof ClientBillingSchedule
            && $agreementId !== null
            && $named->client_agreement_id !== $agreementId) {
            return PeriodClaim::refused(sprintf(
                'Invoice %s overlaps %s to %s and names a billing schedule and an agreement that do not belong '
                .'together, so which one owns it cannot be decided here. Correct its lineage before billing this '
                .'period.',
                $candidate->invoice_number,
                $start->toDateString(),
                $end->toDateString(),
            ));
        }

        if ($scheduleId === $schedule->id) {
            // Mine outright, whatever kind it is. The kind exclusion below
            // applies only to *unlinked* rows: an interim or ad-hoc invoice
            // this schedule actually produced is still this schedule's, and a
            // second cadence invoice for the period it names would collide with
            // the unique index.
            return $this->mine($candidate, $start, $end);
        }

        if ($scheduleId !== null) {
            // A resolved, consistent other schedule. Not this period. Blocking
            // here would trade a double-charge for a schedule that silently
            // stops billing, which is the more expensive mistake and the one
            // nothing in the suite would notice.
            return PeriodClaim::clear();
        }

        // Unlinked from here down. `InvoiceKind::cycleGuardExclusions()` already
        // says an interim or ad-hoc invoice must not block a cadence one, and
        // `ClientInvoicingService::assertNoOverlappingInvoice()` reads the same
        // list, so the two guards cannot drift apart. Neither kind carries a
        // schedule, which is exactly why an unqualified null read one of them
        // as this period's cadence invoice and advanced past an unbilled
        // period.
        if ($candidate->invoice_kind !== null
            && in_array($candidate->invoice_kind, InvoiceKind::cycleGuardExclusions(), true)) {
            return PeriodClaim::clear();
        }

        if ($agreementId !== null) {
            // `ClientInvoicingService` creates cadence invoices with an
            // agreement and no schedule, so this is the ordinary shape of a
            // cadence invoice that did not come from a schedule.
            return $agreementId === $schedule->client_agreement_id
                ? $this->mine($candidate, $start, $end)
                : PeriodClaim::clear();
        }

        // Names neither. It matches every schedule this client has and at most
        // one of them can be the one it covers, so reading it as "mine" makes a
        // single invoice suppress several: each schedule returns it, each
        // advances its own `next_run_on`, and at least one agreement goes
        // unbilled for a period nothing charged.
        //
        // The fail-closed reading is therefore kept only where it is
        // unambiguous. See `anotherOwnerCouldClaim()` for what "unambiguous"
        // means and why it is not a question about schedules.
        if ($this->anotherOwnerCouldClaim($schedule)) {
            return PeriodClaim::refused(sprintf(
                'Invoice %s overlaps %s to %s for this client but names neither a billing schedule nor an '
                .'agreement, and this client has more than one agreement or schedule that could own it. It cannot '
                .'be attributed to one of them here. Set its agreement, or its schedule, before billing this period.',
                $candidate->invoice_number,
                $start->toDateString(),
                $end->toDateString(),
            ));
        }

        return $this->mine($candidate, $start, $end);
    }

    /**
     * An invoice established as this schedule's: billed if it covers exactly
     * this period, refused if it covers only part of it.
     */
    private function mine(ClientInvoice $candidate, CarbonImmutable $start, CarbonImmutable $end): PeriodClaim
    {
        $coversExactly = $candidate->service_period_start?->isSameDay($start) === true
            && $candidate->service_period_end?->isSameDay($end) === true;

        if ($coversExactly) {
            return PeriodClaim::alreadyBilled($candidate);
        }

        return PeriodClaim::refused(sprintf(
            'Invoice %s covers %s to %s, which overlaps the period %s to %s being billed now without matching it. '
            .'Billing anyway would charge the shared days twice and skipping would leave the rest of the period '
            .'unbilled, so neither is done. Re-key that invoice, or the schedule cadence, so the periods line up.',
            $candidate->invoice_number,
            $candidate->service_period_start?->toDateString() ?? '?',
            $candidate->service_period_end?->toDateString() ?? '?',
            $start->toDateString(),
            $end->toDateString(),
        ));
    }

    /**
     * Whether anything other than this schedule could own an unattributed row.
     *
     * Deliberately *not* "does another active schedule exist". A cadence
     * invoice does not need a schedule to exist at all - `ClientInvoicingService`
     * creates them with an agreement and no schedule - so a client can hold an
     * agreement that has produced invoices and has no schedule, or one whose
     * schedule is inactive, or one that has since been terminated.
     * `AgreementSelector` treats a client's billing history as a sequence of
     * agreement segments for exactly that reason. An unattributed row from any
     * of those is invisible to a question about *active schedules*, so the
     * single remaining active schedule would adopt it and advance past its own
     * unbilled period.
     *
     * Any status, therefore, and agreements rather than schedules: a terminated
     * agreement's historical invoice is the likeliest way this row exists at
     * all. Asking instead whether a rival is *currently due* would be worse -
     * `next_run_on` is a mutable cursor, and a schedule that already produced
     * the row has by definition advanced past it.
     */
    private function anotherOwnerCouldClaim(ClientBillingSchedule $schedule): bool
    {
        $rivalAgreement = ClientAgreement::query()
            ->where('workspace_id', $schedule->workspace_id)
            ->where('client_company_id', $schedule->client_company_id)
            ->whereKeyNot($schedule->client_agreement_id)
            ->exists();

        if ($rivalAgreement) {
            return true;
        }

        // Ordinarily unreachable, and kept for the case where it is not.
        // `billing_schedule_agreement_unique` gives a workspace one schedule
        // per agreement, so a second active schedule on this client normally
        // implies a second agreement on it and the check above has already
        // answered. It stops implying that if a schedule's own
        // `client_company_id` ever disagrees with its agreement's - nothing
        // enforces that pair - and this is the reading that stays correct
        // either way.
        return ClientBillingSchedule::query()
            ->where('workspace_id', $schedule->workspace_id)
            ->where('client_company_id', $schedule->client_company_id)
            ->where('is_active', true)
            ->whereKeyNot($schedule->id)
            ->exists();
    }

    /**
     * An invoice this schedule or its agreement owns that states no complete
     * period, and which no date comparison can therefore place.
     *
     * Scoped to rows demonstrably this schedule's. A row naming *neither* owner
     * and stating no period is not evidence of anything - there is no date to
     * tie it to this period and no lineage to tie it to this schedule - and
     * refusing on it would halt billing for every schedule the client has on
     * the strength of a row that may belong to none of them. That row is
     * reported by {@see UnplaceableInvoiceAuditor} for a person to repair
     * instead.
     */
    private function anUnplaceableInvoiceOfMine(ClientBillingSchedule $schedule): ?ClientInvoice
    {
        return ClientInvoice::query()
            ->where('workspace_id', $schedule->workspace_id)
            ->where('client_company_id', $schedule->client_company_id)
            ->where(function (Builder $missing): void {
                $missing->whereNull('service_period_start')->orWhereNull('service_period_end');
            })
            ->where(function (Builder $mine) use ($schedule): void {
                $mine
                    ->where('client_billing_schedule_id', $schedule->id)
                    ->orWhere(function (Builder $unlinked) use ($schedule): void {
                        $unlinked
                            ->whereNull('client_billing_schedule_id')
                            ->where(function (Builder $kind): void {
                                $kind
                                    ->whereNull('invoice_kind')
                                    ->orWhereNotIn('invoice_kind', InvoiceKind::cycleGuardExclusions());
                            })
                            ->where('client_agreement_id', $schedule->client_agreement_id);
                    });
            })
            ->orderBy('id')
            ->first();
    }

    /**
     * Invoices for this client whose period overlaps the one being billed.
     *
     * Inclusive on both ends, matching `assertNoOverlappingInvoice()`: a strict
     * comparison lets a new period start on an existing invoice's last billed
     * day, so that day would belong to two invoices.
     *
     * @return Collection<int, ClientInvoice>
     */
    private function overlapping(ClientBillingSchedule $schedule, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return ClientInvoice::query()
            ->where('workspace_id', $schedule->workspace_id)
            ->where('client_company_id', $schedule->client_company_id)
            ->whereNotNull('service_period_start')
            ->whereNotNull('service_period_end')
            ->whereDate('service_period_start', '<=', $end->toDateString())
            ->whereDate('service_period_end', '>=', $start->toDateString())
            ->orderBy('id')
            ->get();
    }

    /**
     * The schedules the candidates name, that exist in the candidates' own
     * workspace and client.
     *
     * @param  Collection<int, ClientInvoice>  $candidates
     * @return Collection<int, ClientBillingSchedule>
     */
    private function resolvedSchedules(ClientBillingSchedule $schedule, Collection $candidates): Collection
    {
        $ids = $candidates->pluck('client_billing_schedule_id')->filter()->unique()->values()->all();
        if ($ids === []) {
            return new Collection;
        }

        return ClientBillingSchedule::query()
            ->whereIn('id', $ids)
            ->where('workspace_id', $schedule->workspace_id)
            ->where('client_company_id', $schedule->client_company_id)
            ->get()
            ->keyBy('id');
    }

    /**
     * The agreements the candidates name, that exist in the candidates' own
     * workspace and client.
     *
     * @param  Collection<int, ClientInvoice>  $candidates
     * @return Collection<int, ClientAgreement>
     */
    private function resolvedAgreements(ClientBillingSchedule $schedule, Collection $candidates): Collection
    {
        $ids = $candidates->pluck('client_agreement_id')->filter()->unique()->values()->all();
        if ($ids === []) {
            return new Collection;
        }

        return ClientAgreement::query()
            ->whereIn('id', $ids)
            ->where('workspace_id', $schedule->workspace_id)
            ->where('client_company_id', $schedule->client_company_id)
            ->get()
            ->keyBy('id');
    }

    private function danglingMessage(ClientInvoice $candidate, string $what, CarbonImmutable $start, CarbonImmutable $end): string
    {
        return sprintf(
            'Invoice %s overlaps %s to %s and names a %s that does not exist for this client and workspace, so it '
            .'cannot be established whose period it covers. Treating it as another owner\'s would let this schedule '
            .'bill the period a second time. Correct its lineage before billing this period.',
            $candidate->invoice_number,
            $start->toDateString(),
            $end->toDateString(),
            $what,
        );
    }
}
