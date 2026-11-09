<?php

namespace App\Support\Engagement;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The end dates a cancellation may choose, as {@see CancellationDates} works
 * them out for one agreement on one day.
 *
 * Every date is a calendar day at midnight UTC: read it with `toDateString()`.
 */
final readonly class CancellationEndDates
{
    /**
     * @param  CarbonImmutable  $earliest  The close of the billing period in which the
     *                                     notice runs out, and never before the minimum
     *                                     term's last period closes.
     * @param  list<CarbonImmutable>  $offered  The earliest date and the period closes after
     *                                          it, ascending, each before the agreement's
     *                                          current end. Empty when the agreement already
     *                                          ends on or before the earliest date.
     * @param  CarbonImmutable  $noticeRunsOutOn  Today plus the notice period.
     * @param  CarbonImmutable|null  $minimumTermEndsOn  The minimum term's last day, or null when the
     *                                                   agreement has none.
     */
    public function __construct(
        public CarbonImmutable $earliest,
        public array $offered,
        public CarbonImmutable $noticeRunsOutOn,
        public ?CarbonImmutable $minimumTermEndsOn,
    ) {}

    /** Whether `$date`'s calendar day is one of the offered end dates. */
    public function offers(CarbonInterface $date): bool
    {
        return isset(array_flip($this->offeredDateStrings())[$date->toDateString()]);
    }

    /** @return list<string> */
    public function offeredDateStrings(): array
    {
        return array_map(static fn (CarbonImmutable $date): string => $date->toDateString(), $this->offered);
    }
}
