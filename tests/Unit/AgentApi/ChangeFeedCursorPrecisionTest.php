<?php

namespace Tests\Unit\AgentApi;

use App\Exceptions\InvalidAgentApiCursor;
use App\Support\AgentApi\ChangeFeedCursor;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ChangeFeedCursorPrecisionTest extends TestCase
{
    public function test_the_default_cursor_keeps_the_existing_second_precision_payload(): void
    {
        $encoded = (new ChangeFeedCursor('2030-01-20 12:00:00', 1, '2030-01-20 12:03:00'))->encode('synthetic-workspace', 'synthetic-query');
        $payload = json_decode(Crypt::decryptString($encoded), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('precision', $payload);
        $this->assertSame('2030-01-20 12:00:00', $payload['at']);
        $this->assertSame('2030-01-20 12:00:00', ChangeFeedCursor::decode($encoded, 'synthetic-workspace', 'synthetic-query')->changedAt);
    }

    public function test_a_microsecond_cursor_preserves_its_position_and_refuses_a_precision_mismatch(): void
    {
        $encoded = (new ChangeFeedCursor('2030-01-20 12:00:00.123456', 1, '2030-01-20 12:03:00', true))->encode('synthetic-workspace', 'synthetic-query');
        $decoded = ChangeFeedCursor::decode($encoded, 'synthetic-workspace', 'synthetic-query', true);
        $this->assertSame('2030-01-20 12:00:00.123456', $decoded->changedAt);
        $this->expectException(InvalidAgentApiCursor::class);
        ChangeFeedCursor::decode($encoded, 'synthetic-workspace', 'synthetic-query');
    }

    public function test_an_unstamped_position_round_trips_without_inventing_a_change_time(): void
    {
        foreach ([false, true] as $microseconds) {
            $cursor = new ChangeFeedCursor(null, 1, '2030-01-20 12:03:00', $microseconds);
            $encoded = $cursor->encode('synthetic-workspace', 'synthetic-query');
            $decoded = ChangeFeedCursor::decode($encoded, 'synthetic-workspace', 'synthetic-query', $microseconds);
            $this->assertNotNull($decoded);
            $this->assertNull($decoded->changedAt);
            $this->assertSame(1, $decoded->id);
            $this->assertSame($cursor->watermark, $decoded->watermark);
            $this->assertSame($microseconds, $decoded->microseconds);
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidPayloadFields(): iterable
    {
        yield 'different feed' => ['feed', 'synthetic-other-feed'];
        yield 'wrong precision with an otherwise valid timestamp' => ['precision', 6];
        yield 'unreadable timestamp' => ['at', 'not-a-change-time'];
        yield 'non-string timestamp' => ['at', 7];
        yield 'zero id' => ['id', 0];
        yield 'negative id' => ['id', -1];
        yield 'string id' => ['id', '1'];
        yield 'unreadable watermark' => ['watermark', 'not-a-watermark'];
        yield 'different workspace' => ['workspace', 'synthetic-other-workspace'];
        yield 'different query' => ['query', str_repeat('a', 64)];
    }

    #[DataProvider('invalidPayloadFields')]
    public function test_decode_refuses_each_invalid_payload_field_independently(string $field, mixed $value): void
    {
        $encoded = (new ChangeFeedCursor('2030-01-20 12:00:00', 1, '2030-01-20 12:03:00'))->encode('synthetic-workspace', 'synthetic-query');
        $payload = json_decode(Crypt::decryptString($encoded), true, flags: JSON_THROW_ON_ERROR);
        $payload[$field] = $value;
        $invalid = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
        $this->expectException(InvalidAgentApiCursor::class);
        ChangeFeedCursor::decode($invalid, 'synthetic-workspace', 'synthetic-query');
    }

    /** @return iterable<string, array{?string, int, string, bool}> */
    public static function invalidPositions(): iterable
    {
        yield 'zero id only' => ['2030-01-20 12:00:00', 0, '2030-01-20 12:03:00', false];
        yield 'negative id only' => [null, -1, '2030-01-20 12:03:00', false];
        yield 'invalid change time only' => ['not-a-change-time', 1, '2030-01-20 12:03:00', false];
        yield 'invalid watermark only' => ['2030-01-20 12:00:00', 1, 'not-a-watermark', false];
        yield 'microseconds required' => ['2030-01-20 12:00:00', 1, '2030-01-20 12:03:00', true];
    }

    #[DataProvider('invalidPositions')]
    public function test_encode_refuses_each_invalid_position_independently(?string $at, int $id, string $watermark, bool $microseconds): void
    {
        $this->expectException(InvalidAgentApiCursor::class);
        (new ChangeFeedCursor($at, $id, $watermark, $microseconds))->encode('synthetic-workspace', 'synthetic-query');
    }
}
