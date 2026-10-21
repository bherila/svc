<?php

namespace Tests\Unit\AgentApi;

use App\Exceptions\InvalidAgentApiCursor;
use App\Support\AgentApi\ChangeFeedCursor;
use Illuminate\Support\Facades\Crypt;
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
}
