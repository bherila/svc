<?php

namespace App\Support\Billing;

use App\Services\Billing\BillingScheduleService;
use App\Services\Billing\ScheduleGenerationPreflight;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * The span a schedule bills for one run, and where the next one starts.
 *
 * Extracted from {@see BillingScheduleService} so that
 * {@see ScheduleGenerationPreflight} asks about the same periods the schedule
 * would actually bill. A preflight that computed its own dates would be a
 * second implementation of the cadence arithmetic, and a preflight that
 * disagrees with the run it is predicting is worse than no preflight - it
 * reports on periods nobody will bill and stays silent about the ones somebody
 * will.
 *
 * ## Adjacency is the property everything rests on
 *
 * The end is the day before the next start, so consecutive periods touch and
 * never overlap. That matters more than it looks: since #219/#224 the duplicate
 * guard refuses on an *overlap* rather than ignoring anything that is not an
 * exact match, so a period that overlapped its own predecessor by one day would
 * no longer be a silent double-charge - it would be a schedule that halts on
 * its second run and never recovers.
 *
 * `addMonthsNoOverflow()` rather than `addMonths()`, so that a schedule anchored
 * on the 31st lands on the last day of a short month instead of skidding into
 * the next one. Pinned across every cadence and every awkward start by
 * `BillingWorkflowTest::test_consecutive_periods_are_adjacent_for_every_cadence_and_awkward_start`.
 */
final readonly class BillingPeriod
{
    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public CarbonImmutable $next,
    ) {}

    /**
     * The period beginning at `$start` for a schedule on `$cadence`.
     *
     * @throws DomainException on a cadence this application does not know. Fail
     *                         closed: guessing a month count would bill a span
     *                         nobody asked for.
     */
    public static function beginningAt(CarbonImmutable $start, string $cadence): self
    {
        $months = BillingCadence::tryFrom($cadence)?->monthsInCycle()
            ?? throw new DomainException('Unsupported billing cadence.');

        $next = $start->addMonthsNoOverflow($months);

        return new self($start, $next->subDay(), $next);
    }
}
