<?php

namespace App\Services\Billing;

use App\Models\ClientBillingSchedule;
use App\Models\Workspace;
use App\Support\Billing\BillingPeriod;
use App\Support\Billing\BillingScheduleLineTemplate;
use App\Support\Billing\PeriodClaimVerdict;
use App\Support\Billing\PeriodRefusalReason;
use App\Support\Billing\ScheduleDefect;
use App\Support\Billing\ScheduleGenerationPreflightReport;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ask every due schedule what it would do, without letting it do it.
 *
 * {@see BillingPeriodCollisionResolver} refuses rather than guessing whenever
 * it cannot establish who owns an overlapping invoice, and stops rather than
 * advancing when a draft has claimed a period without billing it. Both are
 * right - the alternatives are charging a client twice, or silently skipping a
 * period nobody billed - but both are *halts*, and not every halt clears
 * itself: a refusal naming a paid invoice stops that schedule on every run
 * until someone makes a financial correction, because
 * `InvoiceLifecycleService::void()` throws once `paid_amount > 0` and
 * `updateDraft()` rewrites no period or lineage column at any status.
 *
 * So the blast radius has to be measurable before the guards are deployed,
 * rather than discovered one failed cron run at a time.
 *
 * ## It runs the real classifier over the real periods
 *
 * The first version of this did not. It classified *rows* with SQL that
 * re-derived the resolver's decisions, and it was wrong in both directions -
 * it cleared rows that halt production and flagged rows no schedule would
 * reject. The cause was structural rather than a set of bugs: half the
 * resolver's rules are about a `(schedule, period, invoice)` triple, and no
 * amount of care makes a row-at-a-time query answer a question that has a
 * period in it. "Does this invoice cover the period exactly" has no answer
 * until you name the period.
 *
 * So this enumerates what `generateDue()` would enumerate - every period from
 * each active schedule's `next_run_on` through `$through`, cut by the same
 * {@see BillingPeriod} arithmetic - and calls the same resolver on each. There
 * is no second implementation to drift, and partial overlaps, exact-versus-
 * inexact voids and implicit sole ownership are all covered because the code
 * deciding them is the code that will decide them for real.
 *
 * It stops at a schedule's first undecidable period, exactly as `generateDue()`
 * does, so a halted schedule contributes one reason rather than a pile of them.
 *
 * ## What it does and does not rehearse
 *
 * It rehearses everything `generateDue()` reads before it writes: the schedule
 * is active, its line template normalises, its cadence cuts a period, and the
 * resolver's answer for each due period. Those are the halts this exists to
 * size.
 *
 * It rehearses nothing that happens while writing. `createDraft()` and
 * `issue()` compose lines, price them, move time entries and take locks, and a
 * failure inside any of them is outside this prediction - a clean report says
 * no schedule halts *on the state of the data*, not that a run will succeed.
 * Making that claim would mean performing the run.
 *
 * It also takes no locks and writes nothing, so it is a prediction about a
 * database that can change underneath it. Between this and the run: an invoice
 * can be issued, voided or re-dated, a draft can appear, `next_run_on` can
 * move.
 *
 * And it looks only forward. Every question here starts at a schedule's
 * *current* `next_run_on`, so a period the cursor has already passed is
 * invisible to it however it got passed. Historical exposure must therefore
 * be settled from execution evidence rather than this preflight. For the #250
 * incident, production verification found that no schedule id was ever
 * allocated and no schedule-generation request occurred during the exposure
 * window, so #254 closed with no affected population.
 */
final class ScheduleGenerationPreflight
{
    /**
     * Periods examined per schedule before the answer for it becomes "I do not
     * know".
     *
     * A schedule whose `next_run_on` sits years in the past has hundreds of
     * periods due, and each is a query. `generateDue()` would work through all
     * of them, so the cap is not a claim that it would not - it is a promise
     * that the audit cannot itself become the incident.
     *
     * A schedule that hits it is reported as *truncated*, and a report with any
     * truncation is inconclusive rather than clean: its unexamined periods are
     * precisely the ones nobody has looked at. Raise `$periodCap` to finish the
     * job on a run that is allowed to take longer.
     */
    public const PERIOD_CAP = 240;

    public function __construct(
        private readonly BillingPeriodCollisionResolver $collisions,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * Classify every period due by `$through`, optionally within one workspace.
     *
     * Passing no workspace covers every one, which is the operator reading and
     * the one a deployment wants. Any caller rendering to a tenant must pass
     * that tenant's workspace; nothing else here scopes for them.
     *
     * `$through` defaults to today in the given workspace's timezone, or the
     * application's when the audit is unscoped - the same boundary every other
     * read of "now" in domain code goes through. An unscoped run therefore
     * asks one question of every tenant, which is the right shape for an
     * operator sizing a deployment and the wrong one for a tenant-facing
     * screen; such a screen passes its own workspace and gets its own day.
     *
     * @param  int|null  $periodCap  periods per schedule before it is reported truncated; defaults to {@see self::PERIOD_CAP}
     */
    public function run(
        ?Workspace $workspace = null,
        ?CarbonImmutable $through = null,
        ?int $periodCap = null,
    ): ScheduleGenerationPreflightReport {
        $through ??= $this->clock->today($workspace);
        $cap = $periodCap ?? self::PERIOD_CAP;

        $refusals = array_fill_keys(
            array_map(static fn (PeriodRefusalReason $reason): string => $reason->value, PeriodRefusalReason::cases()),
            0,
        );
        $defects = array_fill_keys(
            array_map(static fn (ScheduleDefect $defect): string => $defect->value, ScheduleDefect::cases()),
            0,
        );
        $schedules = 0;
        $due = 0;
        $periods = 0;
        $refused = 0;
        $pending = 0;
        $defective = 0;
        $truncated = 0;

        // Chunked, because this walks every schedule in the database and holds
        // only counts. Inactive ones are excluded here rather than classified
        // and discarded: `generateDue()` returns immediately for an inactive
        // schedule without consulting the resolver at all, so an invoice that
        // would halt one halts nothing.
        $this->schedules($workspace)->chunkById(200, function (Collection $chunk) use (
            $through, $cap, &$refusals, &$defects, &$schedules, &$due, &$periods, &$refused, &$pending, &$defective, &$truncated
        ): void {
            foreach ($chunk as $schedule) {
                $schedules++;
                $outcome = $this->classifySchedule($schedule, $through, $cap);

                $periods += $outcome['periods'];
                if ($outcome['due']) {
                    $due++;
                }
                if ($outcome['truncated']) {
                    $truncated++;
                }

                if ($outcome['defect'] instanceof ScheduleDefect) {
                    $defective++;
                    $defects[$outcome['defect']->value]++;
                } elseif ($outcome['reason'] instanceof PeriodRefusalReason) {
                    $refused++;
                    $refusals[$outcome['reason']->value]++;
                } elseif ($outcome['pending']) {
                    $pending++;
                }
            }
        });

        return new ScheduleGenerationPreflightReport(
            schedules: $schedules,
            schedulesDue: $due,
            periodsClassified: $periods,
            wouldHalt: $refused + $pending + $defective,
            haltedByARefusal: $refused,
            haltedByAPendingDraft: $pending,
            haltedByAScheduleDefect: $defective,
            schedulesTruncated: $truncated,
            refusalsByReason: $refusals,
            defectsByKind: $defects,
        );
    }

    /**
     * Walk one schedule until something stops it, in `generateDue()`'s order.
     *
     * Same order deliberately: template first, then a loop over periods from
     * the same starting cursor with the same period arithmetic and the same
     * inclusive `<= $through`, stopping at the first period that cannot be
     * decided. Only the outcome differs - it records what happened instead of
     * throwing.
     *
     * `due` is answered before the cadence is parsed, because that is when it
     * becomes known: `$cursor <= $through` is the whole question, and a
     * schedule with an unreadable cadence is a due schedule that halts rather
     * than a schedule with nothing to do. Reporting it as not-due while
     * counting it as a halt was an arithmetic contradiction in the output.
     *
     * @return array{due: bool, periods: int, reason: ?PeriodRefusalReason, defect: ?ScheduleDefect, pending: bool, truncated: bool}
     */
    private function classifySchedule(ClientBillingSchedule $schedule, CarbonImmutable $through, int $cap): array
    {
        $cursor = CarbonImmutable::parse((string) $schedule->next_run_on);
        $due = $cursor->lte($through);

        // Before the loop, exactly where `generateDue()` normalises it - so a
        // malformed template halts a schedule whether or not it has a period
        // due, and this reports it on the same terms. The same normaliser, not
        // a predicate re-stating its conditions: the predicate this replaced
        // agreed with the service on the two conditions it knew and, like the
        // service, accepted an empty template - which then priced to an issued
        // invoice for nothing. One reader cannot drift from itself.
        try {
            BillingScheduleLineTemplate::normalize($schedule->getAttribute('line_template'));
        } catch (DomainException) {
            return $this->outcome($due, 0, defect: ScheduleDefect::UnreadableLineTemplate);
        }

        $periods = 0;

        while ($cursor->lte($through)) {
            if ($periods >= $cap) {
                return $this->outcome($due, $periods, truncated: true);
            }

            try {
                $period = BillingPeriod::beginningAt($cursor, (string) $schedule->cadence);
            } catch (DomainException) {
                return $this->outcome($due, $periods, defect: ScheduleDefect::UnreadableCadence);
            }

            $periods++;
            $claim = $this->collisions->resolve($schedule, $period->start, $period->end);

            // Exhaustive, with no `default`, for the same reason
            // `generateDue()`'s is. An if-chain here tested for the two verdicts
            // that halt and advanced the cursor for anything else, so a fifth
            // verdict added to `PeriodClaimVerdict` would have been reported as
            // clean by the preflight whatever the run was going to do with it -
            // the shape of defect this class exists to rule out, moved one
            // layer downstream. `Clear` and `AlreadyBilled` are the only two
            // that let the run move on; the difference between them is whether
            // an invoice gets written, which is precisely what a preflight does
            // not do.
            $halt = match ($claim->verdict) {
                PeriodClaimVerdict::Refused => $this->outcome($due, $periods, reason: $claim->reason()),
                PeriodClaimVerdict::PendingDraft => $this->outcome($due, $periods, pending: true),
                PeriodClaimVerdict::AlreadyBilled, PeriodClaimVerdict::Clear => null,
            };

            if ($halt !== null) {
                return $halt;
            }

            $cursor = $period->next;
        }

        return $this->outcome($due, $periods);
    }

    /**
     * @return array{due: bool, periods: int, reason: ?PeriodRefusalReason, defect: ?ScheduleDefect, pending: bool, truncated: bool}
     */
    private function outcome(
        bool $due,
        int $periods,
        ?PeriodRefusalReason $reason = null,
        ?ScheduleDefect $defect = null,
        bool $pending = false,
        bool $truncated = false,
    ): array {
        return [
            'due' => $due,
            'periods' => $periods,
            'reason' => $reason,
            'defect' => $defect,
            'pending' => $pending,
            'truncated' => $truncated,
        ];
    }

    /**
     * The schedules a run would actually touch.
     *
     * @return Builder<ClientBillingSchedule>
     */
    private function schedules(?Workspace $workspace): Builder
    {
        $schedules = ClientBillingSchedule::query()->where('is_active', true);

        return $workspace === null
            ? $schedules
            : $schedules->where('workspace_id', $workspace->id);
    }
}
