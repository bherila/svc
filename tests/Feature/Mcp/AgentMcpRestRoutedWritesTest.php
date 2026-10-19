<?php

namespace Tests\Feature\Mcp;

use App\Models\AgentMutationAudit;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Mcp\AgentMcpApiFailure;
use App\Support\AgentApi\AgentApiScopes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * The MCP write tools that used to call their actions directly now run their
 * REST operation (#459): the same response, the same receipt, and the API's
 * own refusal - a conflict or a field error comes back as a tool error naming
 * it, not as the SDK's generic internal error.
 */
final class AgentMcpRestRoutedWritesTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
    }

    public function test_task_writes_answer_as_the_rest_route_and_share_its_receipts(): void
    {
        [$workspace, $project, $owner] = $this->fixture();
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::TASKS_WRITE]);
        $session = $this->initialize();

        $created = $this->data($this->callTool($session, 'tasks.create', ['workspace_id' => $workspace->public_id, 'project_id' => $project->public_id, 'title' => 'Synthetic routed task', 'idempotency_key' => 'routed-task-create']));
        $replay = $this->withHeader('Idempotency-Key', 'routed-task-create')
            ->postJson("/api/v1/workspaces/{$workspace->public_id}/projects/{$project->public_id}/tasks", ['title' => 'Synthetic routed task'])
            ->assertCreated()->json('data');
        $this->assertSame($replay, $created, 'REST replays the receipt the MCP call wrote, with the same body');
        $this->assertSame(1, ClientTask::query()->where('workspace_id', $workspace->id)->count());

        $arguments = ['workspace_id' => $workspace->public_id, 'task_id' => $created['id'], 'expected_version' => $created['version'], 'status' => 'completed'];
        $updated = $this->data($this->callTool($session, 'tasks.update', [...$arguments, 'idempotency_key' => 'routed-task-update']));
        $this->assertSame('completed', $updated['status']);

        // A stale version: REST's 409, word for word, as a tool error.
        $stale = $this->withHeader('Idempotency-Key', 'routed-task-stale-rest')
            ->patchJson("/api/v1/workspaces/{$workspace->public_id}/tasks/{$created['id']}", ['expected_version' => $created['version'], 'status' => 'open'])
            ->assertConflict();
        $this->assertRefusal(AgentMcpApiFailure::message(409, $stale->json()), $this->callTool($session, 'tasks.update', [...$arguments, 'status' => 'open', 'idempotency_key' => 'routed-task-stale']));
        $this->assertSame('completed', ClientTask::query()->where('public_id', $created['id'])->value('status'));
        // Both refusals reached the same audit boundary.
        $this->assertSame(2, AgentMutationAudit::query()->where('operation', 'tasks.update')->where('outcome', 'failed')->where('error_category', 'conflict')->count());
    }

    public function test_a_task_outside_the_callers_workspace_is_refused_as_rest_refuses_it(): void
    {
        [$workspace, , $owner] = $this->fixture();
        [$foreign, $foreignProject] = $this->fixture();
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::TASKS_WRITE]);
        $session = $this->initialize();

        foreach ([$workspace, $foreign] as $index => $target) {
            $rest = $this->withHeader('Idempotency-Key', "foreign-task-rest-{$index}")
                ->postJson("/api/v1/workspaces/{$target->public_id}/projects/{$foreignProject->public_id}/tasks", ['title' => 'Synthetic foreign task']);
            $this->assertContains($rest->status(), [403, 404]);
            $this->assertRefusal(AgentMcpApiFailure::message($rest->status(), $rest->json()), $this->callTool($session, 'tasks.create', ['workspace_id' => $target->public_id, 'project_id' => $foreignProject->public_id, 'title' => 'Synthetic foreign task', 'idempotency_key' => "foreign-task-{$index}"]));
        }
        $this->assertSame(0, ClientTask::query()->count());
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_expense_writes_answer_as_the_rest_route_and_share_its_receipts(): void
    {
        config(['agent_api.expense_writes_enabled' => true]);
        [$workspace, $project] = $this->fixture();
        $owner = $this->member($workspace, 'owner');
        $company = ClientCompany::query()->findOrFail($project->client_company_id);
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::EXPENSES_READ, AgentApiScopes::EXPENSES_WRITE]);
        $session = $this->initialize();
        $base = "/api/v1/workspaces/{$workspace->public_id}/expenses";
        $entry = ['company_id' => $company->public_id, 'project_id' => $project->public_id, 'spent_on' => '2026-10-01', 'amount' => 1250, 'currency' => 'USD', 'description' => 'Synthetic routed expense'];

        $logged = $this->data($this->callTool($session, 'expenses.log', ['workspace_id' => $workspace->public_id, 'entries' => [$entry], 'idempotency_key' => 'routed-expense-log']));
        $this->assertSame($this->withHeader('Idempotency-Key', 'routed-expense-log')->postJson($base, ['entries' => [$entry]])->assertCreated()->json('data'), $logged);
        $expense = $logged[0];

        // A blank description passes the tool's schema and is refused by the route's rules.
        $blank = [...$entry, 'description' => '   '];
        $refused = $this->withHeader('Idempotency-Key', 'routed-expense-blank-rest')->postJson($base, ['entries' => [$blank]])->assertUnprocessable();
        $this->assertRefusal(AgentMcpApiFailure::message(422, $refused->json()), $this->callTool($session, 'expenses.log', ['workspace_id' => $workspace->public_id, 'entries' => [$blank], 'idempotency_key' => 'routed-expense-blank']));

        // An omitted project keeps the attribution.
        $facts = ['spent_on' => '2026-10-02', 'amount' => 1300, 'currency' => 'USD', 'description' => 'Synthetic revised expense'];
        $updated = $this->data($this->callTool($session, 'expenses.update', ['workspace_id' => $workspace->public_id, 'expense_id' => $expense['id'], 'expected_version' => $expense['version'], 'idempotency_key' => 'routed-expense-update', ...$facts]));
        $this->assertSame([1300, $project->public_id], [$updated['amount'], $updated['project_id']]);
        $stale = $this->withHeader('Idempotency-Key', 'routed-expense-stale-rest')->patchJson("{$base}/{$expense['id']}", ['expected_version' => $expense['version'], ...$facts])->assertConflict();
        $this->assertRefusal(AgentMcpApiFailure::message(409, $stale->json()), $this->callTool($session, 'expenses.delete', ['workspace_id' => $workspace->public_id, 'expense_id' => $expense['id'], 'expected_version' => $expense['version'], 'idempotency_key' => 'routed-expense-stale']));

        $deleted = $this->data($this->callTool($session, 'expenses.delete', ['workspace_id' => $workspace->public_id, 'expense_id' => $expense['id'], 'expected_version' => $updated['version'], 'idempotency_key' => 'routed-expense-delete']));
        $this->assertSame(['deleted_id' => $expense['id']], $deleted);
        $this->assertSame($deleted, $this->withHeader('Idempotency-Key', 'routed-expense-delete')->deleteJson("{$base}/{$expense['id']}", ['expected_version' => $updated['version']])->assertOk()->json('data'));
    }

    public function test_time_writes_answer_as_the_rest_route_and_share_its_receipts(): void
    {
        config(['agent_api.time_entry_writes_enabled' => true]);
        [$workspace, $project] = $this->fixture();
        $owner = $this->member($workspace, 'owner');
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::TIME_READ, AgentApiScopes::TIME_WRITE, AgentApiScopes::TIME_APPROVE]);
        $session = $this->initialize();
        $base = "/api/v1/workspaces/{$workspace->public_id}/time-entries";
        $entry = ['project_id' => $project->public_id, 'worked_on' => '2026-10-07', 'minutes' => 45, 'description' => 'Synthetic routed time', 'billing_rate_amount' => 15000, 'currency' => 'USD'];

        // With time:read the rows are time_entries.list's: the same as REST's read view of the receipt.
        $logged = $this->data($this->callTool($session, 'time_entries.log', ['workspace_id' => $workspace->public_id, 'entries' => [$entry], 'idempotency_key' => 'routed-time-log']));
        $this->assertSame(15000, $logged[0]['billing_rate_amount']);
        $this->assertSame($this->withHeader('Idempotency-Key', 'routed-time-log')->postJson("{$base}?view=read", ['entries' => [$entry]])->assertCreated()->json('data'), $logged);
        $plain = $this->withHeader('Idempotency-Key', 'routed-time-log')->postJson($base, ['entries' => [$entry]])->assertCreated()->json('data');
        $this->assertArrayNotHasKey('billing_rate_amount', $plain[0], 'without the view REST answers with the write shape');
        $this->assertSame(1, ClientTimeEntry::query()->where('workspace_id', $workspace->id)->count());

        // Any other view is refused before anything is written.
        $this->withHeader('Idempotency-Key', 'routed-time-other-view')->postJson("{$base}?view=list", ['entries' => [$entry]])->assertUnprocessable()->assertJsonValidationErrors('view');
        // So is a view sent as a field, where it would otherwise pass unnoticed.
        $this->withHeader('Idempotency-Key', 'routed-time-body-view')->postJson($base, ['entries' => [$entry], 'view' => 'read'])->assertUnprocessable()->assertJsonValidationErrors('view');
        $this->withHeader('Idempotency-Key', 'routed-time-both-views')->postJson("{$base}?view=read", ['entries' => [$entry], 'view' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('view');
        $this->assertSame(1, ClientTimeEntry::query()->where('workspace_id', $workspace->id)->count());

        $time = $logged[0];
        $updated = $this->data($this->callTool($session, 'time_entries.update', ['workspace_id' => $workspace->public_id, 'entry_id' => $time['id'], 'expected_version' => $time['version'], 'minutes' => 50, 'idempotency_key' => 'routed-time-update']));
        $this->assertSame([50, 'Synthetic routed time'], [$updated['minutes'], $updated['description']]);
        $stale = $this->withHeader('Idempotency-Key', 'routed-time-stale-rest')->deleteJson("{$base}/{$time['id']}", ['expected_version' => $time['version']])->assertConflict();
        $this->assertRefusal(AgentMcpApiFailure::message(409, $stale->json()), $this->callTool($session, 'time_entries.delete', ['workspace_id' => $workspace->public_id, 'entry_id' => $time['id'], 'expected_version' => $time['version'], 'idempotency_key' => 'routed-time-stale']));
        $deleted = $this->data($this->callTool($session, 'time_entries.delete', ['workspace_id' => $workspace->public_id, 'entry_id' => $time['id'], 'expected_version' => $updated['version'], 'idempotency_key' => 'routed-time-delete']));
        $this->assertSame(['deleted_id' => $time['id']], $deleted);
    }

    public function test_the_read_view_follows_the_list_tools_switch_and_scope(): void
    {
        config(['agent_api.time_entry_writes_enabled' => true]);
        [$workspace, $project] = $this->fixture();
        $owner = $this->member($workspace, 'owner');
        $entry = ['project_id' => $project->public_id, 'worked_on' => '2026-10-07', 'minutes' => 30, 'description' => 'Synthetic plain time'];

        // Without time:read, the route answers with the write shape even when MCP asks for the view.
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::TIME_WRITE]);
        $withoutScope = $this->data($this->callTool($this->initialize(), 'time_entries.log', ['workspace_id' => $workspace->public_id, 'entries' => [$entry], 'idempotency_key' => 'plain-time-scope']));
        $this->assertArrayNotHasKey('currency', $withoutScope[0]);

        // With time_entries.list switched off, MCP does not ask for it.
        config(['agent_api.mcp_feature_flags' => ['time_entries.list' => false]]);
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::TIME_READ, AgentApiScopes::TIME_WRITE]);
        $switchedOff = $this->data($this->callTool($this->initialize(), 'time_entries.log', ['workspace_id' => $workspace->public_id, 'entries' => [$entry], 'idempotency_key' => 'plain-time-switch']));
        $this->assertArrayNotHasKey('currency', $switchedOff[0]);

        config(['agent_api.mcp_feature_flags' => []]);
        $listed = $this->data($this->callTool($this->initialize(), 'time_entries.log', ['workspace_id' => $workspace->public_id, 'entries' => [$entry], 'idempotency_key' => 'listed-time']));
        $this->assertArrayHasKey('currency', $listed[0]);
    }

    public function test_approval_folded_into_a_log_answers_to_the_approve_tools_switch(): void
    {
        config(['agent_api.time_entry_writes_enabled' => true, 'agent_api.mcp_feature_flags' => ['time_entries.approve' => false]]);
        [$workspace, $project, $manager] = $this->fixture();
        $this->actingAsMcp($manager, [AgentApiScopes::MCP_USE, AgentApiScopes::TIME_WRITE, AgentApiScopes::TIME_APPROVE]);
        $arguments = ['workspace_id' => $workspace->public_id, 'idempotency_key' => 'switched-approval', 'approve' => true, 'entries' => [['project_id' => $project->public_id, 'worked_on' => '2026-10-07', 'minutes' => 30, 'description' => 'Synthetic approval']]];

        $this->assertRefusal('Approving time is not enabled for agents.', $this->callTool($this->initialize(), 'time_entries.log', $arguments));
        $this->assertSame(0, ClientTimeEntry::query()->count());
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_a_routed_call_is_counted_once_against_the_principals_throttle(): void
    {
        [$workspace, $project, $manager] = $this->fixture();
        $this->actingAsMcp($manager, [AgentApiScopes::MCP_USE, AgentApiScopes::TASKS_WRITE]);
        $key = sha1((string) $manager->getAuthIdentifier());
        RateLimiter::clear($key);

        $session = $this->initialize();
        $this->data($this->callTool($session, 'tasks.create', ['workspace_id' => $workspace->public_id, 'project_id' => $project->public_id, 'title' => 'Synthetic counted task', 'idempotency_key' => 'counted-task']));
        $this->assertSame(2, RateLimiter::attempts($key), 'initialize and the tool call, not the nested REST request');

        $this->withHeader('Idempotency-Key', 'counted-task-rest')->postJson("/api/v1/workspaces/{$workspace->public_id}/projects/{$project->public_id}/tasks", ['title' => 'Synthetic REST task'])->assertCreated();
        $this->assertSame(3, RateLimiter::attempts($key), 'a REST client is still counted');
    }

    /** @param array<string, mixed> $body
     * @return array<string, mixed> */
    private function data(array $body): array
    {
        $this->assertFalse($body['result']['isError'] ?? true, 'Expected success, got: '.json_encode($body));

        return $body['result']['structuredContent']['data'];
    }

    /** A refusal the caller can act on is a tool error, never the SDK's generic internal error.
     * @param array<string, mixed> $body */
    private function assertRefusal(string $message, array $body): void
    {
        $this->assertArrayNotHasKey('error', $body, json_encode($body, JSON_THROW_ON_ERROR));
        $this->assertTrue($body['result']['isError'] ?? false, json_encode($body, JSON_THROW_ON_ERROR));
        $this->assertSame($message, $body['result']['content'][0]['text'] ?? null);
    }

    private function member(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create(['email' => 'synthetic-'.Str::uuid().'@example.test']);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /** @return array{Workspace, ClientProject, User} */
    private function fixture(string $projectRole = 'manager'): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic routed workspace', 'slug' => 'synthetic-'.Str::uuid(), 'default_currency' => 'USD']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic routed client', 'slug' => 'synthetic-'.Str::uuid()]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic routed project']);
        $user = User::factory()->create(['email' => 'synthetic-'.Str::uuid().'@example.test']);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $user->id, 'role' => $projectRole]);

        return [$workspace, $project, $user];
    }
}
