<?php

namespace Tests\Unit\Accounting;

use App\Support\Accounting\FeedPosition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeedPositionTest extends TestCase
{
    /** @return iterable<string, array{FeedPosition, FeedPosition, int}> expected sign of compare() */
    public static function orderings(): iterable
    {
        $base = new FeedPosition('2030-01-02 03:04:05.000000', 2, 7, 3);
        yield 'identical' => [$base, new FeedPosition('2030-01-02 03:04:05.000000', 2, 7, 3), 0];
        yield 'a microsecond later' => [new FeedPosition('2030-01-02 03:04:05.000001', 1, 1, 0), $base, 1];
        yield 'a second earlier' => [new FeedPosition('2030-01-02 03:04:04.999999', 9, 99, 9), $base, -1];
        yield 'same instant, higher source' => [new FeedPosition('2030-01-02 03:04:05.000000', 3, 1, 0), $base, 1];
        yield 'same instant, lower source' => [new FeedPosition('2030-01-02 03:04:05.000000', 1, 99, 9), $base, -1];
        yield 'same source, higher row' => [new FeedPosition('2030-01-02 03:04:05.000000', 2, 8, 0), $base, 1];
        yield 'same source, lower row' => [new FeedPosition('2030-01-02 03:04:05.000000', 2, 6, 9), $base, -1];
        yield 'same row, higher index' => [new FeedPosition('2030-01-02 03:04:05.000000', 2, 7, 4), $base, 1];
        yield 'same row, lower index' => [new FeedPosition('2030-01-02 03:04:05.000000', 2, 7, 2), $base, -1];
    }

    #[DataProvider('orderings')]
    public function test_positions_order_by_instant_then_source_then_row_then_index(FeedPosition $a, FeedPosition $b, int $sign): void
    {
        $this->assertSame($sign, $a->compare($b) <=> 0);
        $this->assertSame(-$sign, $b->compare($a) <=> 0);
    }

    public function test_the_index_defaults_to_zero(): void
    {
        $this->assertSame(0, (new FeedPosition('2030-01-02 03:04:05.000000', 1, 1))->sub);
    }

    public function test_rows_at_the_cursors_instant_qualify_by_the_rank_of_their_source(): void
    {
        $position = new FeedPosition('2030-01-02 03:04:05.000000', 2, 7, 3);

        // A source ranked below the cursor's has every row at that instant still to come.
        $this->assertSame(PHP_INT_MAX, $position->rowCeiling(1));
        // The same source: this row may have entries left, rows above it were already read.
        $this->assertSame(7, $position->rowCeiling(2));
        // A source ranked above the cursor's was read in full at that instant.
        $this->assertNull($position->rowCeiling(3));
    }
}
