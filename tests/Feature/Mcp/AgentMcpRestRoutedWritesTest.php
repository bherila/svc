<?php

namespace Tests\Feature\Mcp;

use App\Models\AgentMutationAudit;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Mcp\AgentMcpApiFailure;
use App\Support\AgentApi\AgentApiScopes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
