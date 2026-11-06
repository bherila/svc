<?php

namespace Tests\Feature\Submission;

use App\Support\AgentApi\AgentApiScopes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsReviewerWorld;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/** The same operations over MCP: one rule, two transports. */
final class SubmissionMcpTest extends TestCase
{
    use BuildsReviewerWorld;
    use CallsMcp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-06 12:00:00');
        config(['agent_api.mcp_enabled' => true]);
        $this->buildReviewerWorld();
        $this->actingAsMcp($this->otherOwner, array_keys(AgentApiScopes::descriptions()));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_submit_list_withdraw_and_the_timesheet_over_mcp(): void
    {
        $entry = $this->hours($this->otherProject, $this->otherOwner, '2026-10-14', 60, ['status' => 'draft', 'billing_rate_amount' => null]);
        $session = $this->initialize();
        $workspace = $this->otherEng->public_id;
        $names = $this->toolNames($session);
        foreach (['submissions.list', 'submissions.submit', 'submissions.withdraw', 'timesheets.approved'] as $tool) {
            $this->assertContains($tool, $names);
        }

        $submitted = $this->callTool($session, 'submissions.submit', ['workspace_id' => $workspace, 'period' => '2026-10', 'idempotency_key' => 'mcp-submit-1'])['result'];
        $this->assertFalse($submitted['isError'] ?? false, json_encode($submitted));
        $this->assertSame('submitted', $submitted['structuredContent']['data']['items'][0]['state']);
        $this->assertNotNull($entry->fresh()->submitted_at);

        $list = $this->callTool($session, 'submissions.list', ['workspace_id' => $workspace, 'period' => '2026-10'])['result'];
        $this->assertSame('submitted', $list['structuredContent']['data']['time'][0]['state']);

        $withdrawn = $this->callTool($session, 'submissions.withdraw', ['workspace_id' => $workspace, 'time_entry_ids' => [$entry->public_id], 'idempotency_key' => 'mcp-withdraw-1'])['result'];
        $this->assertFalse($withdrawn['isError'] ?? false, json_encode($withdrawn));
        $this->assertNull($entry->fresh()->submitted_at);

        $sheet = $this->callTool($session, 'timesheets.approved', ['workspace_id' => $workspace, 'period' => '2026-10'])['result'];
        $this->assertSame([], $sheet['structuredContent']['data']['entries']);
    }

    public function test_an_mcp_caller_cannot_submit_another_workspaces_rows(): void
    {
        $theirs = $this->hours($this->herilaProject, $this->herilaWorker, '2026-10-14', 60, ['status' => 'draft']);
        $session = $this->initialize();

        $result = $this->callTool($session, 'submissions.submit', ['workspace_id' => $this->otherEng->public_id, 'time_entry_ids' => [$theirs->public_id], 'idempotency_key' => 'mcp-foreign'])['result'];

        $this->assertTrue($result['isError']);
        $this->assertNull($theirs->fresh()->submitted_at);
    }
}
