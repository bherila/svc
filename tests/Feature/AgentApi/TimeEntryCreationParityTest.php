<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentMutationAudit;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class TimeEntryCreationParityTest extends TestCase
{
    use RefreshDatabase, WritesLegacyCrossTenantRows;

    public static function validRates(): iterable
    {
        yield 'manager explicit rate' => ['manager', 12500, true, 'EUR'];
        yield 'manager explicit zero' => ['manager', 0, true, null];
        yield 'manager nonbillable' => ['manager', 12500, false, null];
        yield 'contributor default rate' => ['contributor', null, true, null];
        yield 'contributor nonbillable' => ['contributor', null, false, 'EUR'];
    }

    #[DataProvider('validRates')]
    public function test_web_rest_and_mcp_create_the_same_time(string $role, ?int $rate, bool $billable, ?string $currency): void
    {
        [$user, $workspace, $project, $task] = $this->fixture($role);
        $payload = $this->payload($project, $task) + [
            'billing_rate_amount' => $rate,
            'currency' => $currency,
            'is_billable' => $billable,
            'is_deferred' => true,
            'is_visible_to_client' => true,
            'client_visible_description' => 'Synthetic client-facing work',
        ];
        foreach (['web', 'rest', 'mcp'] as $transport) {
            $this->submit($transport, $user, $workspace, $project, $payload);
            $entry = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->latest('id')->firstOrFail();
            $this->assertSame($workspace->id, $entry->workspace_id);
            $this->assertSame($project->client_company_id, $entry->client_company_id);
            $this->assertSame($project->id, $entry->client_project_id);
            $this->assertSame($task->id, $entry->client_task_id);
            $this->assertSame($user->id, $entry->user_id);
            $this->assertSame(30, $entry->minutes);
            $this->assertSame($rate, $entry->billing_rate_amount);
            $this->assertSame($rate === null ? null : 'explicit', $entry->billing_rate_source);
            $this->assertSame($currency ?? 'CAD', $entry->currency);
            $this->assertSame($billable, $entry->is_billable);
            $this->assertTrue($entry->is_deferred);
            $this->assertTrue($entry->is_visible_to_client);
            $this->assertSame('Synthetic client-facing work', $entry->client_visible_description);
            $this->assertSame('draft', $entry->status);
        }
        $this->assertDatabaseCount('client_time_entries', 3);
    }

    public static function deniedRates(): iterable
    {
        yield 'positive' => [99999, true];
        yield 'zero' => [0, true];
        yield 'nonbillable' => [99999, false];
    }

    #[DataProvider('deniedRates')]
    public function test_contributors_cannot_price_time_through_any_transport(int $rate, bool $billable): void
    {
        [$user, $workspace, $project, $task] = $this->fixture('contributor');
        foreach (['web', 'rest', 'mcp'] as $transport) {
            $this->submit($transport, $user, $workspace, $project, $this->payload($project, $task) + [
                'billing_rate_amount' => $rate, 'is_billable' => $billable,
            ], 422);
        }
        $this->assertDatabaseCount('client_time_entries', 0);
    }

    public function test_visible_time_requires_a_client_description_on_every_transport(): void
    {
        [$user, $workspace, $project, $task] = $this->fixture('manager');
        foreach (['web', 'rest', 'mcp'] as $transport) {
            $this->submit($transport, $user, $workspace, $project, $this->payload($project, $task) + [
                'is_visible_to_client' => true, 'client_visible_description' => ' ',
            ], 422, 'domain');
        }
        $this->assertDatabaseCount('client_time_entries', 0);
    }

    public function test_foreign_projects_and_tasks_are_not_resolved_on_any_transport(): void
    {
        [$user, $workspace, $project, $task] = $this->fixture('manager');
        [, $foreignWorkspace, $foreignProject, $foreignTask] = $this->fixture('manager');
        // Even membership in both tenants must not make mixed tenant IDs valid.
        $foreignWorkspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
        foreach (['web', 'rest', 'mcp'] as $transport) {
            $this->submit($transport, $user, $workspace, $foreignProject, $this->payload($foreignProject, $foreignTask), 404, 'not_found');
            $this->submit($transport, $user, $workspace, $project, $this->payload($project, $foreignTask), 404, 'not_found');
        }
        $this->assertDatabaseCount('client_time_entries', 0);
    }

    public function test_a_task_from_another_project_is_refused_on_every_transport(): void
    {
        [$user, $workspace, $project, $task] = $this->fixture('manager');
        $otherProject = ClientProject::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id, 'name' => 'Other synthetic project',
        ]);
        $task->forceFill(['client_project_id' => $otherProject->id])->save();
        foreach (['web', 'rest', 'mcp'] as $transport) {
            $this->submit($transport, $user, $workspace, $project, $this->payload($project, $task), 422, 'domain');
        }
        $this->assertDatabaseCount('client_time_entries', 0);
    }

    public function test_a_project_cannot_create_time_for_a_foreign_client_company(): void
    {
        [$user, $workspace, $project, $task] = $this->fixture('manager');
        [, , $foreignProject] = $this->fixture('manager');
        $this->writingLegacyCrossTenantRows(fn () => $project->forceFill(['client_company_id' => $foreignProject->client_company_id, 'name' => 'Synthetic malformed project'])->save());
        $companyReads = [];
        DB::listen(function (QueryExecuted $query) use (&$companyReads): void {
            $sql = str_replace(['`', '"', '[', ']'], '', $query->sql);
            // Inspect materialized company reads; workspace-resolution EXISTS subqueries correlate their own scope.
            if (preg_match('/^select\s+[^()]+\s+from\s+client_companies\b/i', $sql)) {
                $companyReads[] = $sql;
            }
        });
        foreach (['web', 'rest', 'mcp'] as $transport) {
            $this->submit($transport, $user, $workspace, $project, $this->payload($project, $task), 422, 'domain');
        }
        $this->assertNotEmpty($companyReads);
        foreach ($companyReads as $sql) {
            $this->assertMatchesRegularExpression('/\bwhere\b.*\bworkspace_id\s*=\s*\?/i', $sql, $sql);
        }
        $this->assertDatabaseCount('client_time_entries', 0);
    }

    /** @return array<string, mixed> */
    private function payload(ClientProject $project, ClientTask $task): array
    {
        return ['project_id' => $project->public_id, 'task_id' => $task->public_id,
            'worked_on' => now()->toDateString(), 'minutes' => 30, 'description' => 'Synthetic internal work'];
    }

    /** @param array<string, mixed> $payload */
    private function submit(string $transport, User $user, Workspace $workspace, ClientProject $project, array $payload, int $expected = 201, string $expectedCategory = 'validation'): void
    {
        if ($transport === 'web') {
            $this->actingAs($user, 'web')->postJson("/workspaces/{$workspace->public_id}/projects/{$project->public_id}/time-entries", $payload)->assertStatus($expected);

            return;
        }
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE, AgentApiScopes::TIME_WRITE]);
        if ($transport === 'rest') {
            $this->withHeader('Idempotency-Key', 'synthetic-'.str()->uuid())->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries", ['entries' => [$payload]])->assertStatus($expected);

            return;
        }
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        $init = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic parity test', 'version' => '1']],
        ], $headers)->assertOk();
        $headers['Mcp-Session-Id'] = $init->headers->get('Mcp-Session-Id');
        $result = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'time_entries.log', 'arguments' => ['workspace_id' => $workspace->public_id,
                'idempotency_key' => 'synthetic-'.str()->uuid(), 'entries' => [$payload]]],
        ], $headers)->assertOk()->json();
        if ($expected !== 201) {
            $this->assertSame(-32603, $result['error']['code'] ?? null, json_encode($result, JSON_THROW_ON_ERROR));
            $audit = AgentMutationAudit::query()->where('workspace_id', $workspace->id)->latest('id')->firstOrFail();
            $this->assertSame('failed', $audit->outcome);
            $this->assertSame($expectedCategory, $audit->error_category);

            return;
        }
        $this->assertArrayHasKey('result', $result, json_encode($result, JSON_THROW_ON_ERROR));
        $result = $result['result'];
        $this->assertSame($expected !== 201, $result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return array{User, Workspace, ClientProject, ClientTask} */
    private function fixture(string $role): array
    {
        config(['agent_api.writes_enabled' => true]);
        $user = User::factory()->create(['email' => 'synthetic-'.str()->uuid().'@example.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic workspace', 'slug' => 'synthetic-'.str()->uuid(), 'default_currency' => 'CAD']);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'member']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $user->id, 'role' => $role]);
        $task = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Synthetic task']);

        return [$user, $workspace, $project, $task];
    }
}
