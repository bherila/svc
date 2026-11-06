<?php

declare(strict_types=1);

namespace App\Support\Accounting;

use Closure;

/**
 * Reads one source of an accounting feed until it has produced enough entries
 * to decide a page, or has run out.
 *
 * A source's rows come newest first and each yields any number of entries
 * (a due-date correction yields none), so a batch of `$need` rows may hold
 * fewer than `$need` entries. Reading on batch by batch until `$need`
 * entries after the cursor are in hand makes the page exact: every row not
 * yet read sorts below every entry already held.
 */
final class SourceCollector
{
    /**
     * @template TRow
     *
     * @param  Closure(array{string, int}|null, int): array<int, TRow>  $fetch  the next `$need` rows newest first, strictly below the (instant, id) given when there is one
     * @param  Closure(TRow): array{string, int}  $key  a row's (instant, id)
     * @param  Closure(array<int, TRow>): list<FeedEntry>  $expand  the entries of a batch of rows
     * @return list<FeedEntry> entries strictly after `$after`, at least `$need` of them unless the source is exhausted
     */
    public static function collect(Closure $fetch, Closure $key, Closure $expand, ?FeedPosition $after, int $need): array
    {
        $entries = [];
        $below = null;
        do {
            $rows = $fetch($below, $need);
            foreach ($expand($rows) as $entry) {
                if ($after === null || $entry->position->compare($after) < 0) {
                    $entries[] = $entry;
                }
            }
            $exhausted = count($rows) < $need;
            if (! $exhausted) {
                $below = $key(array_slice($rows, -1)[0]);
            }
        } while (! $exhausted && count($entries) < $need);

        return $entries;
    }
}
