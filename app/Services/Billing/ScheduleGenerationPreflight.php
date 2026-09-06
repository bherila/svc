<?php

namespace App\Services\Billing;

use App\Models\ClientBillingSchedule;
use App\Models\Workspace;
use App\Support\Billing\BillingPeriod;
use App\Support\Billing\PeriodClaimVerdict;
use App\Support\Billing\PeriodRefusalReason;
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
 * ## What it still cannot promise
 *
 * It takes no locks and writes nothing, so it is a prediction about a database
 * that can change underneath it. Between this and the run: an invoice can be
 * issued, voided or re-dated, a draft can appear, `next_run_on` can move. A
 * clean preflight is evidence that the *data* is in order now, not a guarantee
 * about a future run.
 *
 * It is also scoped to periods that are due by `$through`. A schedule with
 * nothing due is reported as not due rather than as clean, because no period
 * of it was examined.
 */
final class ScheduleGenerationPreflight
{
    /**
     * Periods examined per schedule before giving up on it.
     *
     * A schedule whose `next_run_on` sits years in the past has hundreds of
     * periods due, and each is a query. `generateDue()` would work through all
     * of them, so the cap is not a claim that it would not - it is a promise
     * that the audit cannot itself become the incident. A truncated schedule is
     * counted and reported rather than silently treated as clean.
     */
    private const PERIOD_CAP = 240;

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
     */
    public function run(?Workspace $workspace = null, ?CarbonImmutable $through = null): ScheduleGenerationPreflightReport
    {
        $through ??= $this->clock->today($workspace);

        $refusals = array_fill_keys(
            array_map(static fn (PeriodRefusalReason $reason): string => $reason->value, PeriodRefusalReason::cases()),
            0,
        );
        $schedules = 0;
        $due = 0;
        $periods = 0;
        $refused = 0;
        $pending = 0;
        $truncated = 0;

        // Chunked, because this walks every schedule in the database and holds
        // only counts. Inactive ones are excluded here rather than classified
        // and discarded: `generateDue()` returns immediately for an inactive
        // schedule without consulting the resolver at all, so an invoice that
        // would halt one halts nothing.
        $this->schedules($workspace)->chunkById(200, function (Collection $chunk) use (
            $through, &$refusals, &$schedules, &$due, &$periods, &$refused, &$pending, &$truncated
        ): void {
            foreach ($chunk as $schedule) {
                $schedules++;
                $outcome = $this->classifyDuePeriods($schedule, $through);

                $periods += $outcome['periods'];
                if ($outcome['periods'] > 0) {
                    $due++;
                }
                if ($outcome['truncated']) {
                    $truncated++;
                }

                if ($outcome['reason'] instanceof PeriodRefusalReason) {
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
            wouldHalt: $refused + $pending,
            haltedByARefusal: $refused,
            haltedByAPendingDraft: $pending,
            schedulesTruncated: $truncated,
            refusalsByReason: $refusals,
        );
    }

    /**
     * Walk one schedule's due periods until something stops it.
     *
     * The loop is `generateDue()`'s, minus the writing: same starting cursor,
     * same period arithmetic, same inclusive `<= $through`, same stop at the
     * first period that cannot be decided. Only the outcome differs - it
     * records what happened instead of throwing.
     *
     * An unreadable cadence is reported as a refusal rather than crashing the
     * audit. `BillingPeriod` throws on one because billing a guessed span is
     * worse than not billing, and the same schedule would throw out of
     * `generateDue()` for the same reason - so it does halt, and the honest
     * place to say so is the count.
     *
     * @return array{periods: int, reason: ?PeriodRefusalReason, pending: bool, truncated: bool}
     */
    private function classifyDuePeriods(ClientBillingSchedule $schedule, CarbonImmutable $through): array
    {
        $cursor = CarbonImmutable::parse((string) $schedule->next_run_on);
        $periods = 0;

        while ($cursor->lte($through)) {
            if ($periods >= self::PERIOD_CAP) {
                return ['periods' => $periods, 'reason' => null, 'pending' => false, 'truncated' => true];
            }

            try {
                $period = BillingPeriod::beginningAt($cursor, (string) $schedule->cadence);
            } catch (DomainException) {
                return [
                    'periods' => $periods,
                    'reason' => PeriodRefusalReason::UnreadableCadence,
                    'pending' => false,
                    'truncated' => false,
                ];
            }

            $periods++;
            $claim = $this->collisions->resolve($schedule, $period->start, $period->end);

            if ($claim->verdict === PeriodClaimVerdict::Refused) {
                return ['periods' => $periods, 'reason' => $claim->reason(), 'pending' => false, 'truncated' => false];
            }
            if ($claim->verdict === PeriodClaimVerdict::PendingDraft) {
                return ['periods' => $periods, 'reason' => null, 'pending' => true, 'truncated' => false];
            }

            // `Clear` and `AlreadyBilled` both let the run move on. The
            // difference between them is whether an invoice gets written, which
            // is precisely what a preflight does not do.
            $cursor = $period->next;
        }

        return ['periods' => $periods, 'reason' => null, 'pending' => false, 'truncated' => false];
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
