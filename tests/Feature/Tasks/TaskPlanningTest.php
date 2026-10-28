<?php

namespace Tests\Feature\Tasks;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\ClientTaskAssignment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLineComposer;
use App\Services\Tasks\TaskCreateData;
use App\Services\Tasks\TaskPatchData;
use App\Services\Tasks\TaskPlanningPresenter;
use App\Services\Tasks\WorkspaceTaskRepository;
use App\Services\WorkspaceTaskMutationAction;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\Presenters\AgentTaskPresenter;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BillingWriterLockSites;
use Tests\TestCase;

final class TaskPlanningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true]);
    }

    public function test_dates_assignments_and_description_round_trip_with_explicit_web_version(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $assignee = $this->staff($workspace, $project);
        $this->browser($owner)->postJson('/api/v1'.$this->createPath($workspace, $project), [
            'title' => 'Synthetic planned task', 'description' => 'Synthetic description', 'is_visible_to_client' => false,
            'starts_on' => '2026-10-31', 'due_on' => '2026-11-02', 'assignee_ids' => [$assignee->public_id],
        ])->assertCreated();
        $task = app(WorkspaceTaskRepository::class)->find($workspace, ClientTask::query()->sole()->public_id);
        $this->assertSame('2026-10-31', $task->getRawOriginal('starts_on'));
        $this->assertSame('2026-11-02', $task->due_on->toDateString());
        $this->assertSame([$assignee->public_id], array_column(app(TaskPlanningPresenter::class)->fields($task)['assignees'], 'id'));
        $version = AgentApiVersion::for($task);
        $this->browser($owner)->get(route('tasks.show', [$workspace, $task]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('clients/task')->where('task.version', $version)->where('task.starts_on', '2026-10-31')
            ->where('task.assignees.0.id', $assignee->public_id)->where('task.update_action', route('agent-api.v1.tasks.update', [$workspace, $task->public_id]))
            ->where('workspaceNavigation.current_client_id', $company->public_id)
            ->where('workspaceNavigation.clients.0.destinations.tasks', route('clients.tasks', [$workspace, $company], absolute: false)));
        $this->native()->patchJson('/api/v1'.$this->updatePath($workspace, $task), ['expected_version' => $version, 'title' => 'Synthetic revised task'])->assertOk();
        $task->refresh();
        $this->assertSame('Synthetic description', $task->description);
        $this->assertFalse($task->is_visible_to_client);
        $this->assertSame('2026-10-31', $task->starts_on->toDateString());
        $this->assertSame(1, ClientTaskAssignment::query()->where('workspace_id', $workspace->id)->count());
        $this->native()->patchJson('/api/v1'.$this->updatePath($workspace, $task), ['expected_version' => $version, 'is_visible_to_client' => true])->assertConflict();
        $this->assertFalse($task->fresh()->is_visible_to_client);
        $this->native()->patchJson('/api/v1'.$this->updatePath($workspace, $task), ['title' => 'Missing version'])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
    }

    public function test_merged_date_order_clearing_and_strict_calendar_formats(): void
    {
        [$workspace, , $project, $owner] = $this->fixture();
        $task = $this->create($workspace, $project, $owner, ['starts_on' => '2026-10-20', 'due_on' => '2026-10-30']);
        $this->browser($owner)->patchJson('/api/v1'.$this->updatePath($workspace, $task), ['expected_version' => AgentApiVersion::for($task), 'starts_on' => '2026-11-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('due_on');
        $this->assertSame('2026-10-20', $task->fresh()->starts_on->toDateString());
        $this->native()->patchJson('/api/v1'.$this->updatePath($workspace, $task), ['expected_version' => AgentApiVersion::for($task->fresh()), 'starts_on' => null, 'due_on' => null, 'description' => null])->assertOk();
        $this->assertNull($task->fresh()->starts_on);
        $this->assertNull($task->fresh()->due_on);
        foreach (['2026-02-30', '2026-10-22T12:00:00Z', 'tomorrow'] as $invalid) {
            $this->native()->postJson('/api/v1'.$this->createPath($workspace, $project), ['title' => 'Synthetic invalid date', 'is_visible_to_client' => false, 'due_on' => $invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('due_on');
        }
        $this->native()->postJson('/api/v1'.$this->createPath($workspace, $project), ['title' => 'Synthetic reversed dates', 'is_visible_to_client' => false, 'starts_on' => '2026-11-01', 'due_on' => '2026-10-31'])
            ->assertUnprocessable()->assertJsonValidationErrors('due_on');
    }

    public function test_assignments_validate_current_project_access_and_never_create_grants(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        [$foreign, , $foreignProject, $foreignOwner] = $this->fixture();
        $unassigned = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $unassigned->id, 'role' => 'member']);
        $portal = User::factory()->create();
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $portal->id, 'role' => 'client']);
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Other synthetic project']);
        $otherStaff = $this->staff($workspace, $otherProject);
        $this->browser($owner);
        foreach ([$foreignOwner, $unassigned, $portal, $otherStaff] as $ineligible) {
            $this->native()->postJson('/api/v1'.$this->createPath($workspace, $project), ['title' => 'Synthetic refused assignment', 'is_visible_to_client' => false, 'assignee_ids' => [$ineligible->public_id]])
                ->assertUnprocessable()->assertJsonValidationErrors('assignee_ids');
        }
        $this->assertDatabaseCount('client_tasks', 0);
        $this->assertDatabaseCount('client_task_assignments', 0);
        $viewer = $this->staff($workspace, $project, 'viewer');
        $task = $this->create($workspace, $project, $owner, ['assignee_ids' => [$owner->public_id, $viewer->public_id]]);
        $this->assertDatabaseCount('client_project_memberships', 2);
        $this->assertDatabaseCount('workspace_memberships', 5);
        $this->unencryptedCookies = [];
        $this->actingAsMcp($viewer, ['tasks:write']);
        $this->withHeader('Idempotency-Key', 'synthetic-assignee-no-promotion')->patchJson('/api/v1'.$this->updatePath($workspace, $task), ['expected_version' => AgentApiVersion::for($task), 'title' => 'Synthetic refused promotion'])->assertForbidden();
        $this->assertSame('Synthetic task', $task->fresh()->title);
        $this->assertSame($foreign->id, $foreignProject->workspace_id);
    }

    public function test_assignment_changes_advance_revision_and_record_responsibility_after_revocation(): void
    {
        [$workspace, , $project, $owner] = $this->fixture();
        $assignee = $this->staff($workspace, $project);
        $task = $this->create($workspace, $project, $owner);
        $version = AgentApiVersion::for($task);
        $action = app(WorkspaceTaskMutationAction::class);
        $task = $action->update($owner, $workspace, $task->public_id, TaskPatchData::fromValidated(['assignee_ids' => [$assignee->public_id]]));
        $this->assertNotSame($version, AgentApiVersion::for($task));
        $this->assertSame(1, $task->assignments->count());
        ClientProjectMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $assignee->id)->delete();
        $task = $action->update($owner, $workspace, $task->public_id, TaskPatchData::fromValidated(['title' => 'Synthetic metadata edit', 'assignee_ids' => [$assignee->public_id]]), AgentApiVersion::for($task));
        $this->assertSame(1, $task->assignments->count());
        $this->assertSame([], app(WorkspaceTaskRepository::class)->assigneeOptions($workspace, $project, $assignee->name)['data']);
        $task = $action->update($owner, $workspace, $task->public_id, TaskPatchData::fromValidated(['assignee_ids' => []]), AgentApiVersion::for($task));
        $this->assertSame(0, $task->assignments->count());
        $this->browser($owner)->patchJson('/api/v1'.$this->updatePath($workspace, $task), ['expected_version' => AgentApiVersion::for($task), 'assignee_ids' => [$assignee->public_id]])->assertUnprocessable();
    }

    public function test_agent_replays_and_partial_updates_preserve_planning_and_hide_it_by_default(): void
    {
        [$workspace, , $project, $owner] = $this->fixture();
        $assignee = $this->staff($workspace, $project);
        $this->actingAsMcp($owner, ['tasks:write']);
        $body = ['title' => 'Synthetic agent plan', 'starts_on' => '2026-12-01', 'assignee_ids' => [$assignee->public_id]];
        $created = $this->withHeader('Idempotency-Key', 'synthetic-planning-create')->postJson('/api/v1'.$this->createPath($workspace, $project), $body)->assertCreated();
        $created->assertJsonPath('data.starts_on', '2026-12-01')->assertJsonPath('data.assignees.0.id', $assignee->public_id);
        $this->postJson('/api/v1'.$this->createPath($workspace, $project), $body)->assertCreated()->assertJsonPath('data.id', $created->json('data.id'));
        $task = app(WorkspaceTaskRepository::class)->find($workspace, $created->json('data.id'));
        $plain = app(AgentTaskPresenter::class)->present($workspace, $task);
        $this->assertArrayNotHasKey('assignees', $plain);
        $this->assertArrayNotHasKey('starts_on', $plain);
        $version = $created->json('data.version');
        $updated = $this->withHeader('Idempotency-Key', 'synthetic-planning-clear')->patchJson('/api/v1'.$this->updatePath($workspace, $task), ['expected_version' => $version, 'starts_on' => null])->assertOk();
        $updated->assertJsonPath('data.starts_on', null)->assertJsonPath('data.assignees.0.id', $assignee->public_id);
        $this->withHeader('Idempotency-Key', 'synthetic-planning-stale')->patchJson('/api/v1'.$this->updatePath($workspace, $task), ['expected_version' => $version, 'assignee_ids' => []])->assertConflict();
        $this->assertDatabaseCount('client_tasks', 1);
        $this->assertDatabaseCount('client_task_assignments', 1);
    }

    public function test_dual_portal_and_staff_identity_cannot_create_tasks_through_agent_requests(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'client']);
        $this->actingAsMcp($owner, ['tasks:write']);
        $this->withHeader('Idempotency-Key', 'synthetic-dual-planning')->postJson('/api/v1'.$this->createPath($workspace, $project), [
            'title' => 'Synthetic dual task', 'starts_on' => '2026-10-22', 'assignee_ids' => [$owner->public_id],
        ])->assertForbidden();
        $this->assertDatabaseCount('client_tasks', 0);
    }

    public function test_assignment_limit_duplicates_and_scoped_lookup(): void
    {
        [$workspace, , $project, $owner] = $this->fixture();
        [$foreign, , $foreignProject] = $this->fixture();
        $this->staff($foreign, $foreignProject);
        $staff = $this->staff($workspace, $project);
        $this->browser($owner);
        foreach ([[$staff->public_id, $staff->public_id], ['not-a-list' => $staff->public_id], array_map(fn () => (string) Str::uuid(), range(1, 11))] as $ids) {
            $this->native()->postJson('/api/v1'.$this->createPath($workspace, $project), ['title' => 'Synthetic bound', 'is_visible_to_client' => false, 'assignee_ids' => $ids])->assertUnprocessable();
        }
        $this->browser($owner)->getJson(route('tasks.assignees', [$workspace, $project], false).'?q='.urlencode($staff->name))->assertOk()->assertJsonPath('data.0.id', $staff->public_id)->assertJsonCount(1, 'data');
        $this->getJson(route('tasks.assignees', [$workspace, $foreignProject], false))->assertNotFound();
        $this->browser($staff)->getJson(route('tasks.assignees', [$workspace, $project], false))->assertForbidden();
    }

    public function test_assignee_lookup_is_bounded_and_search_can_reach_later_matches(): void
    {
        [$workspace, , $project, $owner] = $this->fixture();
        $last = null;
        for ($index = 0; $index < 26; $index++) {
            $last = $this->staff($workspace, $project);
        }
        $this->browser($owner)->getJson(route('tasks.assignees', [$workspace, $project], false))
            ->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('has_more', true);
        $this->browser($owner)->getJson(route('tasks.assignees', [$workspace, $project], false).'?q='.urlencode($last->name))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $last->public_id)->assertJsonPath('has_more', false);
    }

    public function test_assignment_composite_key_refuses_foreign_task(): void
    {
        [$workspace, , , $owner] = $this->fixture();
        [$foreign, , $foreignProject, $foreignOwner] = $this->fixture();
        $task = $this->create($foreign, $foreignProject, $foreignOwner);
        $this->expectException(QueryException::class);
        ClientTaskAssignment::query()->create(['workspace_id' => $workspace->id, 'client_task_id' => $task->id, 'user_id' => $owner->id]);
    }

    public function test_task_lock_inventory_and_access_lock_survive_repository_refactor(): void
    {
        [$workspace, , $project, $owner] = $this->fixture();
        $task = $this->create($workspace, $project, $owner);
        $sites = BillingWriterLockSites::all();
        $this->assertContains('App\\Services\\Tasks\\WorkspaceTaskRepository::find', $sites);
        LockOrderRecorder::start();
        try {
            app(WorkspaceTaskMutationAction::class)->update($owner, $workspace, $task->public_id, TaskPatchData::fromValidated(['title' => 'Synthetic lock edit']));
            $sequence = LockOrderRecorder::sequences()[0];
            $this->assertSame([LockResource::BillingMaintenance, LockResource::Workspace, LockResource::ClientTask, LockResource::ClientTaskAssignment, LockResource::WorkspaceMembership, LockResource::ClientProjectMembership, LockResource::ClientCompanyMembership], array_values(array_unique($sequence, SORT_REGULAR)));
        } finally {
            LockOrderRecorder::stop();
        }
    }

    public function test_completion_timestamp_and_existing_invoice_claim_survive_task_planning_edits(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $this->travelTo(CarbonImmutable::parse('2026-10-31 23:30:00', 'UTC'));
        $task = $this->create($workspace, $project, $owner);
        $task->forceFill(['milestone_price_amount' => 18750])->save();
        $action = app(WorkspaceTaskMutationAction::class);
        $task = $action->update($owner, $workspace, $task->public_id, TaskPatchData::fromValidated(['status' => 'completed']));
        $completed = $task->completed_at->toAtomString();
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'invoice_number' => 'SYNTHETIC-MILESTONE-1', 'currency' => 'USD', 'status' => 'draft', 'service_period_end' => '2026-10-31']);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC'));
        $task = $action->update($owner, $workspace, $task->public_id, TaskPatchData::fromValidated([
            'status' => 'completed', 'starts_on' => '2026-12-01', 'due_on' => '2026-12-02', 'assignee_ids' => [$owner->public_id],
            'milestone_price_amount' => 1, 'client_project_id' => -1, 'workspace_id' => -1,
        ]));
        $this->assertSame($completed, $task->completed_at->toAtomString());
        $this->assertSame(18750, $task->milestone_price_amount);
        $this->assertSame($project->id, $task->client_project_id);
        $sort = 0;
        DB::transaction(function () use ($company, $invoice, &$sort): void {
            app(InvoiceLineComposer::class)->addBillableMilestoneTasks($company, $invoice, Carbon::parse('2026-10-31'), $sort);
        });
        $claim = $task->fresh()->client_invoice_line_id;
        $this->assertNotNull($claim, 'Repeated completed status must not move the milestone out of its billable month.');
        $this->assertSame(18750, $invoice->lines()->sole()->unit_amount);
        $this->assertSame('2026-10-31', $invoice->lines()->sole()->line_date->toDateString());
        $task = $action->update($owner, $workspace, $task->public_id, TaskPatchData::fromValidated(['status' => 'open']));
        $this->assertNull($task->completed_at);
        $task = $action->update($owner, $workspace, $task->public_id, TaskPatchData::fromValidated(['status' => 'completed']));
        $this->assertSame($claim, $task->client_invoice_line_id);
        DB::transaction(function () use ($company, $invoice, &$sort): void {
            app(InvoiceLineComposer::class)->addBillableMilestoneTasks($company, $invoice, Carbon::parse('2026-11-30'), $sort);
        });
        $this->assertSame(1, $invoice->lines()->count());
    }

    /** @return array{Workspace,ClientCompany,ClientProject,User} */
    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic planning workspace', 'slug' => 'synthetic-'.Str::uuid(), 'timezone' => 'America/Los_Angeles']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic planning company', 'slug' => 'synthetic-'.Str::uuid()]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic planning project']);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$workspace, $company, $project, $owner];
    }

    private function staff(Workspace $workspace, ClientProject $project, string $role = 'contributor'): User
    {
        $user = User::factory()->create(['name' => 'Synthetic staff '.Str::uuid(), 'email' => 'synthetic-'.Str::uuid().'@example.test']);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /** @param array<string,mixed> $attributes */
    private function create(Workspace $workspace, ClientProject $project, User $owner, array $attributes = []): ClientTask
    {
        return app(WorkspaceTaskMutationAction::class)->create($owner, $workspace, $project->public_id, TaskCreateData::fromValidated($attributes + ['title' => 'Synthetic task']));
    }

    private function createPath(Workspace $workspace, ClientProject $project): string
    {
        return '/workspaces/'.$workspace->public_id.'/projects/'.$project->public_id.'/tasks';
    }

    private function updatePath(Workspace $workspace, ClientTask $task): string
    {
        return '/workspaces/'.$workspace->public_id.'/tasks/'.$task->public_id;
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
