<?php

namespace App\Support\Packages\Migration;

use App\Services\Billing\BillingCycleResolver;
use App\Support\Billing\BillingCadence;
use App\Support\Engagement\CancellationCycleAnchor;
use App\Support\Engagement\CancellationDates;
use App\Support\WorkspaceClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The dates a move to a new version may take effect for one agreement
 * (docs/service-packages.md, MIG-1).
 *
 * A move takes effect at the start of one of the agreement's billing periods,
 * and never before the client could have left instead: the earliest is the
 * day after the earliest end a cancellation made today could choose
 * ({@see CancellationDates}: the close of the period in which today plus the
 * agreement's notice falls). So a client told today can always end the
 * service on the day before the new terms start. The minimum term does not
 * hold a move back - MIG-1 names the notice period alone - and a move never
 * restarts one (the successor keeps what is left of it).
 *
 * Nor does a move take effect inside a period an issued invoice has already
 * sold: the new terms start after the last period invoiced, so nothing is
 * billed twice and no issued invoice is rewritten. Every later period start
 * is offered up to `$count`, each on or before the agreement's own end.
 *
 * Periods are the billing engine's own ({@see BillingCycleResolver}), as for
 * cancellation. Every argument is read as the calendar day it names; pass
 * today from {@see WorkspaceClock::today()}. Pure: no database, no clock.
 */
final class MigrationDates
{
    /** How many period starts are offered, the earliest included. */
    public const int DEFAULT_OFFERED = 6;

    public function __construct(private readonly BillingCycleResolver $cycles = new BillingCycleResolver) {}

    /**
     * @param  CarbonInterface|null  $endsOn  the agreement's `ends_on`, if any; no date after it is offered
     * @param  CarbonInterface|null  $invoicedThrough  the last day of the last period an issued invoice sold, if any
     *
     * @throws InvalidArgumentException on a negative notice period or a count below one
     */
    public function offer(
        BillingCadence $cadence,
        CarbonInterface $startsOn,
        int $noticeDays,
        CarbonInterface $today,
        ?CarbonInterface $endsOn = null,
        ?CarbonInterface $invoicedThrough = null,
        int $count = self::DEFAULT_OFFERED,
    ): MigrationEffectiveDates {
        if ($noticeDays < 0) {
            throw new InvalidArgumentException('The notice period cannot be negative.');
        }
        if ($count < 1) {
            throw new InvalidArgumentException('At least one effective date must be offered.');
        }

        $anchor = new CancellationCycleAnchor($cadence, self::day($startsOn));
        $floor = self::day($today)->addDays($noticeDays)->max($anchor->startsOn);
        if ($invoicedThrough !== null) {
            $floor = $floor->max(self::day($invoicedThrough));
        }
        // The last old period closes where the earliest cancellation could
        // end; the new terms start the day after.
        $earliest = $this->close($anchor, $floor)->addDay();

        $ceiling = $endsOn === null ? null : self::day($endsOn)->toDateString();

        return new MigrationEffectiveDates($earliest, $this->starts($anchor, $earliest, $ceiling, $count));
    }

    /**
     * Up to `$count` period starts from `$earliest`, none after `$ceiling`.
     *
     * @return list<CarbonImmutable>
     */
    private function starts(CancellationCycleAnchor $anchor, CarbonImmutable $earliest, ?string $ceiling, int $count): array
    {
        $offered = [];
        for ($start = $earliest; count($offered) < $count; $start = $this->close($anchor, $start)->addDay()) {
            if ($ceiling !== null && $start->toDateString() > $ceiling) {
                return $offered;
            }
            $offered[] = $start;
        }

        return $offered;
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
