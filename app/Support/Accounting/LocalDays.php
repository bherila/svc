<?php

declare(strict_types=1);

namespace App\Support\Accounting;

use Carbon\CarbonImmutable;

/**
 * A window of calendar days in a workspace's timezone, as the UTC instants a
 * timestamp column is compared with: from the first instant of the first day
 * up to, and excluding, the first instant of the day after the last.
 */
final class LocalDays
{
    /**
     * @return array{string|null, string|null} [inclusive start, exclusive end] as `Y-m-d H:i:s` in UTC; null for an open side
     */
    public static function instants(?string $from, ?string $to, string $timezone): array
    {
        return [
            $from === null ? null : CarbonImmutable::parse($from, $timezone)->startOfDay()->utc()->format('Y-m-d H:i:s'),
            $to === null ? null : CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s'),
        ];
    }
}
