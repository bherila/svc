<?php

namespace Tests\Unit\Accounting;

use App\Exceptions\InvalidAgentApiCursor;
use App\Support\Accounting\FeedCursor;
use App\Support\Accounting\FeedPosition;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(FeedCursor::class)]
final class FeedCursorTest extends TestCase
{
    private const string WORKSPACE = 'synthetic-workspace';

    private const string QUERY = 'synthetic-query';

    private function cursor(): FeedCursor
    {
        return new FeedCursor(new FeedPosition('2030-01-20 12:00:00.123456', 2, 41, 3), '2030-01-20 12:03:00');
    }

    public function test_a_cursor_round_trips_its_position_and_watermark(): void
    {
        $decoded = FeedCursor::decode($this->cursor()->encode(self::WORKSPACE, self::QUERY), self::WORKSPACE, self::QUERY);

        $this->assertNotNull($decoded);
        $this->assertEquals(new FeedPosition('2030-01-20 12:00:00.123456', 2, 41, 3), $decoded->position);
        $this->assertSame('2030-01-20 12:03:00', $decoded->watermark);
    }

    public function test_no_cursor_is_the_start_of_a_pass(): void
    {
        $this->assertNull(FeedCursor::decode(null, self::WORKSPACE, self::QUERY));
        $this->assertNull(FeedCursor::decode('', self::WORKSPACE, self::QUERY));
    }

    public function test_the_cursor_is_opaque(): void
    {
        $encoded = $this->cursor()->encode(self::WORKSPACE, self::QUERY);

        $this->assertStringNotContainsString('2030', $encoded);
        $this->assertStringNotContainsString(self::WORKSPACE, $encoded);
    }

    public function test_a_cursor_is_bound_to_its_workspace_and_query(): void
    {
        $encoded = $this->cursor()->encode(self::WORKSPACE, self::QUERY);

        foreach ([['other-workspace', self::QUERY], [self::WORKSPACE, 'other-query']] as [$workspace, $query]) {
            try {
                FeedCursor::decode($encoded, $workspace, $query);
                $this->fail('A cursor from another workspace or query was accepted.');
            } catch (InvalidAgentApiCursor $e) {
                $this->assertSame('cursor is not valid for this workspace and query.', $e->getMessage());
            }
        }
    }

    public function test_text_that_is_not_a_cursor_is_refused(): void
    {
        foreach (['not-a-cursor', Crypt::encryptString('not json')] as $text) {
            try {
                FeedCursor::decode($text, self::WORKSPACE, self::QUERY);
                $this->fail('Text that is not a cursor was accepted.');
            } catch (InvalidAgentApiCursor $e) {
                $this->assertSame('cursor must be an opaque pagination cursor.', $e->getMessage());
            }
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidFields(): iterable
    {
        yield 'another version' => ['version', 2];
        yield 'another feed' => ['feed', 'changes'];
        yield 'unreadable instant' => ['at', '2030-01-20 12:00:00'];
        yield 'non-string instant' => ['at', 7];
        yield 'zero rank' => ['rank', 0];
        yield 'string rank' => ['rank', '2'];
        yield 'zero row' => ['row', 0];
        yield 'string row' => ['row', '41'];
        yield 'negative index' => ['sub', -1];
        yield 'string index' => ['sub', '3'];
        yield 'unreadable watermark' => ['watermark', 'tomorrow'];
        yield 'watermark with fractions' => ['watermark', '2030-01-20 12:03:00.000000'];
        yield 'non-string workspace' => ['workspace', 7];
        yield 'non-string query' => ['query', 7];
    }

    #[DataProvider('invalidFields')]
    public function test_a_payload_field_out_of_shape_is_refused(string $field, mixed $value): void
    {
        $payload = json_decode(Crypt::decryptString($this->cursor()->encode(self::WORKSPACE, self::QUERY)), true, flags: JSON_THROW_ON_ERROR);
        $payload[$field] = $value;

        $this->expectException(InvalidAgentApiCursor::class);
        FeedCursor::decode(Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), self::WORKSPACE, self::QUERY);
    }

    public function test_a_payload_missing_a_field_is_refused(): void
    {
        $payload = json_decode(Crypt::decryptString($this->cursor()->encode(self::WORKSPACE, self::QUERY)), true, flags: JSON_THROW_ON_ERROR);
        unset($payload['sub']);

        $this->expectException(InvalidAgentApiCursor::class);
        FeedCursor::decode(Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), self::WORKSPACE, self::QUERY);
    }

    /** @return iterable<string, array{FeedCursor}> */
    public static function unencodable(): iterable
    {
        yield 'an instant without microseconds' => [new FeedCursor(new FeedPosition('2030-01-20 12:00:00', 1, 1), '2030-01-20 12:03:00')];
        yield 'a zero rank' => [new FeedCursor(new FeedPosition('2030-01-20 12:00:00.000000', 0, 1), '2030-01-20 12:03:00')];
        yield 'a zero row' => [new FeedCursor(new FeedPosition('2030-01-20 12:00:00.000000', 1, 0), '2030-01-20 12:03:00')];
        yield 'a negative index' => [new FeedCursor(new FeedPosition('2030-01-20 12:00:00.000000', 1, 1, -1), '2030-01-20 12:03:00')];
        yield 'a watermark that is not whole seconds' => [new FeedCursor(new FeedPosition('2030-01-20 12:00:00.000000', 1, 1), '2030-01-20T12:03:00Z')];
    }

    #[DataProvider('unencodable')]
    public function test_a_position_out_of_shape_is_not_encoded(FeedCursor $cursor): void
    {
        $this->expectException(InvalidAgentApiCursor::class);
        $cursor->encode(self::WORKSPACE, self::QUERY);
    }

    public function test_an_index_of_zero_is_a_position(): void
    {
        $encoded = (new FeedCursor(new FeedPosition('2030-01-20 12:00:00.000000', 1, 1, 0), '2030-01-20 12:03:00'))->encode(self::WORKSPACE, self::QUERY);

        $this->assertSame(0, FeedCursor::decode($encoded, self::WORKSPACE, self::QUERY)?->position->sub);
    }
}
