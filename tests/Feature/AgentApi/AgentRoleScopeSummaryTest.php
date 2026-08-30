<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Mcp\Capability\Discovery\SchemaValidator;
use Tests\TestCase;

final class AgentRoleScopeSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_manager_discovers_and_approves_team_time_only_in_managed_projects(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$workspace, $company, $managed] = $this->project('Managed');
        $contributed = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Contributed']);
        $manager = User::factory()->create();
        $worker = User::factory()->create();
        $this->member($workspace, $manager);
        $this->member($workspace, $worker);
        $this->projectMember($workspace, $managed, $manager, 'manager');
        $this->projectMember($workspace, $contributed, $manager, 'contributor');
        $this->projectMember($workspace, $managed, $worker, 'contributor');
        $this->projectMember($workspace, $contributed, $worker, 'contributor');
        $managedTeam = $this->time($workspace, $company, $managed, $worker, 30, 'Managed team');
        $contributedTeam = $this->time($workspace, $company, $contributed, $worker, 45, 'Other team');
        $own = $this->time($workspace, $company, $contributed, $manager, 20, 'Own contribution');

        $this->actingAsAgent($manager, [AgentApiScopes::TIME_READ]);
        $ids = $this->getJson("/api/v1/workspaces/{$workspace->public_id}/time-entries")
            ->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$managedTeam->public_id, $own->public_id], array_column($ids, 'id'));

        $this->actingAsAgent($manager, [AgentApiScopes::TIME_APPROVE]);
        $this->withHeader('Idempotency-Key', 'manager-approve-managed')->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/approve", ['entries' => [[
            'id' => $managedTeam->public_id,
            'expected_version' => AgentApiVersion::for($managedTeam),
            'billing_rate_amount' => 10000,
            'currency' => 'USD',
        ]]])->assertOk();
        $this->withHeader('Idempotency-Key', 'manager-approve-contributed')->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/approve", ['entries' => [[
            'id' => $contributedTeam->public_id,
            'expected_version' => AgentApiVersion::for($contributedTeam),
            'billing_rate_amount' => 10000,
            'currency' => 'USD',
        ]]])->assertForbidden();
    }

    public function test_viewer_has_project_reads_but_no_time_visibility_or_write_capability(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$workspace, $company, $project] = $this->project('Viewed');
        $viewer = User::factory()->create();
        $worker = User::factory()->create();
        $this->member($workspace, $viewer);
        $this->member($workspace, $worker);
        $this->projectMember($workspace, $project, $viewer, 'viewer');
        $this->time($workspace, $company, $project, $worker, 30, 'Not for viewer');
        $this->actingAsAgent($viewer, [
            AgentApiScopes::IDENTITY_READ,
            AgentApiScopes::PROJECTS_READ,
            AgentApiScopes::TASKS_READ,
            AgentApiScopes::TIME_READ,
            AgentApiScopes::TIME_WRITE,
        ]);

        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/time-entries")
            ->assertOk()->assertJsonCount(0, 'data');
        $context = $this->getJson('/api/v1/context')->assertOk()->json('data.workspaces.0');
        $this->assertSame(['projects:read', 'tasks:read'], $context['capabilities']);
        $this->assertSame('viewer', $context['project_capabilities'][0]['role']);
        $this->assertSame(['projects:read', 'tasks:read'], $context['project_capabilities'][0]['capabilities']);
    }

    public function test_context_capabilities_intersect_token_scope_and_project_role(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$workspace, , $project] = $this->project('Managed');
        $manager = User::factory()->create();
        $this->member($workspace, $manager);
        $this->projectMember($workspace, $project, $manager, 'manager');
        $this->actingAsAgent($manager, [
            AgentApiScopes::IDENTITY_READ,
            AgentApiScopes::PROJECTS_READ,
            AgentApiScopes::TASKS_WRITE,
            AgentApiScopes::TIME_READ,
            AgentApiScopes::TIME_WRITE,
            AgentApiScopes::TIME_APPROVE,
            AgentApiScopes::BILLING_WRITE,
        ]);

        $workspaceContext = $this->getJson('/api/v1/context')->assertOk()->json('data.workspaces.0');
        $this->assertSame(['projects:read', 'tasks:write', 'time:read', 'time:write', 'time:approve'], $workspaceContext['capabilities']);
        $this->assertSame($project->public_id, $workspaceContext['project_capabilities'][0]['project_id']);
        $this->assertSame('manager', $workspaceContext['project_capabilities'][0]['role']);
        $this->assertSame(['projects:read', 'tasks:write', 'time:read', 'time:write', 'time:approve'], $workspaceContext['project_capabilities'][0]['capabilities']);
        $this->assertNotContains('billing:write', $workspaceContext['capabilities']);

        config(['agent_api.time_entry_writes_enabled' => false]);
        $timeWritesDisabled = $this->getJson('/api/v1/context')->assertOk()->json('data.workspaces.0');
        $this->assertSame(['projects:read', 'tasks:write', 'time:read', 'time:approve'], $timeWritesDisabled['capabilities']);
        $this->assertSame(['projects:read', 'tasks:write', 'time:read', 'time:approve'], $timeWritesDisabled['project_capabilities'][0]['capabilities']);
    }

    public function test_project_detail_embeds_tasks_only_with_task_scope(): void
    {
        [$workspace, , $project] = $this->project('Scoped project');
        $owner = User::factory()->create();
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $task = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Scoped task']);

        $this->actingAsAgent($owner, [AgentApiScopes::PROJECTS_READ]);
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/projects/{$project->public_id}")
            ->assertOk()->assertJsonMissingPath('data.tasks');

        $this->actingAsAgent($owner, [AgentApiScopes::PROJECTS_READ, AgentApiScopes::TASKS_READ]);
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/projects/{$project->public_id}")
            ->assertOk()->assertJsonPath('data.tasks.0.id', $task->public_id);
    }

    public function test_summary_omits_unscoped_sections_and_reports_decision_safe_currency_totals(): void
    {
        [$workspace, $company, $project] = $this->project('Summary project');
        $owner = User::factory()->create();
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $this->time($workspace, $company, $project, $owner, 15, 'Draft');
        $this->time($workspace, $company, $project, $owner, 30, 'Ready', ['status' => 'approved', 'billing_rate_amount' => 10000]);
        $this->time($workspace, $company, $project, $owner, 40, 'Deferred', ['status' => 'approved', 'is_deferred' => true, 'billing_rate_amount' => 10000]);
        $this->time($workspace, $company, $project, $owner, 50, 'Nonbillable', ['status' => 'approved', 'is_billable' => false]);
        $this->time($workspace, $company, $project, $owner, 20, 'Flat subcontractor', [
            'status' => 'approved',
            'subcontractor_billing_mode' => 'flat_hourly',
            'subcontractor_cost_amount' => 8000,
            'subcontractor_cost_currency' => 'USD',
        ]);
        $this->time($workspace, $company, $project, $owner, 70, 'Direct subcontractor', [
            'status' => 'approved',
            'billing_rate_amount' => 10000,
            'subcontractor_billing_mode' => 'direct',
        ]);
        $allocated = $this->time($workspace, $company, $project, $owner, 60, 'Allocated', ['status' => 'approved', 'billing_rate_amount' => 10000]);

        $draftUsd = $this->invoice($workspace, $company, 'D-USD', 'draft', 'USD', 100, 100);
        $this->invoice($workspace, $company, 'D-EUR', 'draft', 'EUR', 200, 200);
        $line = $draftUsd->lines()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'type' => 'time', 'description' => 'Allocated', 'quantity' => '1', 'unit_amount' => 100, 'tax_amount' => 0, 'total_amount' => 100, 'sort_order' => 0]);
        $line->timeEntries()->attach($allocated->id, ['workspace_id' => $workspace->id]);
        $this->invoice($workspace, $company, 'I-USD', 'issued', 'USD', 300, 300, now()->subDay()->toDateString());
        $this->invoice($workspace, $company, 'I-EUR-FUTURE', 'partially_paid', 'EUR', 500, 400, now()->addDay()->toDateString());
        $this->invoice($workspace, $company, 'I-EUR-OVERDUE', 'issued', 'EUR', 50, 50, now()->subDay()->toDateString());
        $this->invoice($workspace, $company, 'PAID', 'paid', 'USD', 900, 0, now()->subDay()->toDateString());

        $this->actingAsAgent($owner, [AgentApiScopes::IDENTITY_READ]);
        $scopedOut = $this->getJson("/api/v1/workspaces/{$workspace->public_id}/summary")
            ->assertOk()->assertExactJson(['data' => ['workspace_id' => $workspace->public_id]])->json();
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema(
            $scopedOut,
            AgentApiResponseSchemaCatalog::forOperation('operations.summary'),
        ));

        $this->actingAsAgent($owner, [AgentApiScopes::IDENTITY_READ, AgentApiScopes::PROJECTS_READ, AgentApiScopes::TIME_READ, AgentApiScopes::BILLING_READ]);
        $response = $this->getJson("/api/v1/workspaces/{$workspace->public_id}/summary")->assertOk()->json();
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema(
            $response,
            AgentApiResponseSchemaCatalog::forOperation('operations.summary'),
        ));
        $summary = $response['data'];
        $this->assertSame(1, $summary['active_projects']);
        $this->assertSame([
            'draft_minutes' => 15,
            'approved_billable_unallocated_minutes' => 50,
            'allocated_to_draft_minutes' => 60,
        ], $summary['time']);
        $this->assertSame(2, $summary['invoices']['draft_count']);
        $this->assertSame(2, $summary['invoices']['overdue_count']);
        $this->assertSame([['currency' => 'EUR', 'amount' => 200], ['currency' => 'USD', 'amount' => 100]], $summary['invoices']['draft_amounts']);
        $this->assertSame([['currency' => 'EUR', 'amount' => 450], ['currency' => 'USD', 'amount' => 300]], $summary['invoices']['collectible_balances']);
        $this->assertSame([['currency' => 'EUR', 'amount' => 50], ['currency' => 'USD', 'amount' => 300]], $summary['invoices']['overdue_balances']);
    }

    /** @return array{Workspace,ClientCompany,ClientProject} */
    private function project(string $name): array
    {
        $workspace = Workspace::query()->create(['name' => 'Role Scope', 'slug' => 'role-scope-'.strtolower(str_replace(' ', '-', $name))]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Client', 'slug' => 'client-'.strtolower(str_replace(' ', '-', $name))]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => $name]);

        return [$workspace, $company, $project];
    }

    private function member(Workspace $workspace, User $user): void
    {
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'member']);
    }

    private function projectMember(Workspace $workspace, ClientProject $project, User $user, string $role): void
    {
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $user->id, 'role' => $role]);
    }

    /** @param array<string,mixed> $extra */
    private function time(Workspace $workspace, ClientCompany $company, ClientProject $project, User $user, int $minutes, string $description, array $extra = []): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create($extra + [
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => $user->id,
            'worked_on' => '2026-08-23',
            'minutes' => $minutes,
            'description' => $description,
            'is_billable' => true,
            'is_deferred' => false,
            'currency' => 'USD',
        ]);
    }

    private function invoice(Workspace $workspace, ClientCompany $company, string $number, string $status, string $currency, int $total, int $balance, ?string $dueDate = null): ClientInvoice
    {
        return ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'invoice_number' => $number,
            'status' => $status,
            'currency' => $currency,
            'subtotal_amount' => $total,
            'total_amount' => $total,
            'balance_amount' => $balance,
            'due_date' => $dueDate,
        ]);
    }

    /** @param list<string> $scopes */
    private function actingAsAgent(User $user, array $scopes): void
    {
        Passport::actingAs(AgentPrincipal::query()->findOrFail($user->id), $scopes);
    }
}
