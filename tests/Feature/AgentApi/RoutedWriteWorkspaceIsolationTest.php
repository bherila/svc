<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentMutationAudit;
use App\Models\AgentMutationReceipt;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * The writes MCP now reaches over REST (#459) refuse a workspace the caller
 * is not in as not found, before their actions run: the same answer as for a
 * workspace that does not exist, and nothing - not even a failure audit - is
 * written into the other tenant.
 */
final class RoutedWriteWorkspaceIsolationTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private const array SCOPES = [
        AgentApiScopes::MCP_USE,
        AgentApiScopes::TASKS_WRITE,
        AgentApiScopes::TIME_READ,
        AgentApiScopes::TIME_WRITE,
        AgentApiScopes::EXPENSES_WRITE,
        AgentApiScopes::BILLING_READ,
        AgentApiScopes::BILLING_DELIVER,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'agent_api.writes_enabled' => true,
            'agent_api.time_entry_writes_enabled' => true,
            'agent_api.expense_writes_enabled' => true,
            'agent_api.invoice_writes_enabled' => true,
        ]);
    }

    public function test_a_foreign_workspace_is_not_found_over_rest_and_mcp_and_nothing_is_written_there(): void
    {
        $caller = User::factory()->create();
        $own = Workspace::query()->create(['name' => 'Synthetic own workspace', 'slug' => 'synthetic-'.Str::uuid()]);
        $own->memberships()->create(['user_id' => $caller->id, 'role' => 'owner']);
        $foreign = Workspace::query()->create(['name' => 'Synthetic foreign workspace', 'slug' => 'synthetic-'.Str::uuid()]);
        $foreignOwner = User::factory()->create();
        $foreign->memberships()->create(['user_id' => $foreignOwner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $foreign->id, 'name' => 'Synthetic foreign client', 'slug' => 'synthetic-'.Str::uuid()]);
        $project = ClientProject::query()->create(['workspace_id' => $foreign->id, 'client_company_id' => $company->id, 'name' => 'Synthetic foreign project']);
        $task = ClientTask::query()->create(['workspace_id' => $foreign->id, 'client_project_id' => $project->id, 'title' => 'Synthetic foreign task', 'status' => 'open']);
        $version = AgentApiVersion::for($task);
        $unknown = (string) Str::uuid();
        $entry = ['project_id' => $project->public_id, 'worked_on' => now()->toDateString(), 'minutes' => 15, 'description' => 'Synthetic foreign time'];
        $expense = ['company_id' => $company->public_id, 'spent_on' => now()->toDateString(), 'amount' => 100, 'currency' => 'USD', 'description' => 'Synthetic foreign expense'];
        $writes = [
            'tasks.create' => ['POST', "projects/{$project->public_id}/tasks", ['title' => 'Synthetic intrusion'], ['project_id' => $project->public_id, 'title' => 'Synthetic intrusion']],
            'tasks.update' => ['PATCH', "tasks/{$task->public_id}", ['expected_version' => $version, 'status' => 'completed'], ['task_id' => $task->public_id, 'expected_version' => $version, 'status' => 'completed']],
            'time_entries.log' => ['POST', 'time-entries', ['entries' => [$entry]], ['entries' => [$entry]]],
            'time_entries.update' => ['PATCH', "time-entries/{$unknown}", ['expected_version' => $version, 'minutes' => 20], ['entry_id' => $unknown, 'expected_version' => $version, 'minutes' => 20]],
            'time_entries.delete' => ['DELETE', "time-entries/{$unknown}", ['expected_version' => $version], ['entry_id' => $unknown, 'expected_version' => $version]],
            'expenses.log' => ['POST', 'expenses', ['entries' => [$expense]], ['entries' => [$expense]]],
            'expenses.update' => ['PATCH', "expenses/{$unknown}", ['expected_version' => $version, ...array_diff_key($expense, ['company_id' => true])], ['expense_id' => $unknown, 'expected_version' => $version, ...array_diff_key($expense, ['company_id' => true])]],
            'expenses.delete' => ['DELETE', "expenses/{$unknown}", ['expected_version' => $version], ['expense_id' => $unknown, 'expected_version' => $version]],
            'invoices.issue' => ['POST', "invoices/{$unknown}/issue", ['expected_version' => $version, 'confirm' => true], ['invoice_id' => $unknown, 'expected_version' => $version, 'confirm' => true]],
        ];

        $this->actingAsMcp($caller, self::SCOPES);
        $session = $this->initialize();
        foreach ($writes as $tool => [$method, $path, $body, $arguments]) {
            foreach ([$foreign->public_id, (string) Str::uuid()] as $workspace) {
                $this->withHeader('Idempotency-Key', "rest-{$tool}-{$workspace}")
                    ->json($method, "/api/v1/workspaces/{$workspace}/{$path}", $body)
                    ->assertNotFound();
            }
            $result = $this->callTool($session, $tool, ['workspace_id' => $foreign->public_id, 'idempotency_key' => "mcp-{$tool}", ...$arguments]);
            $this->assertTrue($result['result']['isError'] ?? false, $tool.': '.json_encode($result));
            $this->assertSame('The SVC API request could not be completed.', $result['result']['content'][0]['text'], $tool);
        }

        $this->assertSame(0, AgentMutationAudit::query()->where('workspace_id', $foreign->id)->count());
        $this->assertSame(0, AgentMutationReceipt::query()->where('workspace_id', $foreign->id)->count());
        $this->assertSame(['open', 1], [$task->fresh()->status, ClientTask::query()->where('workspace_id', $foreign->id)->count()]);
        $this->assertDatabaseCount('client_time_entries', 0);
        $this->assertDatabaseCount('client_expenses', 0);
    }
}
