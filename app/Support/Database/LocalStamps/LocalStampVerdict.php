<?php

namespace App\Support\Database\LocalStamps;

use Carbon\CarbonImmutable;

/** What {@see LocalStampClassifier} decided about one stored value. */
final readonly class LocalStampVerdict
{
    public const CORRECTED = 'corrected';

    public const ALREADY_UTC = 'already_utc';

    public const OUTSIDE = 'outside';

    public const AMBIGUOUS = 'ambiguous';

    private function __construct(public string $kind, public ?CarbonImmutable $instant) {}

    /** Written in the wall clock; `$instant` is the UTC moment it meant. */
    public static function corrected(CarbonImmutable $instant): self
    {
        return new self(self::CORRECTED, $instant->utc());
    }

    /** Written in the era, in UTC. */
    public static function alreadyUtc(): self
    {
        return new self(self::ALREADY_UTC, null);
    }

    /** Not written while the column was local: before the switch, or after #712. */
    public static function outside(): self
    {
        return new self(self::OUTSIDE, null);
    }

    /** Could be either: left as stored and reported. */
    public static function ambiguous(): self
    {
        return new self(self::AMBIGUOUS, null);
    }

    public function stored(): ?string
    {
        return $this->instant?->format('Y-m-d H:i:s');
    }
}
