<?php

namespace Tests\Unit\Accounting;

use App\Support\Accounting\FeedEntry;
use App\Support\Accounting\FeedMerger;
use App\Support\Accounting\FeedPosition;
use PHPUnit\Framework\TestCase;

final class FeedMergerTest extends TestCase
{
    public function test_it_returns_the_newest_entries_first_across_sources(): void
    {
        $entries = [
            self::entry('2030-01-01 10:00:00.000000', 1, 5),
            self::entry('2030-01-01 12:00:00.000000', 2, 1),
            self::entry('2030-01-01 11:00:00.000000', 3, 9),
            self::entry('2030-01-01 12:00:00.000000', 3, 1),
        ];

        [$page, $more] = FeedMerger::page($entries, null, 10);

        $this->assertSame(['3:1', '2:1', '3:9', '1:5'], self::ids($page));
        $this->assertFalse($more);
    }

    public function test_a_page_holds_the_limit_and_says_whether_more_follow(): void
    {
        $entries = [
            self::entry('2030-01-01 10:00:00.000000', 1, 1),
            self::entry('2030-01-01 11:00:00.000000', 1, 2),
            self::entry('2030-01-01 12:00:00.000000', 1, 3),
        ];

        [$page, $more] = FeedMerger::page($entries, null, 2);
        $this->assertSame(['1:3', '1:2'], self::ids($page));
        $this->assertTrue($more);

        [$page, $more] = FeedMerger::page($entries, null, 3);
        $this->assertSame(['1:3', '1:2', '1:1'], self::ids($page));
        $this->assertFalse($more);
    }

    public function test_it_resumes_strictly_after_the_cursor_position(): void
    {
        $entries = [
            self::entry('2030-01-01 12:00:00.000000', 1, 1, 0),
            self::entry('2030-01-01 12:00:00.000000', 1, 1, 1),
            self::entry('2030-01-01 12:00:00.000000', 1, 1, 2),
            self::entry('2030-01-01 11:59:59.999999', 3, 9, 9),
        ];

        // Entries tied on instant, source and row are told apart by their index; the cursor's own entry is not repeated.
        [$page, $more] = FeedMerger::page($entries, new FeedPosition('2030-01-01 12:00:00.000000', 1, 1, 1), 10);

        $this->assertSame(['1:1:0', '3:9:9'], array_map(
            static fn (FeedEntry $entry): string => $entry->position->rank.':'.$entry->position->row.':'.$entry->position->sub,
            $page,
        ));
        $this->assertFalse($more);
    }

    public function test_an_empty_feed_is_an_empty_page(): void
    {
        $this->assertSame([[], false], FeedMerger::page([], null, 5));
    }

    private static function entry(string $at, int $rank, int $row, int $sub = 0): FeedEntry
    {
        return new FeedEntry(new FeedPosition($at, $rank, $row, $sub), []);
    }

    /**
     * @param  list<FeedEntry>  $page
     * @return list<string>
     */
    private static function ids(array $page): array
    {
        return array_map(static fn (FeedEntry $entry): string => $entry->position->rank.':'.$entry->position->row, $page);
    }
}
