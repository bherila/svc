<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\ProjectRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
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
