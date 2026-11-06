<?php

declare(strict_types=1);

namespace App\Support\Accounting;

/**
 * Where one entry sits in an accounting feed: the UTC instant it entered the
 * books to the microsecond (`Y-m-d H:i:s.u`), then the fixed rank of the
 * source it comes from, the source row's id and the entry's index within that
 * row. The four together are unique and never change, so a page can stop at
 * any entry and the next resumes after it, however many entries share a
 * second.
 *
 * Feeds list newest first: the greatest position comes first.
 */
final readonly class FeedPosition
{
    public function __construct(
        public string $at,
        public int $rank,
        public int $row,
        public int $sub = 0,
    ) {}

    /** Negative when this position sorts before the other, positive after, zero when equal. */
    public function compare(self $other): int
    {
        return strcmp($this->at, $other->at)
            ?: $this->rank <=> $other->rank
            ?: $this->row <=> $other->row
            ?: $this->sub <=> $other->sub;
    }

    /**
     * Among a source's rows stamped at exactly this position's instant, which
     * may still hold entries after it (entries sort below this position):
     * every row when the source ranks below this position's source, rows up
     * to and including this row when it is the same source (the row may have
     * entries left), none when it ranks above.
     *
     * @return int|null the greatest row id that qualifies, or null for none
     */
    public function rowCeiling(int $sourceRank): ?int
    {
        return match (true) {
            $sourceRank < $this->rank => PHP_INT_MAX,
            $sourceRank === $this->rank => $this->row,
            default => null,
        };
    }
}
