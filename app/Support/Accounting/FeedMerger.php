<?php

declare(strict_types=1);

namespace App\Support\Accounting;

/** Merges the entries every source of an accounting feed produced into one newest-first page. */
final class FeedMerger
{
    /**
     * The first `$limit` entries strictly after `$after` (below it, newest
     * first), and whether more follow.
     *
     * @param  list<FeedEntry>  $entries  from every source, in any order
     * @return array{list<FeedEntry>, bool}
     */
    public static function page(array $entries, ?FeedPosition $after, int $limit): array
    {
        $remaining = array_values(array_filter(
            $entries,
            static fn (FeedEntry $entry): bool => $after === null || $entry->position->compare($after) < 0,
        ));
        usort($remaining, static fn (FeedEntry $a, FeedEntry $b): int => $b->position->compare($a->position));

        return [array_slice($remaining, 0, $limit), count($remaining) > $limit];
    }
}
