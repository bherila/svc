<?php

namespace Tests\Feature\Tasks\Organization;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTaskDependency;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\TaskCompletionAdvisory;
use App\Services\Tasks\WorkspaceTaskRepository;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\Presenters\AgentTaskPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class TaskCompletionAdvisoryTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    public function test_native_editor_and_internal_agent_reads_warn_before_completion_without_exposing_children(): void
    {
        [$workspace, $company, $project, $owner, $parent, $child] = $this->fixture();
        $this->actingAs($owner, 'web')->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (app(HandleInertiaRequests::class)->version(Request::create('/')) ?? '')])->get(route('tasks.show', [$workspace, $parent]))
            ->assertOk()->assertJsonPath('props.task.status', 'open')->assertJsonPath('props.task.completion_advisory', $this->message());
        $this->withoutHeader('X-Inertia');
        $this->withoutHeader('X-Inertia-Version');
        $this->actingAsMcp($owner, ['mcp:use', 'tasks:read', 'projects:read']);
        foreach ([['tasks', 'data.0'], ['tasks/'.$parent->public_id, 'data'], ['projects/'.$project->public_id, 'data.tasks.0']] as [$path, $key]) {
            $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/'.$path)->assertOk()
                ->assertJsonPath($key.'.completion_advisory', $this->message());
        }
        $reply = $this->callTool($this->initialize(), 'tasks.get', ['workspace_id' => $workspace->public_id, 'task_id' => $parent->public_id]);
        $this->assertArrayHasKey('result', $reply, json_encode($reply, JSON_THROW_ON_ERROR));
        $this->assertSame($this->message(), $reply['result']['structuredContent']['data']['completion_advisory']);
        $this->assertSame('open', $parent->fresh()->status);
        $this->assertSame('open', $child->fresh()->status);
        $this->assertDatabaseCount('client_task_graph_states', 0);

        // Both a dual workspace owner and a Portal-only user are client audiences.
        foreach ([$owner, User::factory()->create()] as $viewer) {
            ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $viewer->id, 'role' => 'client']);
            $this->actingAsMcp($viewer, ['mcp:use', 'tasks:read', 'projects:read']);
            foreach ([['tasks', 'data.0'], ['tasks/'.$parent->public_id, 'data'], ['projects/'.$project->public_id, 'data.tasks.0']] as [$path, $key]) {
                $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/'.$path)->assertOk()->assertJsonMissingPath($key.'.completion_advisory');
            }
            $reply = $this->callTool($this->initialize(), 'tasks.get', ['workspace_id' => $workspace->public_id, 'task_id' => $parent->public_id]);
            $this->assertArrayNotHasKey('completion_advisory', $reply['result']['structuredContent']['data']);
        }
    }

    public function test_a_reused_internal_model_cannot_publish_its_advisory_to_a_client_presenter_or_with_the_feature_off(): void
    {
        [$workspace, , , , $parent] = $this->fixture();
        $task = app(WorkspaceTaskRepository::class)->find($workspace, $parent->public_id);
        app(TaskCompletionAdvisory::class)->load($workspace, [$task]);
        $this->assertFalse($task->isDirty(), 'Read metadata must never become task columns waiting to be saved.');
        $presenter = app(AgentTaskPresenter::class);
        $this->assertSame($this->message(), $presenter->present($workspace, $task, includePlanning: true)['completion_advisory']);
        $this->assertArrayNotHasKey('completion_advisory', $presenter->present($workspace, $task, includePlanning: false));
        config(['task_organization.enabled' => false]);
        $this->assertArrayNotHasKey('completion_advisory', $presenter->present($workspace, $task, includePlanning: true));
        $this->assertArrayNotHasKey('completionAdvisory', $task->toArray());
    }

    public function test_batches_are_bounded_and_only_unfinished_direct_children_in_the_exact_parent_scope_trigger_the_advisory(): void
    {
        [$workspace, $company, $project, , $parent, $child] = $this->fixture();
        $completedOnly = $this->task($workspace, $project, 'Synthetic completed children only');
        $completed = $this->task($workspace, $project, 'Synthetic completed direct child', $completedOnly);
        $completed->forceFill(['status' => 'completed', 'completed_at' => '2026-10-22 12:00:00'])->save();
        $this->task($workspace, $project, 'Synthetic incomplete grandchild', $completed);
        $legacyOnly = $this->task($workspace, $project, 'Synthetic foreign children only');
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic other project']);
        $this->writingLegacyCrossTenantRows(function () use ($workspace, $otherProject, $legacyOnly): void {
            $this->task($workspace, $otherProject, 'Synthetic foreign project child', $legacyOnly);
        });
        $foreignWorkspace = Workspace::query()->create(['name' => 'Synthetic foreign advisory workspace', 'slug' => 'synthetic-foreign-advisory']);
        $this->writingLegacyCrossTenantRows(function () use ($foreignWorkspace, $project, $legacyOnly): void {
            $this->task($foreignWorkspace, $project, 'Synthetic foreign workspace child', $legacyOnly);
        });
        $parents = [$parent, $completedOnly, $legacyOnly];
        for ($index = 0; $index < 202; $index++) {
            $parents[] = $this->task($workspace, $project, 'Synthetic batch parent '.$index);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(TaskCompletionAdvisory::class)->load($workspace, $parents);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, $queries, 'A 205-row input needs three bounded aggregate loads, never a query for each parent.');
        foreach ($queries as $query) {
            $this->assertLessThanOrEqual(103, count($query['bindings']));
        }
        $presenter = app(TaskCompletionAdvisory::class);
        $this->assertSame(['completion_advisory' => $this->message()], $presenter->fields($parent));
        foreach (array_slice($parents, 1) as $task) {
            $this->assertSame(['completion_advisory' => null], $presenter->fields($task));
        }
        $this->assertSame('open', $child->fresh()->status);
        config(['task_organization.enabled' => false]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $presenter->load($workspace, $parents);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_cli_completion_preview_keeps_the_advisory_and_rolls_back_then_apply_completes_only_the_parent(): void
    {
        [$workspace, , , $owner, $parent, $child] = $this->fixture();
        $input = tempnam(sys_get_temp_dir(), 'svc-completion-advisory-');
        try {
            file_put_contents($input, json_encode(['status' => 'completed', 'expected_version' => AgentApiVersion::for($parent)], JSON_THROW_ON_ERROR));
            $args = ['operation' => 'update', 'workspace' => $workspace->public_id, '--actor' => $owner->public_id,
                '--task' => $parent->public_id, '--input' => $input, '--key' => 'synthetic-parent-completion', '--format' => 'json'];
            $this->assertSame(0, Artisan::call('svc:tasks', $args));
            $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($preview['dry_run']);
            $this->assertSame($this->message(), $preview['data']['completion_advisory']);
            $this->assertSame('completed', $preview['data']['status']);
            $this->assertSame('open', $parent->fresh()->status);
            $this->assertSame('open', $child->fresh()->status);
            $this->assertDatabaseCount('agent_mutation_receipts', 0);
            $this->assertDatabaseCount('agent_mutation_audits', 0);
            $this->assertSame(0, Artisan::call('svc:tasks', $args + ['--apply' => true]));
            $this->assertSame('completed', $parent->fresh()->status);
            $this->assertNotNull($parent->fresh()->completed_at);
            $this->assertSame('open', $child->fresh()->status);
            $this->assertNull($child->fresh()->completed_at);
            $this->assertDatabaseCount('client_task_graph_states', 0);
        } finally {
            unlink($input);
        }
    }

    public function test_agent_mutation_responses_keep_the_advisory_without_completing_or_rebilling_children(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$workspace, , $project, $owner, $parent, $child] = $this->fixture();
        $child->forceFill(['milestone_price_amount' => 4200])->save();
        $this->actingAsMcp($owner, ['tasks:read', 'tasks:write']);
        $base = '/api/v1/workspaces/'.$workspace->public_id;
        $this->withHeader('Idempotency-Key', 'synthetic-advisory-parent-completion')->patchJson($base.'/tasks/'.$parent->public_id,
            ['expected_version' => AgentApiVersion::for($parent), 'status' => 'completed'])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completion_advisory', $this->message());
        $this->withHeader('Idempotency-Key', 'synthetic-advisory-create')->postJson($base.'/projects/'.$project->public_id.'/tasks', ['title' => 'Synthetic newly created task'])
            ->assertCreated()->assertJsonPath('data.completion_advisory', null);
        $this->assertSame('open', $child->fresh()->status);
        $this->assertNull($child->fresh()->completed_at);
        $this->assertSame(4200, $child->fresh()->milestone_price_amount);
        $this->assertNull($child->fresh()->client_invoice_line_id);
    }

    public function test_subtask_and_prerequisite_advisories_have_independent_switches_and_bounded_scoped_facts(): void
    {
        [$workspace, , $project, , $parent] = $this->fixture();
        $prerequisite = $this->task($workspace, $project, 'Synthetic unfinished prerequisite');
        ClientTaskDependency::query()->create(['workspace_id' => $workspace->id,
            'client_project_id' => $project->id, 'client_task_id' => $parent->id, 'prerequisite_task_id' => $prerequisite->id]);
        foreach ([[false, true, 'This task has unfinished prerequisites. Completing it leaves those prerequisites unchanged.'],
            [true, false, $this->message()],
            [true, true, 'This task has unfinished subtasks and prerequisites. Completing it leaves them unchanged.'],
            [false, false, null]] as [$organization, $planning, $message]) {
            config(['task_organization.enabled' => $organization, 'task_planning_details.enabled' => $planning]);
            $record = $parent->fresh();
            DB::enableQueryLog();
            DB::flushQueryLog();
            app(TaskCompletionAdvisory::class)->load($workspace, [$record]);
            $this->assertCount((int) $organization + (int) $planning, DB::getQueryLog());
            DB::disableQueryLog();
            $fields = app(TaskCompletionAdvisory::class)->fields($record);
            if ($message === null) {
                $this->assertSame([], $fields);
            } else {
                $this->assertSame($message, $fields['completion_advisory']);
            }
            $this->assertFalse($record->isDirty());
        }
        config(['task_planning_details.enabled' => true]);
        $prerequisite->update(['status' => 'completed']);
        $record = $parent->fresh();
        app(TaskCompletionAdvisory::class)->load($workspace, [$record]);
        $this->assertNull(app(TaskCompletionAdvisory::class)->fields($record)['completion_advisory']);
        $this->assertSame('open', $parent->fresh()->status);
        $this->assertDatabaseCount('client_task_graph_states', 0);
    }

    public function test_unfinished_prerequisite_warns_on_completion_without_changing_prerequisite_or_financial_state(): void
    {
        [$workspace, , $project, $owner, $parent] = $this->fixture();
        config(['task_organization.enabled' => false, 'task_planning_details.enabled' => true, 'agent_api.writes_enabled' => true]);
        $prerequisite = $this->task($workspace, $project, 'Synthetic independent prerequisite');
        $prerequisite->forceFill(['milestone_price_amount' => 4200])->save();
        $version = AgentApiVersion::for($prerequisite);
        ClientTaskDependency::query()->create(['workspace_id' => $workspace->id,
            'client_project_id' => $project->id, 'client_task_id' => $parent->id, 'prerequisite_task_id' => $prerequisite->id]);
        $this->actingAsMcp($owner, ['tasks:read', 'tasks:write']);
        $this->withHeader('Idempotency-Key', 'synthetic-complete-with-prerequisite')
            ->patchJson('/api/v1/workspaces/'.$workspace->public_id.'/tasks/'.$parent->public_id,
                ['expected_version' => AgentApiVersion::for($parent), 'status' => 'completed'])
            ->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completion_advisory', 'This task has unfinished prerequisites. Completing it leaves those prerequisites unchanged.');
        $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/tasks/'.$parent->public_id)->assertOk()
            ->assertJsonPath('data.planning_details.dependency_summary.blocked', true);
        $this->assertSame('open', $prerequisite->fresh()->status);
        $this->assertSame($version, AgentApiVersion::for($prerequisite->fresh()));
        $this->assertSame(4200, $prerequisite->fresh()->milestone_price_amount);
        $this->assertNull($prerequisite->fresh()->client_invoice_line_id);
        $this->assertDatabaseCount('client_task_dependencies', 1);
    }

    private function message(): string
    {
        return 'This task has unfinished subtasks. Completing it leaves those subtasks unchanged.';
    }

    /** @return array{Workspace,ClientCompany,ClientProject,User,ClientTask,ClientTask} */
    private function fixture(): array
    {
        config(['task_organization.enabled' => true]);
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic advisory workspace', 'slug' => 'synthetic-advisory']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic advisory company', 'slug' => 'synthetic-advisory-company']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic advisory project', 'is_visible_to_client' => true]);
        $parent = $this->task($workspace, $project, 'Synthetic public parent');
        $child = $this->task($workspace, $project, 'Synthetic private unfinished child secret', $parent);
        $child->forceFill(['is_visible_to_client' => false])->save();

        return [$workspace, $company, $project, $owner, $parent, $child];
    }

    private function task(Workspace $workspace, ClientProject $project, string $title, ?ClientTask $parent = null): ClientTask
    {
        $task = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => $title, 'is_visible_to_client' => true]);
        if ($parent !== null) {
            $task->forceFill(['parent_task_id' => $parent->id])->save();
        }

        return $task;
    }
}
