<?php

namespace Tests\Feature\Tasks;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentReadService;
use App\Services\Tasks\TaskCreateData;
use App\Services\WorkspaceTaskMutationAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class TaskPlanningReadTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    public function test_internal_task_list_detail_and_project_embed_include_safe_planning_fields(): void
    {
        [$workspace, $company, $project, $owner, $assignee, $task] = $this->fixture();
        $this->actingAsMcp($owner, ['tasks:read', 'projects:read']);
        foreach ([['tasks', 'data.0'], ['tasks/'.$task->public_id, 'data'], ['projects/'.$project->public_id, 'data.tasks.0']] as [$path, $key]) {
            $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/'.$path)->assertOk()
                ->assertJsonPath($key.'.id', $task->public_id)->assertJsonPath($key.'.starts_on', '2026-10-20')
                ->assertJsonPath($key.'.due_on', '2026-10-30')->assertJsonPath($key.'.web_url', route('tasks.show', [$workspace, $task]))->assertJsonPath($key.'.assignees.0.id', $assignee->public_id)
                ->assertJsonPath($key.'.assignees.0.name', $assignee->name)->assertJsonMissingPath($key.'.assignees.0.email');
        }
        $this->actingAsMcp($assignee, ['tasks:read']);
        $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/tasks/'.$task->public_id)->assertOk()
            ->assertJsonPath('data.web_url', route('clients.tasks', [$workspace, $company, 'project' => $project->public_id]));
    }

    public static function clientIdentities(): iterable
    {
        yield 'portal account' => [false];
        yield 'dual portal and assigned staff account' => [true];
    }

    #[DataProvider('clientIdentities')]
    public function test_portal_and_dual_identities_do_not_receive_internal_planning_or_hidden_embeds(bool $dual): void
    {
        [$workspace, $company, $project, $owner, $assignee, $task] = $this->fixture();
        $hidden = app(WorkspaceTaskMutationAction::class)->create($owner, $workspace, $project->public_id, TaskCreateData::fromValidated(['title' => 'Synthetic hidden task secret']));
        $viewer = $dual ? $assignee : User::factory()->create();
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $viewer->id, 'role' => 'client']);
        $this->actingAsMcp($viewer, ['tasks:read', 'projects:read']);
        foreach ([['tasks', 'data.0'], ['tasks/'.$task->public_id, 'data'], ['projects/'.$project->public_id, 'data.tasks.0']] as [$path, $key]) {
            $response = $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/'.$path)->assertOk()
                ->assertJsonPath($key.'.id', $task->public_id)->assertJsonPath($key.'.web_url', route('portal.tasks', [$company, 'project' => $project->public_id]))->assertJsonMissingPath($key.'.starts_on')
                ->assertJsonMissingPath($key.'.due_on')->assertJsonMissingPath($key.'.assignees');
            $this->assertJsonPayloadOmits($response, [$hidden->title, $assignee->name], $task->title);
        }
        $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/tasks/'.$hidden->public_id)->assertNotFound();
        $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/tasks')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/projects/'.$project->public_id)->assertOk()->assertJsonCount(1, 'data.tasks');
    }

    public function test_malformed_task_and_project_company_chains_are_concealed(): void
    {
        [$workspace, , $project, $owner, , $task] = $this->fixture();
        [, $foreignCompany, $foreignProject] = $this->fixture();
        [$badTask, $badProject, $badCompanyTask] = $this->writingLegacyCrossTenantRows(function () use ($workspace, $foreignCompany, $foreignProject): array {
            $badTask = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $foreignProject->id, 'title' => 'Synthetic foreign task parent secret']);
            $badProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $foreignCompany->id, 'name' => 'Synthetic foreign company parent secret']);
            $badCompanyTask = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $badProject->id, 'title' => 'Synthetic bad company task secret']);

            return [$badTask, $badProject, $badCompanyTask];
        });
        $this->actingAsMcp($owner, ['tasks:read', 'projects:read']);
        $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/tasks')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $task->public_id);
        foreach ([$badTask, $badCompanyTask] as $bad) {
            $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/tasks/'.$bad->public_id)->assertNotFound();
        }
        $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/projects/'.$project->public_id)->assertOk()->assertJsonCount(1, 'data.tasks')->assertJsonPath('data.tasks.0.id', $task->public_id);
        $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/projects/'.$badProject->public_id)->assertNotFound();
    }

    public function test_dual_workspace_owner_reads_only_the_exact_visible_portal_project_grant(): void
    {
        [$workspace, $company, $project, $owner, $assignee, $task] = $this->fixture();
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic other company secret', 'slug' => 'synthetic-'.Str::uuid()]);
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic ungranted project secret', 'is_visible_to_client' => true]);
        $foreignCompanyProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $otherCompany->id, 'name' => 'Synthetic other company project secret', 'is_visible_to_client' => true]);
        $deniedTasks = [];
        foreach ([[$project, false], [$otherProject, true], [$foreignCompanyProject, true]] as [$parent, $visible]) {
            $deniedTasks[] = app(WorkspaceTaskMutationAction::class)->create($owner, $workspace, $parent->public_id,
                TaskCreateData::fromValidated(['title' => 'Synthetic denied task secret '.count($deniedTasks), 'is_visible_to_client' => $visible]));
        }
        $membership = ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_PROJECTS]);
        DB::table('client_portal_project_access')->insert(['workspace_id' => $workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $project->id]);
        $this->actingAsMcp($owner, ['tasks:read', 'projects:read']);
        $base = '/api/v1/workspaces/'.$workspace->public_id.'/';
        foreach ([['tasks', 'data.0'], ['tasks/'.$task->public_id, 'data'], ['projects/'.$project->public_id, 'data.tasks.0']] as [$path, $key]) {
            $response = $this->getJson($base.$path)->assertOk()->assertJsonPath($key.'.id', $task->public_id)
                ->assertJsonPath($key.'.web_url', route('portal.tasks', [$company, 'project' => $project->public_id]))
                ->assertJsonMissingPath($key.'.starts_on')->assertJsonMissingPath($key.'.due_on')->assertJsonMissingPath($key.'.assignees');
            $this->assertJsonPayloadOmits($response, [$assignee->name, ...array_map(fn (ClientTask $denied): string => $denied->title, $deniedTasks)], $task->title);
        }
        $this->getJson($base.'tasks')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($base.'projects')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $project->public_id);
        $this->getJson($base.'projects/'.$project->public_id)->assertOk()->assertJsonCount(1, 'data.tasks');
        foreach ($deniedTasks as $denied) {
            $this->getJson($base.'tasks/'.$denied->public_id)->assertNotFound();
        }
        foreach ([$otherProject, $foreignCompanyProject] as $denied) {
            $this->getJson($base.'projects/'.$denied->public_id)->assertNotFound();
        }
    }

    public static function surfaces(): iterable
    {
        yield 'task list' => ['list'];
        yield 'embedded project tasks' => ['project'];
    }

    #[DataProvider('surfaces')]
    public function test_planning_assignment_queries_are_independent_of_task_row_count(string $surface): void
    {
        [$workspace, , $project, $owner, $assignee] = $this->fixture();
        $reads = app(AgentReadService::class);
        $expected = 1;
        $render = function () use ($surface, $reads, $owner, $workspace, $project, $assignee, &$expected): void {
            $tasks = $surface === 'list' ? $reads->tasks($owner, $workspace, null, 100, null)['data']
                : $reads->project($owner, $workspace, $project->public_id, true)['tasks'];
            $this->assertCount($expected, $tasks);
            foreach ($tasks as $row) {
                $this->assertSame($assignee->public_id, $row['assignees'][0]['id']);
            }
        };
        $this->assertQueryCountIndependentOfRows($render, function () use ($owner, $workspace, $project, $assignee, &$expected): void {
            for ($index = 0; $index < 5; $index++) {
                app(WorkspaceTaskMutationAction::class)->create($owner, $workspace, $project->public_id,
                    TaskCreateData::fromValidated(['title' => 'Synthetic additional planning task '.$index, 'assignee_ids' => [$assignee->public_id]]));
            }
            $expected = 6;
        });
    }

    /** @return array{Workspace,ClientCompany,ClientProject,User,User,ClientTask} */
    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic read workspace', 'slug' => 'synthetic-'.Str::uuid()]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic read company', 'slug' => 'synthetic-'.Str::uuid()]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic read project', 'is_visible_to_client' => true]);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $assignee = User::factory()->create(['name' => 'Synthetic internal assignee secret', 'email' => 'synthetic-'.Str::uuid().'@example.test']);
        $workspace->memberships()->create(['user_id' => $assignee->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $assignee->id, 'role' => 'contributor']);
        $task = app(WorkspaceTaskMutationAction::class)->create($owner, $workspace, $project->public_id, TaskCreateData::fromValidated([
            'title' => 'Synthetic visible planning task', 'is_visible_to_client' => true, 'starts_on' => '2026-10-20', 'due_on' => '2026-10-30', 'assignee_ids' => [$assignee->public_id],
        ]));

        return [$workspace, $company, $project, $owner, $assignee, $task];
    }
}
