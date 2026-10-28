<?php

namespace Tests\Feature\Tasks;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\TaskCreateData;
use App\Services\WorkspaceTaskMutationAction;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\TestCase;

final class TaskWebAccessTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;

    public static function roles(): iterable
    {
        yield 'project owner' => ['owner', true, false, true];
        yield 'project manager' => ['manager', true, false, true];
        yield 'contributor' => ['contributor', true, false, false];
        yield 'viewer' => ['viewer', true, false, false];
        yield 'unassigned staff' => [null, true, false, false];
        yield 'portal only' => [null, false, true, false];
        yield 'dual current internal manager' => ['manager', true, true, true];
    }

    #[DataProvider('roles')]
    public function test_task_editor_and_lookup_require_current_internal_project_management(?string $role, bool $staff, bool $portal, bool $allowed): void
    {
        [$workspace, $company, $project, , $task] = $this->fixture();
        $actor = User::factory()->create();
        if ($staff) {
            $workspace->memberships()->create(['user_id' => $actor->id, 'role' => 'member']);
        }
        if ($role !== null) {
            ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $actor->id, 'role' => $role]);
        }
        if ($portal) {
            ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $actor->id, 'role' => 'client']);
        }
        $this->browser($actor)->get(route('tasks.show', [$workspace, $task]))->assertStatus($allowed ? 200 : 403);
        $this->getJson(route('agent-api.v1.tasks.assignees.index', [$workspace, $project]))->assertStatus($allowed ? 200 : 403);
        if ($allowed) {
            $this->browser($actor)->get(route('tasks.show', [$workspace, $task]))->assertInertia(fn (Assert $page) => $page
                ->where('task.update_action', route('agent-api.v1.tasks.update', [$workspace, $task->public_id]))
                ->where('workspaceNavigation.current_client_id', $company->public_id));
            $this->get(route('clients.project', [$workspace, $company, $project]))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->has('task_management.projects', 1)->where('tasks.0.detail_url', route('tasks.show', [$workspace, $task])));
            $this->native()->patchJson(route('agent-api.v1.tasks.update', [$workspace, $task->public_id]), ['expected_version' => AgentApiVersion::for($task), 'title' => 'Synthetic internal manager edit'])->assertOk();
            $this->assertSame('Synthetic internal manager edit', $task->fresh()->title);
            if (! $portal) {
                $this->actingAsMcp($actor, ['tasks:read', 'projects:read']);
                foreach ([['tasks', 'data.0'], ['tasks/'.$task->public_id, 'data'], ['projects/'.$project->public_id, 'data.tasks.0']] as [$path, $key]) {
                    $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/'.$path)->assertOk()
                        ->assertJsonPath($key.'.web_url', route('tasks.show', [$workspace, $task]));
                }
            }
        }
    }

    public function test_company_task_controls_are_project_scoped_and_have_constant_query_cost(): void
    {
        [$workspace, $company, $project, $owner, $task] = $this->fixture();
        $manager = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $manager->id, 'role' => 'manager']);
        $other = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic view-only project']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $other->id, 'user_id' => $manager->id, 'role' => 'viewer']);
        $otherTask = app(WorkspaceTaskMutationAction::class)->create($owner, $workspace, $other->public_id, ['title' => 'Synthetic view-only task']);
        $this->browser($manager);
        $expected = 1;
        $render = function () use ($workspace, $company, &$expected): void {
            $this->get(route('clients.tasks', [$workspace, $company]))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->has('task_management.projects', $expected)->has('tasks', $expected + 1));
        };
        $this->assertQueryCountIndependentOfRows($render, function () use ($workspace, $company, $owner, $manager, &$expected): void {
            for ($index = 0; $index < 5; $index++) {
                $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic managed project '.$index]);
                ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $manager->id, 'role' => 'manager']);
                app(WorkspaceTaskMutationAction::class)->create($owner, $workspace, $project->public_id, ['title' => 'Synthetic managed task '.$index]);
            }
            $expected = 6;
        });
        $this->get(route('clients.project', [$workspace, $company, $other]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('task_management', null)->where('tasks.0.detail_url', null));
        $this->get(route('tasks.show', [$workspace, $otherTask]))->assertForbidden();
        $this->getJson(route('agent-api.v1.tasks.assignees.index', [$workspace, $other]))->assertForbidden();
        $this->native()->postJson(route('agent-api.v1.tasks.store', [$workspace, $other->public_id]), ['title' => 'Synthetic refused task', 'is_visible_to_client' => false])->assertForbidden();
        $this->native()->patchJson(route('agent-api.v1.tasks.update', [$workspace, $otherTask->public_id]), ['expected_version' => AgentApiVersion::for($otherTask), 'title' => 'Synthetic refused edit'])->assertForbidden();
        $this->browser($manager)->get(route('tasks.show', [$workspace, $task]))->assertOk();
    }

    /** @return array{Workspace,ClientCompany,ClientProject,User,ClientTask} */
    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic task access', 'slug' => 'synthetic-'.Str::uuid()]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-'.Str::uuid()]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project', 'is_visible_to_client' => true]);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $task = app(WorkspaceTaskMutationAction::class)->create($owner, $workspace, $project->public_id, TaskCreateData::fromValidated(['title' => 'Synthetic private task']));

        return [$workspace, $company, $project, $owner, $task];
    }

    private function browser(User $actor): static
    {
        $this->actingAs($actor, 'web')->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40));

        return $this->native();
    }

    private function native(): static
    {
        return $this->withHeader('Idempotency-Key', 'synthetic-native-'.Str::uuid());
    }
}
