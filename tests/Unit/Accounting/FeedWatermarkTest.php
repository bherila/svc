<?php

namespace Tests\Unit\Accounting;

use App\Exceptions\InvalidAgentApiCursor;
use App\Support\Accounting\FeedBounds;
use App\Support\Accounting\FeedCursor;
use App\Support\Accounting\FeedPosition;
use App\Support\Accounting\FeedWatermark;
use PHPUnit\Framework\TestCase;

final class FeedWatermarkTest extends TestCase
{
    private const string LATEST = '2030-01-20 12:00:00';

    private function cursor(): FeedCursor
    {
        return new FeedCursor(new FeedPosition('2030-01-20 11:00:00.000000', 1, 1), '2030-01-20 11:30:00');
    }

    public function test_a_new_pass_is_bounded_by_the_latest_watermark_or_the_one_named(): void
    {
        $this->assertSame(self::LATEST, FeedWatermark::resolve(null, null, self::LATEST));
        $this->assertSame('2030-01-19 00:00:00', FeedWatermark::resolve('2030-01-19 00:00:00', null, self::LATEST));
    }

    public function test_a_pass_in_progress_keeps_the_watermark_of_its_cursor(): void
    {
        $this->assertSame('2030-01-20 11:30:00', FeedWatermark::resolve(null, $this->cursor(), self::LATEST));
        $this->assertSame('2030-01-20 11:30:00', FeedWatermark::resolve('2030-01-20 11:30:00', $this->cursor(), self::LATEST));
    }

    public function test_a_different_watermark_beside_a_cursor_is_refused(): void
    {
        $this->expectException(InvalidAgentApiCursor::class);
        $this->expectExceptionMessage('watermark does not match the cursor.');
        FeedWatermark::resolve('2030-01-20 11:30:01', $this->cursor(), self::LATEST);
    }

    public function test_bounds_spell_instants_as_a_column_stores_them(): void
    {
        $bounds = new FeedBounds('2030-01-01 00:00:00', '2030-01-20 12:00:00');

        $this->assertSame('2030-01-01 00:00:00', $bounds->lower(false));
        $this->assertSame('2030-01-01 00:00:00.000000', $bounds->lower(true));
        $this->assertSame('2030-01-20 12:00:00', $bounds->ceiling(false));
        $this->assertSame('2030-01-20 12:00:00.000000', $bounds->ceiling(true));
        $this->assertNull((new FeedBounds(null, FeedBounds::END))->lower(true));
        $this->assertSame('9999-12-31 23:59:59', FeedBounds::END);
    }
}
