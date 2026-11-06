<?php

namespace Tests\Feature\MyWork;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\ClientTaskAssignment;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\ProjectRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\TestCase;

/**
 * The "My work" page (docs/ux-overhaul.md section 5): a member's own tasks,
 * time and a way to log it, with no money anywhere on it.
 *
 * The isolation tests are the point. The page is read through the viewer's own
 * assignments, time and project memberships, so each is checked against a
 * second member's rows, a second project the viewer does not hold, and a second
 * workspace, none of which may reach the payload.
 */
class MyWorkPageTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private User $colleague;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-06 12:00:00');

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->colleague = User::factory()->create();
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic My Work', 'slug' => 'synthetic-my-work']);
        foreach ([[$this->owner, 'owner'], [$this->member, 'member'], [$this->colleague, 'member']] as [$user, $role]) {
            $this->workspace->memberships()->create(['user_id' => $user->id, 'role' => $role]);
        }
        [$this->company, $this->project] = $this->clientWithProject($this->workspace, 'Synthetic Client');
        $this->hold($this->project, $this->member, ProjectRole::Contributor);
        $this->hold($this->project, $this->colleague, ProjectRole::Contributor);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{ClientCompany, ClientProject} */
    private function clientWithProject(Workspace $workspace, string $name): array
    {
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => $name, 'slug' => str($name)->slug().'-'.uniqid()]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => "$name Project", 'status' => 'active']);

        return [$company, $project];
    }

    private function hold(ClientProject $project, User $user, ProjectRole $role): void
    {
        ClientProjectMembership::query()->create(['workspace_id' => $project->workspace_id, 'client_project_id' => $project->id, 'user_id' => $user->id, 'role' => $role->value]);
    }

    private function task(ClientProject $project, ?User $assignee, string $title, ?string $due = null, string $status = 'open'): ClientTask
    {
        $task = ClientTask::query()->create(['workspace_id' => $project->workspace_id, 'client_project_id' => $project->id, 'title' => $title, 'status' => $status, 'due_on' => $due]);
        if ($assignee !== null) {
            ClientTaskAssignment::query()->create(['workspace_id' => $project->workspace_id, 'client_task_id' => $task->id, 'user_id' => $assignee->id]);
        }

        return $task;
    }

    private function time(ClientProject $project, User $user, string $description, string $day, int $minutes = 60, string $status = 'draft'): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $project->workspace_id, 'client_company_id' => $project->client_company_id, 'client_project_id' => $project->id,
            'user_id' => $user->id, 'worked_on' => $day, 'minutes' => $minutes, 'description' => $description, 'status' => $status,
            'billing_rate_amount' => 987654, 'currency' => 'USD', 'subcontractor_cost_amount' => 765432,
        ]);
    }

    private function path(): string
    {
        return "/workspaces/{$this->workspace->public_id}/my-work";
    }

    public function test_a_member_sees_their_own_tasks_grouped_by_due_date(): void
    {
        $this->task($this->project, $this->member, 'Overdue synthetic task', '2026-11-01');
        $this->task($this->project, $this->member, 'Today synthetic task', '2026-11-06');
        $this->task($this->project, $this->member, 'Undated synthetic task');
        $this->task($this->project, $this->member, 'Finished synthetic task', '2026-11-06', 'completed');

        $this->actingAs($this->member)->get($this->path())->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspaces/my-work')
                ->where('today', '2026-11-06')
                ->has('tasks', 3)
                ->where('tasks.0.key', 'overdue')
                ->where('tasks.0.tasks.0.title', 'Overdue synthetic task')
                ->where('tasks.0.tasks.0.project', 'Synthetic Client Project')
                ->where('tasks.0.tasks.0.client', 'Synthetic Client')
                ->where('tasks.1.key', 'today')
                ->where('tasks.2.key', 'no_date'));
    }

    public function test_nobody_elses_work_reaches_the_page(): void
    {
        [$otherCompany, $otherProject] = $this->clientWithProject($this->workspace, 'Unheld Client');
        [, $foreignProject] = $this->clientWithProject(Workspace::query()->create(['name' => 'Other Tenant', 'slug' => 'other-tenant']), 'Foreign Client');

        $this->task($this->project, $this->member, 'Visible mine');
        $this->task($this->project, $this->colleague, 'Secret colleague task');
        $this->task($otherProject, $this->member, 'Secret unheld project task');
        $this->task($foreignProject, $this->member, 'Secret other tenant task');
        $this->time($this->project, $this->member, 'Visible my work', '2026-11-04');
        $this->time($this->project, $this->colleague, 'Secret colleague time', '2026-11-04');
        $this->time($otherProject, $this->member, 'Secret unheld project time', '2026-11-04');
        $this->time($foreignProject, $this->member, 'Secret other tenant time', '2026-11-04');

        $response = $this->actingAs($this->member)->get($this->path())->assertOk();

        $this->assertInertiaPayloadOmits($response, [
            'Secret colleague task', 'Secret unheld project task', 'Secret other tenant task', 'Unheld Client', 'Foreign Client', $otherCompany->public_id,
        ], 'Visible mine');
        $response->assertInertia(fn (Assert $page) => $page
            ->where('time.week.total_minutes', 60)
            ->has('time.week.projects', 1)
            ->has('projects', 1));
    }

    public function test_no_rate_amount_or_cost_reaches_the_page_for_anyone(): void
    {
        $this->task($this->project, $this->member, 'Priced synthetic task');
        $this->time($this->project, $this->member, 'Priced synthetic work', '2026-11-04', status: 'approved');
        $this->task($this->project, $this->owner, 'Priced synthetic owner task');
        $this->time($this->project, $this->owner, 'Priced synthetic owner work', '2026-11-04', status: 'approved');

        foreach ([$this->member, $this->owner] as $viewer) {
            // The page's own props; the shell's navigation payload names the
            // Invoices tabs it is allowed to, which is not this page's content.
            $props = $this->actingAs($viewer)->get($this->path())->assertOk()->viewData('page')['props'];
            $payload = json_encode(array_intersect_key($props, array_flip(['workspace', 'today', 'tasks', 'projects', 'time'])));
            $this->assertIsString($payload);
            $this->assertStringContainsString('Priced synthetic', (string) json_encode($props['tasks']));
            foreach (['987654', '765432', 'billing_rate', 'rate', 'cost', 'amount', 'currency', 'invoice', 'revenue'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $payload, "{$needle} must not reach My work");
            }
        }
    }

    public function test_time_is_rolled_up_by_week_and_month_with_its_review_state(): void
    {
        // 2026-11-06 is a Friday: the week is Nov 2-8.
        $this->time($this->project, $this->member, 'a', '2026-11-03', 90, 'approved');
        $this->time($this->project, $this->member, 'b', '2026-11-05', 30, 'draft');
        $this->time($this->project, $this->member, 'c', '2026-11-01', 120, 'invoiced');
        $this->time($this->project, $this->member, 'd', '2026-10-31', 600, 'invoiced');

        $this->actingAs($this->member)->get($this->path())->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('time.week.total_minutes', 120)
                ->where('time.week.projects.0.states', [['status' => 'draft', 'minutes' => 30], ['status' => 'approved', 'minutes' => 90]])
                ->where('time.month.total_minutes', 240)
                ->where('time.month.projects.0.project', 'Synthetic Client Project'));
    }

    public function test_the_quick_log_offers_only_projects_the_member_can_log_on(): void
    {
        [, $viewerOnly] = $this->clientWithProject($this->workspace, 'Viewer Client');
        $this->hold($viewerOnly, $this->member, ProjectRole::Viewer);
        [, $managed] = $this->clientWithProject($this->workspace, 'Managed Client');
        $this->hold($managed, $this->member, ProjectRole::Manager);
        $closed = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Closed Project', 'status' => 'completed']);
        $this->hold($closed, $this->member, ProjectRole::Contributor);

        $this->actingAs($this->member)->get($this->path())->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('projects', 2)
                ->where('projects.0.name', 'Managed Client Project')
                ->where('projects.1.name', 'Synthetic Client Project')
                ->where('projects.1.log_time_url', "/api/v1/workspaces/{$this->workspace->public_id}/time-entries"));
    }

    public function test_a_status_url_is_sent_only_where_the_member_may_edit_the_task(): void
    {
        [, $managed] = $this->clientWithProject($this->workspace, 'Managed Client');
        $this->hold($managed, $this->member, ProjectRole::Manager);
        $mine = $this->task($this->project, $this->member, 'Contributor task');
        $managedTask = $this->task($managed, $this->member, 'Manager task');

        $this->actingAs($this->member)->get($this->path())->assertOk()
            ->assertInertia(function (Assert $page) use ($managedTask, $mine): void {
                $tasks = collect($page->toArray()['props']['tasks'])->flatMap(fn (array $group): array => $group['tasks'])->keyBy('title');
                $this->assertNull($tasks['Contributor task']['update_url']);
                $this->assertNull($tasks['Contributor task']['version']);
                $this->assertSame("/api/v1/workspaces/{$this->workspace->public_id}/tasks/{$managedTask->public_id}", $tasks['Manager task']['update_url']);
                $this->assertSame(64, strlen($tasks['Manager task']['version']));
                $this->assertSame("/workspaces/{$this->workspace->public_id}/tasks/{$mine->public_id}", $tasks['Contributor task']['href']);
            });
    }

    public function test_a_member_granted_no_project_sees_an_empty_page_not_the_workspace(): void
    {
        $stranger = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $stranger->id, 'role' => 'member']);
        $this->task($this->project, $stranger, 'Orphan synthetic task');
        $this->time($this->project, $stranger, 'Orphan synthetic time', '2026-11-04');

        $this->actingAs($stranger)->get($this->path())->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tasks', 0)
                ->has('projects', 0)
                ->where('time.week.total_minutes', 0)
                ->where('time.month.total_minutes', 0));
    }

    public function test_an_owner_sees_only_their_own_work_across_every_project(): void
    {
        $this->task($this->project, $this->owner, 'Owner task');
        $this->task($this->project, $this->member, 'Member task');
        $this->time($this->project, $this->owner, 'Owner time', '2026-11-04', 45);
        $this->time($this->project, $this->member, 'Member time', '2026-11-04', 15);

        $this->actingAs($this->owner)->get($this->path())->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tasks', 1)
                ->where('tasks.0.tasks.0.title', 'Owner task')
                ->where('tasks.0.tasks.0.update_url', fn (string $url): bool => str_contains($url, '/tasks/'))
                ->where('time.week.total_minutes', 45)
                ->has('projects', 1));
    }

    public function test_the_navigation_names_my_work_for_members_and_not_owners(): void
    {
        $tabs = fn (User $user) => $this->actingAs($user)->get($this->path())->assertOk()->viewData('page')['props']['workspaceNavigation']['workspace_destinations']['my_work'];

        $this->assertSame($this->path(), $tabs($this->member));
        $this->assertNull($tabs($this->owner));
    }

    public function test_someone_outside_the_workspace_and_a_guest_are_refused(): void
    {
        $this->actingAs(User::factory()->create())->get($this->path())->assertForbidden();

        auth()->logout();
        $this->get($this->path())->assertRedirect();
    }

    public function test_the_page_does_not_query_once_per_row(): void
    {
        $count = 0;
        $addRows = function (int $rows) use (&$count): void {
            for ($i = 0; $i < $rows; $i++) {
                $count++;
                [, $project] = $this->clientWithProject($this->workspace, 'Bulk Client '.$count);
                $this->hold($project, $this->member, ProjectRole::Manager);
                $this->task($project, $this->member, 'Bulk task '.$count, '2026-11-0'.(($count % 8) + 1));
                $this->time($project, $this->member, 'Bulk time '.$count, '2026-11-04');
            }
        };
        $addRows(2);

        $this->assertQueryCountIndependentOfRows(
            fn () => $this->actingAs($this->member)->get($this->path())->assertOk(),
            fn () => $addRows(8),
        );
    }
}
