<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientInvoice;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\PeriodClaim;
use App\Support\Billing\PeriodClaimVerdict;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
 * ## One query, so there is no seam to fall through
 *
 * Complete and incomplete periods are fetched together, and a missing boundary
 * is read as unbounded in that direction. Two queries with two ownership tests
 * is what this replaced, and the join between them was a hole in exactly the
 * shape everything here exists to close - see `possiblyOverlapping()`.
 *
 * ## Status is read where it changes the answer, and never guessed
 *
 * An unrecognised status refuses. The column is a varchar, and
 * `InvoiceStatus::isSettledValue()` and `hasChargedValue()` both answer *yes*
 * to a value they do not know, for the reason that applies here too: a status
 * this code cannot read is one it cannot show has charged nobody. Asking
 * instead whether the status is absent from `live()` puts every unknown value
 * on the same path as `void`, which is the one reading that is unsafe in both
 * directions at once.
 *
 * A voided invoice covering *exactly* this period still blocks it, whatever its
 * status: voiding a cadence invoice is the documented way to waive its own
 * period, and regenerating it would write the same
 * `(client_billing_schedule_id, service_period_start, service_period_end)` and
 * collide with the unique index anyway.
 *
 * A *known* void that does not cover exactly this period is cleared before
 * ownership is resolved at all, because a refusal has to have a way out and for
 * a voided row there was none.
 * `InvoiceLifecycleService::updateDraft()` accepts nothing but a `draft`, so a
 * voided invoice cannot be re-keyed or given a period in the application at
 * all - a refusal on one would halt the schedule on every run with a database
 * edit as the only remedy, while
 * `ClientInvoicingService::assertNoOverlappingInvoice()` tells the operator to
 * void the existing invoice first. Reading status here is what makes that
 * advice true rather than a dead end. The waiver argument does not extend to
 * these cases either: it is about not reselling *the same* cycle, and neither a
 * differently-dated span nor a row with no period is that.
 *
 * `UnplaceableInvoiceAuditor` still counts every status, deliberately, and is a
 * *repair ceiling* rather than a mirror of what refuses here. It reports rows
 * worth investigating - including voided ones this resolver clears, and
 * schedule-linked ones whose link may not resolve - because the exact-match
 * block does read every status: a voided invoice with a complete period blocks
 * its period, while the same row missing a boundary does not, so the period its
 * void was meant to waive gets billed again. How many rows would actually stop
 * a run is a different question, and a different count.
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
        $candidates = $this->possiblyOverlapping($schedule, $start, $end);
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
        $status = InvoiceStatus::tryFrom((string) $candidate->status);
        $coversExactly = $candidate->service_period_start?->isSameDay($start) === true
            && $candidate->service_period_end?->isSameDay($end) === true;

        // A known void that does not cover exactly this period is irrelevant
        // before anything else is asked of it. It charged nobody, it cannot
        // stand in for this period, and its tuple cannot collide with the one
        // about to be written - so none of the refusals below need to establish
        // whose it is. Reached before them deliberately: a voided row with
        // dangling lineage used to refuse at the lineage check and never arrive
        // at the branch that clears it, which made voiding useless as the way
        // out that `assertNoOverlappingInvoice()` tells operators to take.
        if ($status === InvoiceStatus::Void && ! $coversExactly) {
            return PeriodClaim::clear();
        }

        // Kind first among the ownership questions, and only for unlinked rows. `cycleGuardExclusions()` says
        // an interim or ad-hoc invoice must never block a cadence one, and that
        // exemption has to be reached before the lineage refusals below or it
        // is not an exemption: an ad-hoc invoice whose `client_agreement_id`
        // dangles would hard-refuse the whole run over a row that is not
        // allowed to affect it either way. `client_invoices.client_agreement_id`
        // carries no foreign key, so an import or a repaired row reaches this.
        //
        // Unlinked only, because a row naming *this* schedule is this
        // schedule's whatever kind it carries - and one naming a schedule that
        // does not resolve cannot establish that it is exempt any more than it
        // can establish whose it is.
        if ($scheduleId === null
            && $candidate->invoice_kind !== null
            && in_array($candidate->invoice_kind, InvoiceKind::cycleGuardExclusions(), true)) {
            return PeriodClaim::clear();
        }

        // No date and no lineage is no evidence at all: nothing ties the row to
        // this period and nothing ties it to this schedule. Refusing on one
        // would halt every schedule the client has over a row that may belong
        // to none of them. `UnplaceableInvoiceAuditor` reports it for repair
        // instead.
        if ($candidate->service_period_start === null
            && $candidate->service_period_end === null
            && $scheduleId === null
            && $agreementId === null) {
            return PeriodClaim::clear();
        }

        // Lineage next, because every question below reads it. An id that does
        // not resolve is not evidence of anything, and the safe reading of "I
        // cannot tell whose this is" is never "not mine".
        if ($scheduleId !== null && ! $schedules->has($scheduleId)) {
            return PeriodClaim::refused($this->danglingMessage($candidate, 'a billing schedule', $start, $end));
        }
        if ($agreementId !== null && ! $agreements->has($agreementId)) {
            return PeriodClaim::refused($this->danglingMessage($candidate, 'an agreement', $start, $end));
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
            return $this->mine($candidate, $status, $coversExactly, $start, $end);
        }

        if ($scheduleId !== null) {
            // A resolved, consistent other schedule. Not this period. Blocking
            // here would trade a double-charge for a schedule that silently
            // stops billing, which is the more expensive mistake and the one
            // nothing in the suite would notice.
            return PeriodClaim::clear();
        }

        // Unlinked from here down; the kind exemption above has already let go
        // of the interim and ad-hoc rows. `assertNoOverlappingInvoice()` reads
        // the same list, so the two guards cannot drift apart. Neither kind
        // carries a schedule, which is exactly why an unqualified null read one
        // of them as this period's cadence invoice and advanced past an
        // unbilled period.
        if ($agreementId !== null) {
            // `ClientInvoicingService` creates cadence invoices with an
            // agreement and no schedule, so this is the ordinary shape of a
            // cadence invoice that did not come from a schedule.
            return $agreementId === $schedule->client_agreement_id
                ? $this->mine($candidate, $status, $coversExactly, $start, $end)
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

        return $this->mine($candidate, $status, $coversExactly, $start, $end);
    }

    /**
     * An invoice established as this schedule's: billed if it covers exactly
     * this period, refused if it covers only part of it.
     */
    /**
     * An invoice established as this schedule's.
     *
     * Exactly this period, and it is billed. Anything else here is a row that
     * could cover part of this period and cannot be shown to cover all of it,
     * which is neither answer: billing charges the shared days twice, skipping
     * leaves the rest unbilled.
     */
    private function mine(
        ClientInvoice $candidate,
        ?InvoiceStatus $status,
        bool $coversExactly,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): PeriodClaim {
        // Fail closed on a status this code does not recognise, exactly as
        // `InvoiceStatus::isSettledValue()` and `hasChargedValue()` do. The
        // column is a varchar; an unknown value cannot be shown to have charged
        // nobody, and reading it as "not live, therefore ignorable" is the one
        // reading that is unsafe in both directions - it would skip a period
        // that was never billed, or bill one that already was.
        if ($status === null) {
            return PeriodClaim::refused(sprintf(
                'Invoice %s overlaps %s to %s and carries the status "%s", which this application does not '
                .'recognise, so whether it has already charged this client cannot be established. Classify or '
                .'correct that status before billing this period.',
                $candidate->invoice_number,
                $start->toDateString(),
                $end->toDateString(),
                (string) $candidate->status,
            ));
        }

        if ($coversExactly) {
            // Any known status, void included. Voiding a cadence invoice is the
            // documented way to waive its own period, and regenerating one
            // would write the same
            // `(client_billing_schedule_id, service_period_start, service_period_end)`
            // and collide with the unique index anyway.
            return PeriodClaim::alreadyBilled($candidate);
        }

        // A row that cannot be placed at all. Its known boundary was close
        // enough to this period for the query to return it, and the missing one
        // is what stops any comparison settling whether it covers this period.
        if ($candidate->service_period_start === null || $candidate->service_period_end === null) {
            return PeriodClaim::refused(sprintf(
                'Invoice %s belongs to this billing schedule or its agreement, states only %s, and could cover the '
                .'period %s to %s being billed now. No date comparison can settle whether it does, and the unique '
                .'index does not constrain the missing date, so billing this period could duplicate it. %s',
                $candidate->invoice_number,
                $candidate->service_period_start === null
                    ? 'a period end of '.($candidate->service_period_end?->toDateString() ?? '?')
                    : 'a period start of '.$candidate->service_period_start->toDateString(),
                $start->toDateString(),
                $end->toDateString(),
                $this->remedy($status),
            ));
        }

        // A known void never reaches here - it is cleared before ownership is
        // even resolved, so that voiding remains the way out
        // `ClientInvoicingService::assertNoOverlappingInvoice()` tells operators
        // to take.
        return PeriodClaim::refused(sprintf(
            'Invoice %s covers %s to %s, which overlaps the period %s to %s being billed now without matching it. '
            .'Billing anyway would charge the shared days twice and skipping would leave the rest of the period '
            .'unbilled, so neither is done. %s',
            $candidate->invoice_number,
            $candidate->service_period_start->toDateString(),
            $candidate->service_period_end->toDateString(),
            $start->toDateString(),
            $end->toDateString(),
            $this->remedy($status),
        ));
    }

    /**
     * What an operator can actually do about the offending invoice.
     *
     * Status-specific because the application's repair surface is, and an
     * earlier revision of these messages advised a repair it forbids:
     * `InvoiceLifecycleService::updateDraft()` rewrites currency, due date,
     * notes and lines only - never a service period and never a lineage
     * column - so "re-key that invoice" was never possible from the
     * application, at any status. `void()` refuses once `paid_amount` is
     * positive, so it is not available to a partially paid or paid row either.
     */
    private function remedy(InvoiceStatus $status): string
    {
        return match ($status) {
            InvoiceStatus::Draft => 'Discard that draft, and the schedule will raise a correctly dated replacement.',
            InvoiceStatus::Issued => 'Void that invoice, and the schedule will raise a correctly dated replacement.',
            InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid => 'Money has already been taken against that invoice, '
                .'so it can be neither voided nor re-dated here. It needs a financial correction first.',
            // Cleared long before this for a non-exact overlap, and returned as
            // already billed for an exact one. Present so the match stays
            // exhaustive if the vocabulary grows.
            InvoiceStatus::Void => 'That invoice is void and should not have reached this refusal; please report it.',
        };
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
     * Invoices for this client whose period could overlap the one being billed.
     *
     * One query, covering complete and incomplete periods alike, and that is
     * the point rather than a tidying. There used to be two - an inclusive
     * overlap over rows with both boundaries, and a separate sweep for rows of
     * this schedule's missing one - and the seam between them was a hole. The
     * incomplete sweep carried its own, simpler ownership test, so a row with a
     * dangling schedule id and a null boundary fell out of both: the overlap
     * query required two dates, the sweep required the id to be this schedule's
     * or null. The resolver answered `Clear` and the schedule issued a second
     * invoice - the original defect, rebuilt at the join between two queries.
     * Adding a null to one date turned "unsafe, refuse" into "invisible,
     * issue".
     *
     * A missing boundary is treated as unbounded in that direction, which is
     * what it means: an invoice stating an end and no start could have begun at
     * any time before it. So the interval overlaps unless a *known* boundary
     * rules it out.
     *
     * That last part is why this takes the period at all. The old sweep did
     * not, so a paid invoice ending in January 2024 with no start halted August
     * 2026 and every period after it, even though its known end proves it
     * cannot reach them - and a paid row can be neither voided nor re-dated,
     * making the outage permanent without a database edit. Only an interval
     * that could actually reach this period is allowed to stop it.
     *
     * @return Collection<int, ClientInvoice>
     */
    private function possiblyOverlapping(ClientBillingSchedule $schedule, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return ClientInvoice::query()
            ->where('workspace_id', $schedule->workspace_id)
            ->where('client_company_id', $schedule->client_company_id)
            ->where(function (Builder $startSide) use ($end): void {
                $startSide
                    ->whereNull('service_period_start')
                    ->orWhere('service_period_start', '<=', $end->toDateString());
            })
            ->where(function (Builder $endSide) use ($start): void {
                $endSide
                    ->whereNull('service_period_end')
                    ->orWhere('service_period_end', '>=', $start->toDateString());
            })
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
            'Invoice %s overlaps %s to %s and names %s that does not exist for this client and workspace, so it '
            .'cannot be established whose period it covers. Treating it as another owner\'s would let this schedule '
            .'bill the period a second time. Correct its lineage before billing this period.',
            $candidate->invoice_number,
            $start->toDateString(),
            $end->toDateString(),
            $what,
        );
    }
}
