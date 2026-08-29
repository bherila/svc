<?php

namespace App\Support\Engagement;

use Carbon\CarbonImmutable;

/**
 * The span of time the operator time sheet shows.
 *
 * One definition, because it is asked in two places that must agree: the
 * sheet decides what to display with it, and the write validators decide what
 * to accept with it. Stated twice they drift, and the drift is silent - a
 * date the validator allows and the sheet will not show is saved and then
 * invisible on the only screen that offers correction or deletion.
 *
 * Read on the workspace's own clock. The server's month rolls over before a
 * workspace west of UTC has finished the old one, and the browser dates new
 * work locally.
 */
final class TimeSheetWindow
{
    /** Months of history the sheet offers, the current one included. */
    public const MONTHS = 12;

    public static function start(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::now($timezone)->startOfMonth()->subMonths(self::MONTHS - 1);
    }

    /** Today on the workspace's calendar, for defaulting a date field. */
    public static function today(string $timezone): string
    {
        return CarbonImmutable::now($timezone)->toDateString();
    }

    public static function end(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::now($timezone)->endOfMonth();
    }
}
