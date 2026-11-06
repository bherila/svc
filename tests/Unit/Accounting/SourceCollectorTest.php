<?php

namespace Tests\Unit\Accounting;

use App\Support\Accounting\FeedEntry;
use App\Support\Accounting\FeedPosition;
use App\Support\Accounting\SourceCollector;
use PHPUnit\Framework\TestCase;

final class SourceCollectorTest extends TestCase
{
    /**
     * Rows 10 down to 1, one second apart; a row yields as many entries as its id says (row 3 and row 6 yield none).
     *
     * @return array{list<array{string, int}>, list<int>} the (instant, id) of every fetch's `$below`, and the batches asked for
     */
    private function collect(?FeedPosition $after, int $need, array &$fetches = []): array
    {
        $rows = [];
        for ($id = 10; $id >= 1; $id--) {
            $rows[] = [sprintf('2030-01-01 00:00:%02d', $id), $id];
        }
        $entries = SourceCollector::collect(
            fetch: static function (?array $below, int $batch) use ($rows, &$fetches): array {
                $fetches[] = [$below, $batch];

                return array_values(array_slice(array_filter($rows, static fn (array $row): bool => $below === null || $row[1] < $below[1]), 0, $batch));
            },
            key: static fn (array $row): array => $row,
            expand: static fn (array $batch): array => array_merge(...array_map(
                static fn (array $row): array => in_array($row[1], [3, 6], true) ? [] : [new FeedEntry(new FeedPosition($row[0].'.000000', 1, $row[1]), [])],
                $batch,
            ) ?: [[]]),
            after: $after,
            need: $need,
        );

        return array_map(static fn (FeedEntry $entry): int => $entry->position->row, $entries);
    }

    public function test_it_reads_batch_by_batch_until_it_holds_enough_entries(): void
    {
        $fetches = [];

        // Batches of 4 rows: 10,9,8,7 give four entries at once.
        $this->assertSame([10, 9, 8, 7], $this->collect(null, 4, $fetches));
        $this->assertSame([[null, 4]], $fetches);
    }

    public function test_rows_without_entries_do_not_end_the_read(): void
    {
        $fetches = [];

        // The first batch (rows 10 to 6) holds four entries, one short of five, so the next batch (rows 5 to 1) is read from below row 6.
        $this->assertSame([10, 9, 8, 7, 5, 4, 2, 1], $this->collect(null, 5, $fetches));
        $this->assertSame([[null, 5], [['2030-01-01 00:00:06', 6], 5]], $fetches);
    }

    public function test_a_short_batch_means_the_source_is_exhausted(): void
    {
        $fetches = [];

        $this->assertSame([10, 9, 8, 7, 5, 4, 2, 1], $this->collect(null, 20, $fetches));
        $this->assertSame([[null, 20]], $fetches);
    }

    public function test_entries_at_or_before_the_cursor_are_dropped(): void
    {
        // Entries after the cursor are those strictly below it: rows 7 and under.
        $this->assertSame([7, 5, 4, 2, 1], $this->collect(new FeedPosition('2030-01-01 00:00:08.000000', 1, 8), 20));
        // The cursor's own entry is not repeated.
        $this->assertSame([5], $this->collect(new FeedPosition('2030-01-01 00:00:07.000000', 1, 7), 1));
    }

    public function test_a_source_with_no_rows_has_no_entries(): void
    {
        $entries = SourceCollector::collect(
            fetch: static fn (?array $below, int $batch): array => [],
            key: static fn (array $row): array => $row,
            expand: static fn (array $batch): array => [],
            after: null,
            need: 3,
        );

        $this->assertSame([], $entries);
    }
}
