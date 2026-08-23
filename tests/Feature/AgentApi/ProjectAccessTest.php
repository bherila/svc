<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\ProjectRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_contributor_can_view_but_cannot_manage_tasks_or_approve_time(): void
    {
        [$workspace, $project] = $this->project();
        $user = User::factory()->create();
        $this->workspaceMember($workspace, $user, 'member');
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'user_id' => $user->id,
            'role' => ProjectRole::Contributor,
        ]);

        $this->assertSame(ProjectRole::Contributor, app(ProjectAccess::class)->projectRole($user, $project));
        $this->assertTrue(Gate::forUser($user)->allows('view', $project));
        $this->assertFalse(Gate::forUser($user)->allows('manageTasks', $project));
        $this->assertFalse(Gate::forUser($user)->allows('approveTime', $project));
    }

    public function test_project_manager_can_manage_tasks_and_approve_time(): void
    {
        [$workspace, $project] = $this->project();
        $user = User::factory()->create();
        $this->workspaceMember($workspace, $user, 'member');
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'user_id' => $user->id,
            'role' => ProjectRole::Manager,
        ]);

        $this->assertTrue(Gate::forUser($user)->allows('manageTasks', $project));
        $this->assertTrue(Gate::forUser($user)->allows('approveTime', $project));
    }

    public function test_workspace_admin_has_project_access_without_an_explicit_membership(): void
    {
        [$workspace, $project] = $this->project();
        $user = User::factory()->create();
        $this->workspaceMember($workspace, $user, 'admin');

        $this->assertTrue(Gate::forUser($user)->allows('view', $project));
        $this->assertTrue(Gate::forUser($user)->allows('manageTasks', $project));
        $this->assertTrue(Gate::forUser($user)->allows('approveTime', $project));
    }

    public function test_removing_workspace_membership_cascades_all_project_roles(): void
    {
        [$workspace, $project] = $this->project();

        foreach (ProjectRole::cases() as $role) {
            $user = User::factory()->create();
            $membership = WorkspaceMembership::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => 'member',
            ]);
            ClientProjectMembership::query()->create([
                'workspace_id' => $workspace->id,
                'client_project_id' => $project->id,
                'user_id' => $user->id,
                'role' => $role,
            ]);

            $membership->delete();

            $this->assertDatabaseMissing('client_project_memberships', [
                'client_project_id' => $project->id,
                'user_id' => $user->id,
            ]);
            $this->assertNull(app(ProjectAccess::class)->projectRole($user, $project));
        }
    }

    public function test_removed_workspace_manager_cannot_read_or_mutate_the_former_project(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$workspace, $project] = $this->project();
        $manager = User::factory()->create();
        $this->workspaceMember($workspace, $manager, 'member');
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'user_id' => $manager->id,
            'role' => ProjectRole::Manager,
        ]);
        $task = ClientTask::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'title' => 'Existing task',
        ]);
        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $project->client_company_id,
            'client_project_id' => $project->id,
            'user_id' => $manager->id,
            'worked_on' => '2026-08-23',
            'minutes' => 30,
            'description' => 'Existing time',
            'currency' => 'USD',
        ]);

        WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $manager->id)
            ->delete();

        Passport::actingAs(AgentPrincipal::query()->findOrFail($manager->id), [
            AgentApiScopes::PROJECTS_READ,
            AgentApiScopes::TASKS_WRITE,
            AgentApiScopes::TIME_WRITE,
            AgentApiScopes::TIME_APPROVE,
        ]);

        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/projects/{$project->public_id}")
            ->assertNotFound();
        $this->withHeader('Idempotency-Key', 'removed-member-task-create')->postJson("/api/v1/workspaces/{$workspace->public_id}/projects/{$project->public_id}/tasks", ['title' => 'Unauthorized'])
            ->assertForbidden();
        $this->withHeader('Idempotency-Key', 'removed-member-task-update')->patchJson("/api/v1/workspaces/{$workspace->public_id}/tasks/{$task->public_id}", [
            'expected_version' => AgentApiVersion::for($task),
            'title' => 'Unauthorized',
        ])->assertForbidden();
        $this->withHeader('Idempotency-Key', 'removed-member-time')->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries", [
            'entries' => [[
                'project_id' => $project->public_id,
                'worked_on' => '2026-08-23',
                'minutes' => 30,
                'description' => 'Unauthorized',
            ]],
        ])->assertNotFound();
        $this->withHeader('Idempotency-Key', 'removed-member-time-update')->patchJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/{$entry->public_id}", [
            'expected_version' => AgentApiVersion::for($entry),
            'minutes' => 60,
        ])->assertNotFound();
        $this->withHeader('Idempotency-Key', 'removed-member-time-delete')->deleteJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/{$entry->public_id}", [
            'expected_version' => AgentApiVersion::for($entry),
        ])->assertNotFound();
        $this->withHeader('Idempotency-Key', 'removed-member-time-approve')->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/approve", [
            'entries' => [[
                'id' => $entry->public_id,
                'expected_version' => AgentApiVersion::for($entry),
            ]],
        ])->assertForbidden();

        $this->assertDatabaseHas('client_tasks', ['id' => $task->id, 'title' => 'Existing task']);
        $this->assertDatabaseHas('client_time_entries', ['id' => $entry->id, 'minutes' => 30, 'status' => 'draft']);
    }

    /** @return array{Workspace, ClientProject} */
    private function project(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Example Client',
            'slug' => 'example-client',
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Example Project',
        ]);

        return [$workspace, $project];
    }

    private function workspaceMember(Workspace $workspace, User $user, string $role): void
    {
        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
    }
}
