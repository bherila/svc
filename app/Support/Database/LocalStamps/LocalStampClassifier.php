<?php

namespace App\Support\Database\LocalStamps;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Decides, for one stored value, whether code before #712 wrote it in the
 * workspace's wall clock, and if so which UTC instant it meant.
 *
 * Database-free: it sees the stored string, the row's companion stamp and
 * the column's {@see LocalStampSpec}, and nothing else.
 *
 * In order:
 *
 * 1. A value at or after #712's release can only be UTC.
 * 2. The companion decides when it was written in the same statement: within
 *    a minute of the value read in the zone, local; within a minute of the
 *    value read as UTC, already UTC. The zone's offset is hours, so the two
 *    cannot both hold, and a wall time the autumn change repeats is resolved
 *    by whichever reading matches.
 * 3. A value earlier than any local write could be is UTC from before the
 *    switch.
 * 4. A value in a column only local writers wrote, inside the era and outside
 *    the band around the switch, is local.
 * 5. Anything else is ambiguous: left as stored and reported.
 */
final class LocalStampClassifier
{
    /** The earliest #712's release could have written a UTC value (its deploy step began). */
    public const NEW_RELEASE_FROM = '2026-11-13 22:28:14';

    /** The latest the old release could have written a local one (its deploy step ended). */
    public const OLD_RELEASE_UNTIL = '2026-11-13 22:28:54';

    /** How far apart two stamps written by one statement can be. */
    public const SAME_STATEMENT_SECONDS = 60;

    /** The earliest switch to the workspace clock (#127's merge). */
    private const ERA_FLOOR = '2026-08-30 06:29:02';

    /** @var array{int, int}|null */
    private ?array $offsets = null;

    public function __construct(private readonly DateTimeZone $zone) {}

    /** Whether the zone is ever off UTC while the wall clock was being stored. */
    public function isOffUtc(): bool
    {
        [$min, $max] = $this->offsetRange();

        return $min !== 0 || $max !== 0;
    }

    public function classify(LocalStampSpec $spec, string $stored, ?string $companion): LocalStampVerdict
    {
        $value = CarbonImmutable::parse($stored, 'UTC');
        $shift = $spec->companionShiftDays;
        // When it was written, read as UTC: the era's edges are about writes.
        $writtenIfUtc = $value->subDays($shift);
        [$minOffset, $maxOffset] = $this->offsetRange();

        if ($writtenIfUtc->gte(CarbonImmutable::parse(self::NEW_RELEASE_FROM, 'UTC')->addSeconds(max(0, $maxOffset)))) {
            return LocalStampVerdict::outside();
        }

        $readings = $this->wallReadings($value->format('Y-m-d H:i:s'));
        $writtenIfLocal = fn (CarbonImmutable $reading): CarbonImmutable => $reading->setTimezone($this->zone)->subDays($shift)->utc();
        if ($companion !== null) {
            $written = CarbonImmutable::parse($companion, 'UTC');
            foreach ($readings as $reading) {
                if ($this->sameStatement($written, $writtenIfLocal($reading))) {
                    return LocalStampVerdict::corrected($reading);
                }
            }
            if ($this->sameStatement($written, $writtenIfUtc)) {
                return LocalStampVerdict::alreadyUtc();
            }
        }

        $utcUntil = $spec->utcUntil();
        if ($spec->localSince !== null && $writtenIfUtc->lt($spec->localSince->addSeconds($minOffset))) {
            return LocalStampVerdict::outside();
        }

        // Not UTC: a UTC writer before the switch stopped by `utcUntil`, and
        // #712's release began at NEW_RELEASE_FROM. Local: the moment it was
        // written fell while the wall clock was being stored.
        $notUtc = ($utcUntil === null || $writtenIfUtc->gte($utcUntil))
            && $writtenIfUtc->lt(CarbonImmutable::parse(self::NEW_RELEASE_FROM, 'UTC'));
        $meant = count($readings) === 1 ? $readings[0] : null;
        $local = $meant !== null
            && ($spec->localSince === null || $writtenIfLocal($meant)->gte($spec->localSince))
            && $writtenIfLocal($meant)->lte(CarbonImmutable::parse(self::OLD_RELEASE_UNTIL, 'UTC'));
        if ($spec->exclusive && $notUtc && $local) {
            return LocalStampVerdict::corrected($meant);
        }

        return LocalStampVerdict::ambiguous();
    }

    /**
     * The UTC instants whose wall time in the zone is `$wall`: one, two in the
     * hour the autumn change repeats, none in the hour spring skips.
     *
     * @return list<CarbonImmutable>
     */
    private function wallReadings(string $wall): array
    {
        $first = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $wall, $this->zone);
        if (! $first instanceof CarbonImmutable) {
            return [];
        }

        $readings = [];
        // PHP reads a repeated wall time as its first occurrence, so the only
        // other reading is an hour later.
        foreach ([$first->utc(), $first->utc()->addHour()] as $candidate) {
            if ($candidate->setTimezone($this->zone)->format('Y-m-d H:i:s') === $wall) {
                $readings[$candidate->getTimestamp()] = $candidate;
            }
        }

        return array_values($readings);
    }

    private function sameStatement(CarbonImmutable $a, CarbonImmutable $b): bool
    {
        return abs($a->getTimestamp() - $b->getTimestamp()) <= self::SAME_STATEMENT_SECONDS;
    }

    /**
     * The zone's least and greatest UTC offset, in seconds, from the earliest
     * switch to the old release's end. One range for every column: a column
     * whose era started later can only have a narrower one, and the wider
     * range only widens what is reported instead of corrected.
     *
     * @return array{int, int}
     */
    private function offsetRange(): array
    {
        if ($this->offsets === null) {
            $from = CarbonImmutable::parse(self::ERA_FLOOR, 'UTC')->getTimestamp();
            $until = CarbonImmutable::parse(self::OLD_RELEASE_UNTIL, 'UTC')->getTimestamp();
            $offsets = array_column($this->zone->getTransitions($from, $until) ?: [], 'offset');
            $offsets[] = $this->zone->getOffset(CarbonImmutable::createFromTimestamp($until, 'UTC'));
            $this->offsets = [min($offsets), max($offsets)];
        }

        return $this->offsets;
    }
}
