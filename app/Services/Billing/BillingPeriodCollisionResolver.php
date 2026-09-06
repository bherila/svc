<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientInvoice;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\PeriodClaim;
use App\Support\Billing\PeriodClaimVerdict;
use App\Support\Billing\PeriodRefusalReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LogicException;

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

        // Every candidate is classified and the exact claims collected, rather
        // than the first interesting one winning. A refusal still short-
        // circuits - it is already the most cautious answer available and
        // nothing later can soften it - but "billed" and "pending" cannot be
        // ranked against each other one row at a time. Which of them is right
        // is a property of the whole set: see below.
        //
        // The `match` is exhaustive with no `default` on purpose. An earlier
        // revision tested for the verdicts it knew and let anything else fall
        // through to `Clear` - the one outcome that writes an invoice - so a
        // fifth case added to `PeriodClaimVerdict` would have failed *open*,
        // silently, in the guard whose whole job is to fail closed. Now it
        // throws instead. Candidates are ordered by id, so which of two equally
        // cautious rows is reported does not depend on how the database
        // happened to return them.
        $refusal = null;

        /** @var list<PeriodClaim> $exact */
        $exact = [];

        foreach ($candidates as $candidate) {
            $claim = $this->classify($schedule, $candidate, $start, $end, $schedules, $agreements);

            match ($claim->verdict) {
                PeriodClaimVerdict::Refused => $refusal = $claim,
                PeriodClaimVerdict::PendingDraft, PeriodClaimVerdict::AlreadyBilled => $exact[] = $claim,
                PeriodClaimVerdict::Clear => null,
            };

            if ($refusal instanceof PeriodClaim) {
                return $refusal;
            }
        }

        return $this->settle($exact, $start, $end);
    }

    /**
     * One answer for the period from every invoice covering it exactly.
     *
     * More than one row covering exactly the same period cannot be ruled out
     * by the schema: the unique index constrains
     * `(client_billing_schedule_id, service_period_start, service_period_end)`
     * and does not constrain a null, so an unlinked cadence invoice from
     * `ClientInvoicingService` and a schedule-linked one can both cover the
     * same period. Which of them bills it is a property of the whole set, so
     * the set is settled here rather than one row at a time.
     *
     * ## Status decides, because status is all a repair can change
     *
     * An earlier revision refused whenever the set held more than one row,
     * whatever their statuses, and told the operator to discard the duplicate
     * draft or void the duplicate invoice. Neither repair could clear it.
     * `InvoiceLifecycleService::discardDraft()` and `void()` both change the
     * status to `void` and keep the service period, and an exact void is
     * itself an exact claim - so issued + draft became issued + void, still
     * two rows, still refused. Every remedy the message named looped back to
     * the same refusal, and the only way out was a database edit. For two
     * issued rows that turned a historical anomaly into a permanent halt.
     *
     * So the rows are read by status, and the rule is the one that makes each
     * advertised repair actually release the period:
     *
     * - voids only: a waiver, however many times it was recorded, and reported
     *   as already billed exactly as a lone exact void always has been;
     * - one charged invoice and any number of voids: that invoice bills the
     *   period, and the voids beside it are the residue of a repair rather
     *   than a second claim on the money;
     * - one draft and nothing else: pending, with the advice to issue it or
     *   discard it - see {@see PeriodClaimVerdict::PendingDraft};
     * - two or more charged invoices: a conflict, whatever else is present.
     *   The client has been asked for the money twice, and advancing past it
     *   is how that stays invisible. Voiding the unpaid duplicate leaves one
     *   charged row plus a void, which the second rule then settles;
     * - a draft beside anything else: a conflict. Beside a charged invoice,
     *   issuing it charges the client twice - `issue()` runs no overlap check
     *   - so the draft is discarded and the charged row survives. Beside an
     *   exact void, which is intended cannot be read from the rows: the void
     *   may be a deliberate waiver the draft would undo, or the discarded half
     *   of a repair the draft is meant to complete. Discarding the draft
     *   settles it as waived; issuing it settles it as billed. Two drafts are
     *   discarded down to one, which is then one of those two cases.
     *
     * Every conflict therefore has an exit through the ordinary lifecycle
     * except one: two or more rows that have each taken money. `void()` throws
     * once `paid_amount > 0`, so the message for that shape says a financial
     * correction is needed and does not recommend a call the application will
     * refuse.
     *
     * Ranking the draft above the rest - which is what the revision before
     * that did - was the worse mistake, and is why the draft cases refuse
     * rather than pend: the halt was safe, but the sentence told the operator
     * to *issue* the draft, and following it produced two issued invoices for
     * one period or undid a waiver.
     *
     * @param  list<PeriodClaim>  $exact  pending or already-billed claims, each carrying an invoice
     */
    private function settle(array $exact, CarbonImmutable $start, CarbonImmutable $end): PeriodClaim
    {
        /** @var list<PeriodClaim> $drafts */
        $drafts = [];
        /** @var list<PeriodClaim> $charged */
        $charged = [];
        /** @var list<PeriodClaim> $voids */
        $voids = [];

        foreach ($exact as $claim) {
            // Exhaustive over the status vocabulary, so a sixth status cannot
            // be silently read as any of these three. `mine()` has already
            // refused a value no case matches, so `null` cannot reach here;
            // it is handled rather than assumed because this is the guard.
            match (InvoiceStatus::tryFrom((string) $claim->invoice()->status)) {
                InvoiceStatus::Draft => $drafts[] = $claim,
                InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid => $charged[] = $claim,
                InvoiceStatus::Void => $voids[] = $claim,
                null => throw new LogicException('An exact claim with an unrecognised status should have been refused.'),
            };
        }

        if ($drafts === [] && count($charged) < 2) {
            // One charged invoice, or voids only. Whichever survives is the
            // answer; nothing here is a second claim on the client's money.
            $survivor = $charged[0] ?? $voids[0] ?? null;
            if ($survivor instanceof PeriodClaim) {
                return $survivor;
            }

            return PeriodClaim::clear();
        }

        if ($charged === [] && $voids === [] && count($drafts) === 1) {
            return $drafts[0];
        }

        return PeriodClaim::refused(
            $this->conflictMessage($drafts, $charged, $voids, $start, $end),
            PeriodRefusalReason::ConflictingExactClaims,
        );
    }

    /**
     * Name every invoice claiming the period, and say which way out is safe.
     *
     * The advice depends on the shape - see {@see settle()} - and each
     * sentence names only a repair the lifecycle will actually perform and
     * that actually releases the period. A conflict that needs two steps says
     * so, and the state after the first step produces the message for the
     * second.
     *
     * @param  list<PeriodClaim>  $drafts
     * @param  list<PeriodClaim>  $charged
     * @param  list<PeriodClaim>  $voids
     */
    private function conflictMessage(
        array $drafts,
        array $charged,
        array $voids,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): string {
        $sentences = [sprintf(
            'Invoices %s each cover exactly %s to %s, the period being billed now, so which of them bills it cannot '
            .'be decided here. The period is not billed again and the schedule is not advanced past it.',
            $this->numbersWithStatus([...$charged, ...$drafts, ...$voids]),
            $start->toDateString(),
            $end->toDateString(),
        )];

        if (count($charged) > 1) {
            // `void()` refuses a paid row and one with any `paid_amount`, so
            // the rows it would accept are named and, when there are none, the
            // message says what is actually needed instead.
            $voidable = array_values(array_filter(
                $charged,
                static fn (PeriodClaim $claim): bool => InvoiceStatus::tryFrom((string) $claim->invoice()->status) === InvoiceStatus::Issued
                    && (int) $claim->invoice()->paid_amount === 0,
            ));

            $sentences[] = $voidable === []
                ? sprintf(
                    'Invoices %s have each billed this client for it, and money has been taken against every one of '
                    .'them, so none can be voided here. This needs a financial correction outside the schedule before '
                    .'it can advance; until then it halts on every run.',
                    $this->numbers($charged),
                )
                : sprintf(
                    'Invoices %s have each billed this client for it. Void the duplicate that has not been paid - %s - '
                    .'and the invoice that remains is read as having billed the period.',
                    $this->numbers($charged),
                    $this->numbers($voidable),
                );
        }

        if ($drafts !== [] && $charged !== []) {
            $sentences[] = sprintf(
                'Invoice %s has already billed this client for it, so discard %s %s rather than issuing %s: issuing '
                .'performs no overlap check and would charge this client twice. Once discarded, the period reads as '
                .'billed by the invoice that charged for it.',
                $this->numbers(array_slice($charged, 0, 1)),
                count($drafts) === 1 ? 'draft' : 'drafts',
                $this->numbers($drafts),
                count($drafts) === 1 ? 'it' : 'them',
            );
        }

        if ($drafts !== [] && $charged === []) {
            $sentences[] = count($drafts) > 1
                ? sprintf(
                    'Nothing has billed this client for it yet, and drafts %s each propose to. Discard all but one of '
                    .'them, then issue the one that remains to bill the period, or discard it too to waive the period.',
                    $this->numbers($drafts),
                )
                : sprintf(
                    'Nothing has billed this client for it: void %s waived it, and draft %s proposes to bill it after '
                    .'all. Whether the waiver or the draft is intended cannot be read from the rows. Discard the draft '
                    .'to keep the waiver, or issue it to bill the period.',
                    $this->numbers($voids),
                    $this->numbers($drafts),
                );
        }

        return implode(' ', $sentences);
    }

    /**
     * @param  list<PeriodClaim>  $claims
     */
    private function numbers(array $claims): string
    {
        return implode(', ', array_map(
            static fn (PeriodClaim $claim): string => (string) $claim->invoice()->invoice_number,
            $claims,
        ));
    }

    /**
     * @param  list<PeriodClaim>  $claims
     */
    private function numbersWithStatus(array $claims): string
    {
        return implode(', ', array_map(
            static fn (PeriodClaim $claim): string => sprintf(
                '%s (%s)',
                $claim->invoice()->invoice_number,
                (string) $claim->invoice()->status,
            ),
            $claims,
        ));
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
            return PeriodClaim::refused(
                $this->danglingMessage($candidate, 'a billing schedule', $start, $end),
                PeriodRefusalReason::DanglingSchedule,
            );
        }
        if ($agreementId !== null && ! $agreements->has($agreementId)) {
            return PeriodClaim::refused(
                $this->danglingMessage($candidate, 'an agreement', $start, $end),
                PeriodRefusalReason::DanglingAgreement,
            );
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
            ), PeriodRefusalReason::ContradictoryLineage);
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
            ), PeriodRefusalReason::Unattributed);
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
            ), PeriodRefusalReason::UnknownStatus);
        }

        if ($coversExactly) {
            // A draft has reserved this period but charged nobody for it, and
            // those are different facts. Reporting it as billed advanced
            // `next_run_on` past a period no money had been asked for, and
            // nothing brought it back: `discardDraft()` turns the draft into a
            // *void* invoice keeping its period, so even rewinding the cursor
            // met an exact void and read it as a deliberate waiver. Creating a
            // second invoice would be the #219 defect, so the schedule does
            // neither and says which draft is in the way.
            if ($status === InvoiceStatus::Draft) {
                return PeriodClaim::pendingDraft($candidate);
            }

            // Any other known status, void included. Issued, partially paid and
            // paid have each asked the client for money; voiding a cadence
            // invoice is the documented way to waive its own period, and
            // regenerating one would write the same
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
            ), PeriodRefusalReason::IncompletePeriod);
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
        ), PeriodRefusalReason::PartialOverlap);
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
