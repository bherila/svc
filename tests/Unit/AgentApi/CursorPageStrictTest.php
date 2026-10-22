<?php

namespace Tests\Unit\AgentApi;

use App\Exceptions\InvalidAgentApiCursor;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\CursorPage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(CursorPage::class)]
#[CoversClass(AgentApiCursor::class)]
final class CursorPageStrictTest extends TestCase
{
    public function test_authenticated_non_json_is_an_invalid_cursor_in_both_modes(): void
    {
        config(['agent_api.accept_legacy_cursors' => true]);
        $cursor = Crypt::encryptString('synthetic-not-json');
        foreach ([false, true] as $allowLegacy) {
            try {
                AgentApiCursor::decode($cursor, 'synthetic-workspace', 'synthetic-query', $allowLegacy);
                $this->fail('An authenticated non-JSON payload must not become a valid continuation.');
            } catch (InvalidAgentApiCursor $exception) {
                $this->assertStringContainsString('opaque pagination cursor', $exception->getMessage());
            }
        }
    }

    public function test_strict_cursor_is_checked_before_fetch_while_existing_default_accepts_legacy(): void
    {
        config(['agent_api.accept_legacy_cursors' => true]);
        $workspace = new Workspace;
        $workspace->forceFill(['id' => 1, 'public_id' => 'synthetic-workspace']);
        $fetches = [];
        $fetch = function (int $take, ?int $after) use (&$fetches): Collection {
            $fetches[] = [$take, $after];

            return new Collection;
        };
        CursorPage::run($workspace, 'old-list', 1, base64_encode('42'), $fetch, fn (Model $row): array => []);
        $this->assertSame([[2, 42]], $fetches);
        $this->expectException(InvalidAgentApiCursor::class);
        CursorPage::run($workspace, 'new-files', 1, base64_encode('42'), function (int $take, ?int $after): Collection {
            $this->fail('Strict list must reject a legacy cursor before executing a fetch.');
        }, fn (Model $row): array => [], allowLegacyCursor: false);
    }
}
