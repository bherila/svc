<?php

namespace Tests\Unit\Mcp;

use App\Services\Mcp\Toolsets\McpToolPage;
use Mcp\Exception\InvalidCursorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpToolPage::class)]
final class McpToolPageTest extends TestCase
{
    public function test_pages_follow_the_cursor_to_the_end(): void
    {
        $names = ['a', 'b', 'c', 'd', 'e'];

        $first = McpToolPage::slice($names, 2, null);
        $this->assertSame(['a', 'b'], $first['names']);
        $this->assertSame(base64_encode('2'), $first['next']);

        $second = McpToolPage::slice($names, 2, $first['next']);
        $this->assertSame(['c', 'd'], $second['names']);
        $this->assertSame(base64_encode('4'), $second['next']);

        $third = McpToolPage::slice($names, 2, $second['next']);
        $this->assertSame(['e'], $third['names']);
        $this->assertNull($third['next']);
    }

    public function test_a_page_that_ends_exactly_at_the_last_item_has_no_next_page(): void
    {
        $this->assertNull(McpToolPage::slice(['a', 'b', 'c', 'd'], 2, base64_encode('2'))['next']);
        $this->assertNull(McpToolPage::slice(['a', 'b'], 2, null)['next']);
        $this->assertSame(base64_encode('2'), McpToolPage::slice(['a', 'b', 'c'], 2, null)['next']);
    }

    public function test_a_list_shorter_than_a_page_or_empty_is_one_page(): void
    {
        $this->assertSame(['names' => ['a'], 'next' => null], McpToolPage::slice(['a'], 100, null));
        $this->assertSame(['names' => [], 'next' => null], McpToolPage::slice([], 100, null));
    }

    public function test_the_cursor_may_point_exactly_past_the_last_item(): void
    {
        $this->assertSame(['names' => [], 'next' => null], McpToolPage::slice(['a', 'b'], 100, base64_encode('2')));
        $this->assertSame(['names' => ['a', 'b'], 'next' => null], McpToolPage::slice(['a', 'b'], 100, base64_encode('0')));
    }

    /** @return iterable<string, array{string}> */
    public static function badCursors(): iterable
    {
        yield 'not base64' => ['***'];
        yield 'not a number' => [base64_encode('abc')];
        yield 'negative' => [base64_encode('-1')];
        yield 'one past the end' => [base64_encode('3')];
        yield 'far past the end' => [base64_encode('500')];
    }

    #[DataProvider('badCursors')]
    public function test_a_cursor_that_is_not_ours_is_refused(string $cursor): void
    {
        $this->expectException(InvalidCursorException::class);

        McpToolPage::slice(['a', 'b'], 100, $cursor);
    }
}
