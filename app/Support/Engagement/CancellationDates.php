<?php

namespace App\Support\Engagement;

use App\Services\Billing\BillingCycleResolver;
use App\Support\Billing\BillingCadence;
use App\Support\WorkspaceClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The end dates a cancellation may choose (docs/service-packages.md, CAN-2).
 *
 * The earliest end is the close of the billing period in which today plus the
 * notice period falls, and never earlier than the close of the period holding
 * the minimum term's last day. Later period closes are offered after it. An
 * end therefore always falls on a period close, so a paid period is never cut
 * short and termination leaves no partial final period.
 *
 * ## Whose periods
 *
 * The billing engine's. Every close comes from
 * {@see BillingCycleResolver::cycleContaining()}, the same call cadence
 * generation, the period guard and the portal's next-invoice estimate make:
 * calendar months for a monthly agreement, and blocks of the cadence's months
 * anchored on `starts_on` for every other cadence. A second notion of a period
 * here would offer a client an end the engine then bills past.
 *
 * ## Days, not instants
 *
 * Every argument is read as the calendar day it names in its own timezone, and
 * every result is that day at midnight UTC. Pass today from
 * {@see WorkspaceClock::today()} for the workspace: the server's day rolls over
 * before a workspace west of UTC has finished its own, and a notice period
 * counted from the server's date would start a day early there.
 *
 * Pure: no database, no clock, no configuration.
 */
final class CancellationDates
{
    /** How many period closes are offered, the earliest included. */
    public const int DEFAULT_OFFERED = 3;

    public function __construct(private readonly BillingCycleResolver $cycles = new BillingCycleResolver) {}

    /**
     * @param  BillingCadence  $cadence  The agreement's effective billing cadence.
     * @param  CarbonInterface  $startsOn  The agreement's `starts_on`.
     * @param  int  $noticeDays  The notice period in days; 0 for none.
     * @param  int|null  $minimumTermMonths  The minimum term in months from `starts_on`; null or 0 for none.
     * @param  CarbonInterface  $today  Today on the workspace's clock.
     * @param  CarbonInterface|null  $currentEndsOn  The end the agreement has without this
     *                                               cancellation (its `ends_on`, or the end
     *                                               a scheduled termination replaced). No
     *                                               date on or after it is offered.
     * @param  int  $count  How many period closes to offer at most.
     *
     * @throws InvalidArgumentException on a negative notice period or minimum term, or a count below one.
     */
    public function offer(
        BillingCadence $cadence,
        CarbonInterface $startsOn,
        int $noticeDays,
        ?int $minimumTermMonths,
        CarbonInterface $today,
        ?CarbonInterface $currentEndsOn = null,
        int $count = self::DEFAULT_OFFERED,
    ): CancellationEndDates {
        if ($noticeDays < 0) {
            throw new InvalidArgumentException('The notice period cannot be negative.');
        }
        $termMonths = $minimumTermMonths ?? 0;
        if ($termMonths < 0) {
            throw new InvalidArgumentException('The minimum term cannot be negative.');
        }
        if ($count < 1) {
            throw new InvalidArgumentException('At least one end date must be offered.');
        }

        $anchor = new CancellationCycleAnchor($cadence, self::day($startsOn));
        $noticeRunsOutOn = self::day($today)->addDays($noticeDays);
        // The term's last day: a twelve-month term from Jan 1 ends on Dec 31,
        // and one from Jan 31 ends on the day before Feb 28 rather than
        // skidding into March.
        $termEndsOn = $anchor->startsOn->addMonthsNoOverflow($termMonths)->subDay();

        // Closes only move forward as the day does, so the close of the
        // latest constraint is the latest of their closes. A period that has
        // not begun yet has no close before `starts_on`.
        $earliest = $this->close($anchor, $noticeRunsOutOn->max($termEndsOn)->max($anchor->startsOn));

        $ceiling = $currentEndsOn === null ? null : self::day($currentEndsOn)->toDateString();
        $offered = [];
        for ($close = $earliest; count($offered) < $count && ($ceiling === null || $close->toDateString() < $ceiling); $close = $this->close($anchor, $close->addDay())) {
            $offered[] = $close;
        }

        return new CancellationEndDates(
            earliest: $earliest,
            offered: $offered,
            noticeRunsOutOn: $noticeRunsOutOn,
            minimumTermEndsOn: $termMonths > 0 ? $termEndsOn : null,
        );
    }

    /** The last day of the engine's billing period that contains `$day`. */
    private function close(CancellationCycleAnchor $anchor, CarbonImmutable $day): CarbonImmutable
    {
        return self::day($this->cycles->cycleContaining($anchor, Carbon::instance($day))->end);
    }

    private static function day(CarbonInterface $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value->toDateString(), 'UTC');
    }
}
