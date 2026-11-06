<?php

declare(strict_types=1);

namespace App\Support\Accounting;

/**
 * The time bounds one pass over an accounting feed reads inside: an inclusive
 * lower bound (`updated_since`) and the snapshot watermark above it, both
 * whole seconds in UTC (`Y-m-d H:i:s`).
 */
final readonly class FeedBounds
{
    /** An upper bound no stored instant reaches: a read of everything recorded. */
    public const string END = '9999-12-31 23:59:59';

    public function __construct(
        public ?string $since,
        public string $upper,
    ) {}

    /** The lower bound as a column of this precision stores an instant, or null for none. */
    public function lower(bool $microseconds): ?string
    {
        return $this->since === null ? null : self::column($this->since, $microseconds);
    }

    /** The upper bound as a column of this precision stores an instant. */
    public function ceiling(bool $microseconds): string
    {
        return self::column($this->upper, $microseconds);
    }

    /**
     * A whole-second instant in the form a column stores: with six fractional
     * digits when it keeps microseconds. SQLite compares the stored text, so a
     * bound must be spelled as the column spells its values.
     */
    public static function column(string $instant, bool $microseconds): string
    {
        return $microseconds ? $instant.'.000000' : $instant;
    }
}
