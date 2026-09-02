<?php

namespace Tests\Unit\AgentApi;

use App\Support\AgentApi\AgentApiCursor;
use InvalidArgumentException;
use Tests\TestCase;

final class AgentApiCursorTest extends TestCase
{
    public function test_it_encrypts_and_binds_new_cursors_to_workspace_and_query(): void
    {
        $cursor = AgentApiCursor::encode(42, 'workspace-a', 'projects|status=active|search=');

        $this->assertNotSame(base64_encode('42'), $cursor);
        $this->assertSame(42, AgentApiCursor::decode($cursor, 'workspace-a', 'projects|status=active|search='));

        $this->expectException(InvalidArgumentException::class);
        AgentApiCursor::decode($cursor, 'workspace-b', 'projects|status=active|search=');
    }

    public function test_it_rejects_a_cursor_reused_with_different_filters(): void
    {
        $cursor = AgentApiCursor::encode(42, 'workspace-a', 'invoices|status=draft');

        $this->expectException(InvalidArgumentException::class);
        AgentApiCursor::decode($cursor, 'workspace-a', 'invoices|status=issued');
    }

    public function test_it_accepts_legacy_cursors_only_during_the_explicit_compatibility_window(): void
    {
        $legacy = base64_encode('42');
        $this->assertSame(42, AgentApiCursor::decode($legacy, 'workspace-a', 'projects'));

        config(['agent_api.accept_legacy_cursors' => false]);
        $this->expectException(InvalidArgumentException::class);
        AgentApiCursor::decode($legacy, 'workspace-a', 'projects');
    }
}
