<?php

namespace Tests\Feature;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SvcFoundationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_a_workspace_and_becomes_its_owner(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/workspaces', ['name' => 'Synthetic Studio'])
            ->assertRedirect(route('workspaces.enter', Workspace::query()->sole()));

        $workspace = Workspace::query()->sole();
        $this->assertSame('synthetic-studio', $workspace->slug);
        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
    }

    public function test_non_member_cannot_create_records_in_another_workspace(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $workspace = $this->workspaceOwnedBy($owner);

        $this->actingAs($outsider)
            ->post("/workspaces/{$workspace->public_id}/clients", ['name' => 'Forbidden Client'])
            ->assertForbidden();

        $this->assertDatabaseCount('client_companies', 0);
    }

    public function test_owner_can_create_company_project_and_task_with_explicit_workspace_scope(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceOwnedBy($owner);

        $this->actingAs($owner)
            ->post("/workspaces/{$workspace->public_id}/clients", [
                'name' => 'Example Client',
                'billing_email' => 'billing@example.test',
            ])->assertRedirect(route('clients.show', [$workspace, ClientCompany::query()->sole()]));

        $company = ClientCompany::query()->sole();
        $this->actingAs($owner)
            ->post("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/projects", [
                'name' => 'Website refresh',
                'description' => 'Synthetic project description.',
                'is_visible_to_client' => true,
            ])->assertRedirect('/');

        $project = ClientProject::query()->sole();
        $this->actingAs($owner)
            ->post("/workspaces/{$workspace->public_id}/projects/{$project->public_id}/tasks", [
                'title' => 'Prepare discovery notes',
                'is_visible_to_client' => true,
            ])->assertRedirect('/');

        $task = ClientTask::query()->sole();
        $this->assertSame($workspace->id, $company->workspace_id);
        $this->assertSame($workspace->id, $project->workspace_id);
        $this->assertSame($workspace->id, $task->workspace_id);

        $this->actingAs($owner)
            ->patch("/workspaces/{$workspace->public_id}/tasks/{$task->public_id}", [
                'status' => 'completed',
                'is_visible_to_client' => true,
            ])->assertRedirect('/');

        $this->assertSame('completed', $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_nested_route_rejects_a_company_from_another_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceOwnedBy($owner, 'First Workspace');
        $otherWorkspace = $this->workspaceOwnedBy($owner, 'Second Workspace');
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Other Client',
            'slug' => 'other-client',
        ]);

        $this->actingAs($owner)
            ->post("/workspaces/{$workspace->public_id}/clients/{$otherCompany->public_id}/projects", [
                'name' => 'Cross-tenant project',
            ])->assertNotFound();

        $this->assertDatabaseCount('client_projects', 0);
    }

    public function test_client_portal_only_exposes_visible_records_to_company_members(): void
    {
        $owner = User::factory()->create();
        $clientUser = User::factory()->create();
        $outsider = User::factory()->create();
        $workspace = $this->workspaceOwnedBy($owner);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Portal Client',
            'slug' => 'portal-client',
        ]);
        $company->portalUsers()->attach($clientUser, ['role' => 'client']);

        $visibleProject = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Visible Project',
            'is_visible_to_client' => true,
        ]);
        $hiddenProject = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Internal Project',
            'is_visible_to_client' => false,
        ]);
        ClientTask::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $visibleProject->id,
            'title' => 'Visible Task',
            'is_visible_to_client' => true,
        ]);
        ClientTask::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $visibleProject->id,
            'title' => 'Internal Task',
            'is_visible_to_client' => false,
        ]);
        ClientTask::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $hiddenProject->id,
            'title' => 'Hidden Project Task',
            'is_visible_to_client' => true,
        ]);

        $this->actingAs($clientUser)
            ->get("/portal/{$company->public_id}/tasks")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/tasks')
                ->has('projects', 1)
                ->where('projects.0.name', 'Visible Project')
                ->has('tasks', 1)
                ->where('tasks.0.title', 'Visible Task'));

        $this->actingAs($outsider)->get("/portal/{$company->public_id}")->assertForbidden();
    }

    private function workspaceOwnedBy(User $owner, string $name = 'Synthetic Workspace'): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
        ]);
        $workspace->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        return $workspace;
    }
}
