<?php

namespace Tests\Feature\Tasks;

use App\Http\Controllers\ClientDirectoryController;
use App\Http\Controllers\ClientTaskController;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\TaskCreateData;
use App\Services\Tasks\TaskEditorPresenter;
use App\Services\WorkspaceTaskMutationAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(ClientDirectoryController::class)]
#[CoversClass(ClientTaskController::class)]
#[CoversClass(TaskEditorPresenter::class)]
final class TaskAssigneeSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_task_and_project_seed_the_same_bounded_scoped_roster_as_the_lookup(): void
    {
        [$workspace, $company, $project, $owner, $task] = $this->fixture();
        for ($index = 0; $index < 27; $index++) {
            $this->staff($workspace, $project, 'Synthetic eligible '.$index);
        }
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic other project']);
        $outsider = $this->staff($workspace, $otherProject, 'Synthetic other-project identity');
        [$foreignWorkspace, , $foreignProject] = $this->fixture();
        $foreign = $this->staff($foreignWorkspace, $foreignProject, 'Synthetic foreign identity');

        $this->actingAs($owner, 'web')->withCredentials()->withUnencryptedCookie(config('session.cookie'), Str::random(40));
        $lookup = $this->getJson(route('agent-api.v1.tasks.assignees.index', [$workspace, $project]))->assertOk()->json();
        $this->assertCount(25, $lookup['data']);
        $this->assertTrue($lookup['has_more']);
        foreach ($lookup['data'] as $person) {
            $this->assertSame(['id', 'name'], array_keys($person));
            $this->assertNotContains($person['id'], [$outsider->public_id, $foreign->public_id]);
        }
        $this->actingAs($owner, 'web')->get(route('tasks.show', [$workspace, $task]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('task.assignee_options', $lookup));
        $this->get(route('clients.project', [$workspace, $company, $project]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('task_management.projects.0.assignee_options', $lookup));
    }

    public function test_company_page_only_seeds_the_first_manageable_project(): void
    {
        [$workspace, $company, $first, $owner] = $this->fixture();
        $second = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic second project']);
        $firstStaff = $this->staff($workspace, $first, 'Synthetic first-project staff');
        $secondStaff = $this->staff($workspace, $second, 'Synthetic second-project staff');
        $this->actingAs($owner)->get(route('clients.tasks', [$workspace, $company]))->assertOk()->assertInertia(function (Assert $page) use ($workspace, $firstStaff, $secondStaff): void {
            $page->has('task_management.projects', 2)
                ->has('task_management.projects.0.assignee_options.data', 2)
                ->where('task_management.projects.0.assignee_options.has_more', false)
                ->missing('task_management.projects.1.assignee_options');
            $projects = $page->toArray()['props']['task_management']['projects'];
            $firstOptions = array_column($projects[0]['assignee_options']['data'], 'id');
            $this->assertContains($firstStaff->public_id, $firstOptions);
            $this->assertNotContains($secondStaff->public_id, $firstOptions);
            $this->assertStringContainsString($workspace->public_id, $projects[1]['assignees_action']);
        });
    }

    public function test_a_company_without_projects_has_no_editor_or_roster_to_preload(): void
    {
        [$workspace, , , $owner] = $this->fixture();
        $empty = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic no-project client', 'slug' => 'synthetic-empty']);
        $this->actingAs($owner)->get(route('clients.tasks', [$workspace, $empty]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('task_management', null)->has('projects', 0)->has('tasks', 0));
    }

    public function test_initial_html_safely_embeds_roster_names_in_the_existing_json_page_payload(): void
    {
        [$workspace, , $project, $owner, $task] = $this->fixture();
        $name = 'Synthetic </script><script>window.untrustedRoster=true</script>';
        $person = $this->staff($workspace, $project, $name);
        $html = $this->actingAs($owner)->get(route('tasks.show', [$workspace, $task]))->assertOk()->getContent();
        $this->assertStringNotContainsString($name, $html);
        $this->assertSame(1, preg_match('/<script data-page="app" type="application\/json">(.*?)<\/script>/s', $html, $matches));
        $payload = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $people = $payload['props']['task']['assignee_options']['data'];
        $this->assertContains(['id' => $person->public_id, 'name' => $name], $people);
    }

    private function staff(Workspace $workspace, ClientProject $project, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $user->id, 'role' => 'contributor']);

        return $user;
    }

    /** @return array{Workspace, ClientCompany, ClientProject, User, ClientTask} */
    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic seeded roster', 'slug' => 'synthetic-'.Str::uuid()]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-'.Str::uuid()]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic first project']);
        $owner = User::factory()->create(['name' => 'Synthetic workspace owner']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $task = app(WorkspaceTaskMutationAction::class)->create($owner, $workspace, $project->public_id, TaskCreateData::fromValidated(['title' => 'Synthetic task']));

        return [$workspace, $company, $project, $owner, $task];
    }
}
