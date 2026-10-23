<?php

namespace Tests\Feature\Tasks\Organization;

use App\Models\AgentMutationAudit;
use App\Models\AgentMutationReceipt;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\ClientTaskGraphState;
use App\Models\ClientTaskList;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentTaskOrganizationMutationAction;
use App\Services\Tasks\Organization\TaskContainer;
use App\Services\Tasks\Organization\TaskOrganizationAction;
use App\Services\Tasks\Organization\TaskOrganizationCommand;
use App\Services\Tasks\TaskMutationOrigin;
use App\Services\Tasks\TaskPatchData;
use App\Services\Tasks\TaskVisibility;
use App\Services\WorkspaceTaskMutationAction;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class TaskOrganizationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_nested_raw_cli_assignees_use_the_foundation_array_guard_and_preview_never_creates_rows(): void
    {
        [$workspace, $project, $owner] = $this->fixture();
        $version = app(TaskOrganizationAction::class)->inspect($owner, $workspace, $project->public_id)->graphVersion();
        $input = tempnam(sys_get_temp_dir(), 'svc-organization-assignees-');
        try {
            foreach (['', ' ', null, 17] as $index => $assignees) {
                file_put_contents($input, json_encode(['parent_id' => null, 'list_id' => null, 'expected_graph_version' => $version,
                    'task' => ['title' => 'Synthetic nested raw task', 'assignee_ids' => $assignees]], JSON_THROW_ON_ERROR));
                $this->assertSame(2, Artisan::call('svc:tasks:organization', ['operation' => 'tasks.create', 'workspace' => $workspace->public_id,
                    'project' => $project->public_id, '--actor' => $owner->public_id, '--input' => $input, '--key' => 'synthetic-invalid-nested-assignees-'.$index, '--format' => 'json']));
                $this->assertStringContainsString('assignee_ids', Artisan::output());
                foreach (['client_tasks', 'client_task_assignments', 'client_task_graph_states', 'agent_mutation_receipts', 'agent_mutation_audits'] as $table) {
                    $this->assertDatabaseCount($table, 0);
                }
            }
        } finally {
            unlink($input);
        }
    }

    public function test_raw_cli_empty_visibility_is_rejected_before_preview_or_apply_and_preserves_existing_publication(): void
    {
        [$workspace, $project, $owner] = $this->fixture();
        $action = app(TaskOrganizationAction::class);
        $listId = $action->perform($owner, $workspace, $project->public_id, TaskOrganizationCommand::fromInput('lists.create',
            ['name' => 'Synthetic published list', 'is_visible_to_client' => true, 'expected_graph_version' => $action->inspect($owner, $workspace, $project->public_id)->graphVersion()]))[0];
        $graph = $action->inspect($owner, $workspace, $project->public_id);
        $list = ClientTaskList::query()->where('workspace_id', $workspace->id)->where('public_id', $listId)->sole();
        $version = AgentApiVersion::for($list);
        $input = tempnam(sys_get_temp_dir(), 'svc-organization-visibility-');
        try {
            foreach (['', ' ', null] as $index => $value) {
                file_put_contents($input, json_encode(['list_id' => $listId, 'is_visible_to_client' => $value,
                    'expected_version' => $version, 'expected_graph_version' => $graph->graphVersion()], JSON_THROW_ON_ERROR));
                $args = ['operation' => 'lists.update', 'workspace' => $workspace->public_id, 'project' => $project->public_id,
                    '--actor' => $owner->public_id, '--input' => $input, '--key' => 'synthetic-invalid-visibility-'.$index, '--format' => 'json'];
                foreach ([false, true] as $apply) {
                    $this->assertSame(2, Artisan::call('svc:tasks:organization', $args + ($apply ? ['--apply' => true] : [])));
                    $this->assertTrue($list->fresh()->is_visible_to_client);
                    $this->assertSame($version, AgentApiVersion::for($list->fresh()));
                    $this->assertSame($graph->graphVersion(), $action->inspect($owner, $workspace, $project->public_id)->graphVersion());
                    $this->assertDatabaseCount('agent_mutation_receipts', 0);
                    $this->assertDatabaseCount('agent_mutation_audits', 0);
                }
            }
        } finally {
            unlink($input);
        }
    }

    public function test_creation_appends_after_manual_order_and_same_container_moves_preserve_that_order(): void
    {
        [$workspace, $project, $owner] = $this->fixture();
        $first = $this->task($workspace, $project, 'Synthetic older task');
        $second = $this->task($workspace, $project, 'Synthetic newer task');
        $action = app(TaskOrganizationAction::class);
        $root = new TaskContainer(null, null);
        $graph = $action->inspect($owner, $workspace, $project->public_id);
        $action->perform($owner, $workspace, $project->public_id, TaskOrganizationCommand::fromInput('tasks.reorder',
            ['parent_id' => null, 'list_id' => null, 'ordered_ids' => [$second->public_id, $first->public_id], 'expected_container_version' => $graph->containerVersion($root)]));
        $this->move($action, $owner, $workspace, $project, $second, null, null);
        $graph = $action->inspect($owner, $workspace, $project->public_id);
        $this->assertSame([$second->public_id, $first->public_id], $graph->siblings($root));
        $thirdId = $action->perform($owner, $workspace, $project->public_id, TaskOrganizationCommand::fromInput('tasks.create',
            ['parent_id' => null, 'list_id' => null, 'task' => ['title' => 'Synthetic appended task'], 'expected_graph_version' => $graph->graphVersion()]))[0];
        $this->assertSame([$second->public_id, $first->public_id, $thirdId], $action->inspect($owner, $workspace, $project->public_id)->siblings($root));
    }

    public function test_organization_caps_do_not_block_existing_metadata_on_large_flat_projects(): void
    {
        [$workspace, $project, $owner] = $this->fixture();
        $rows = [];
        for ($index = 0; $index < 1001; $index++) {
            $rows[] = ['workspace_id' => $workspace->id, 'client_project_id' => $project->id,
                'public_id' => (string) Str::uuid(), 'title' => 'Synthetic large flat task '.$index];
        }
        ClientTask::query()->insert($rows);
        $task = ClientTask::query()->where('workspace_id', $workspace->id)->orderBy('id')->firstOrFail();
        app(WorkspaceTaskMutationAction::class)->update($owner, $workspace, $task->public_id,
            TaskPatchData::fromValidated(['title' => 'Synthetic metadata remains available']), AgentApiVersion::for($task));
        $this->assertSame('Synthetic metadata remains available', $task->fresh()->title);
        try {
            app(TaskOrganizationAction::class)->inspect($owner, $workspace, $project->public_id);
            $this->fail('The organization read silently omitted over-budget tasks.');
        } catch (ValidationException $exception) {
            $this->assertSame(['organization' => ['Organization operations support at most 1000 tasks and 100 lists per project.']], $exception->errors());
        }
        $this->assertDatabaseCount('client_task_graph_states', 0);
        $this->assertDatabaseCount('client_tasks', 1001);
    }

    public function test_graph_mutations_take_registered_locks_in_order(): void
    {
        [$workspace, $project, $owner] = $this->fixture();
        $root = $this->task($workspace, $project, 'Synthetic lock root');
        $child = $this->task($workspace, $project, 'Synthetic lock child');
        $action = app(TaskOrganizationAction::class);
        LockOrderRecorder::start();
        try {
            $this->move($action, $owner, $workspace, $project, $child, $root->public_id, null);
            $sequence = LockOrderRecorder::sequences()[0];
            $this->assertSame([
                LockResource::BillingMaintenance,
                LockResource::Workspace,
                LockResource::ClientTaskGraphState,
                LockResource::ClientTask,
                LockResource::ClientTaskList,
                LockResource::WorkspaceMembership,
                LockResource::ClientProjectMembership,
                LockResource::ClientCompanyMembership,
            ], $sequence);
            $ranks = array_map(static fn (LockResource $resource): int => $resource->rank(), $sequence);
            $sorted = $ranks;
            sort($sorted);
            $this->assertSame($sorted, $ranks);
        } finally {
            LockOrderRecorder::stop();
        }
    }

    public function test_cli_previews_leave_no_state_receipts_audits_or_assignments_and_feature_off_hides_management(): void
    {
        [$workspace, $project, $owner] = $this->fixture();
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id, 'user_id' => $owner->id, 'role' => 'client']);
        $action = app(TaskOrganizationAction::class);
        $input = tempnam(sys_get_temp_dir(), 'svc-organization-cli-');
        $base = ['workspace' => $workspace->public_id, 'project' => $project->public_id, '--actor' => $owner->public_id, '--format' => 'json'];
        try {
            file_put_contents($input, json_encode(['parent_id' => null, 'list_id' => null,
                'expected_graph_version' => $action->inspect($owner, $workspace, $project->public_id)->graphVersion(),
                'task' => ['title' => 'Synthetic CLI child', 'assignee_ids' => [$owner->public_id]]], JSON_THROW_ON_ERROR));
            $command = $base + ['operation' => 'tasks.create', '--key' => 'synthetic-cli-organization', '--input' => $input];
            $this->assertSame(0, Artisan::call('svc:tasks:organization', $command));
            $this->assertTrue(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['dry_run']);
            foreach (['client_tasks', 'client_task_assignments', 'client_task_graph_states', 'agent_mutation_receipts', 'agent_mutation_audits'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
            $this->assertSame(0, Artisan::call('svc:tasks:organization', $base + ['operation' => 'get']));
            $this->assertDatabaseCount('client_task_graph_states', 0);
            $this->assertSame(0, Artisan::call('svc:tasks:organization', $command + ['--apply' => true]));
            $this->assertFalse(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['dry_run']);
            $this->assertSame(0, Artisan::call('svc:tasks:organization', $command + ['--apply' => true]));
            foreach (['client_tasks', 'client_task_assignments', 'client_task_graph_states', 'agent_mutation_receipts'] as $table) {
                $this->assertDatabaseCount($table, 1);
            }
            $this->assertSame(0, Artisan::call('svc:tasks:organization', $base + ['operation' => 'get', '--container' => true]));
            $view = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['data'];
            $this->assertSame('Synthetic CLI child', $view['container']['tasks'][0]['title']);
            $this->assertArrayNotHasKey('workspace_id', $view['tree'][0]);
            $this->assertArrayNotHasKey('description', $view['container']['tasks'][0]);
            config(['task_organization.enabled' => false]);
            $this->assertTrue(app(\App\Console\Commands\Tasks\TaskOrganizationCommand::class)->isHidden());
            $this->assertSame(1, Artisan::call('svc:tasks:organization', $command + ['--apply' => true]));
            $this->assertDatabaseCount('client_tasks', 1);
            $this->assertDatabaseCount('agent_mutation_receipts', 1);
        } finally {
            unlink($input);
        }
    }

    public function test_shared_receipt_replay_requires_current_authority_and_preserves_native_dual_policy(): void
    {
        [$workspace, $project, $owner] = $this->fixture();
        $action = app(TaskOrganizationAction::class);
        $writes = app(AgentTaskOrganizationMutationAction::class);
        $input = ['name' => 'Synthetic receipt list', 'expected_graph_version' => $action->inspect($owner, $workspace, $project->public_id)->graphVersion()];
        $ids = $writes->execute($owner, $workspace, 'synthetic-org-client', 'synthetic-org-key', $project->public_id, 'lists.create', $input);
        $this->assertSame($ids, $writes->execute($owner, $workspace, 'synthetic-org-client', 'synthetic-org-key', $project->public_id, 'lists.create', $input));
        $this->assertDatabaseCount('client_task_lists', 1);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'task_organization.lists.create', 'outcome' => 'replay']);
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id, 'user_id' => $owner->id, 'role' => 'client']);
        try {
            $writes->execute($owner, $workspace, 'synthetic-org-client', 'synthetic-org-key', $project->public_id, 'lists.create', $input);
            $this->fail('A replay used former agent authority.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $nativeInput = ['name' => 'Synthetic native list', 'expected_graph_version' => $action->inspect($owner, $workspace, $project->public_id)->graphVersion()];
        $this->assertCount(1, $writes->executeInternal($owner, $workspace, 'synthetic-org-native', 'synthetic-native-key', $project->public_id, 'lists.create', $nativeInput));
        $this->assertDatabaseCount('client_task_lists', 2);
        $workspace->memberships()->where('user_id', $owner->id)->update(['role' => 'member']);
        try {
            $writes->executeInternal($owner, $workspace, 'synthetic-org-native', 'synthetic-native-key', $project->public_id, 'lists.create', $nativeInput);
            $this->fail('A replay ignored the current native role.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_subtask_creation_uses_current_responsibility_without_granting_access(): void
    {
        [$workspace, $project, $owner] = $this->fixture();
        $root = $this->task($workspace, $project, 'Synthetic root');
        $target = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $target->id, 'role' => 'member']);
        $action = app(TaskOrganizationAction::class);
        $input = ['parent_id' => $root->public_id, 'list_id' => null,
            'expected_graph_version' => $action->inspect($owner, $workspace, $project->public_id)->graphVersion(),
            'task' => ['title' => 'Synthetic new subtask', 'assignee_ids' => [$target->public_id]]];
        try {
            $action->perform($owner, $workspace, $project->public_id, TaskOrganizationCommand::fromInput('tasks.create', $input));
            $this->fail('Assignment granted project access.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignee_ids', $exception->errors());
        }
        $this->assertDatabaseCount('client_tasks', 1);
        $this->assertDatabaseCount('client_task_graph_states', 0);
        $this->assertDatabaseCount('client_project_memberships', 0);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $target->id, 'role' => 'contributor']);
        $id = $action->perform($owner, $workspace, $project->public_id, TaskOrganizationCommand::fromInput('tasks.create', $input))[0];
        $task = ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->sole();
        $this->assertSame($root->id, $task->parent_task_id);
        $this->assertNull($task->client_task_list_id);
        $this->assertFalse($task->is_visible_to_client);
        $this->assertSame('open', $task->status);
        $this->assertNull($task->completed_at);
        $this->assertSame([$target->id], $task->assignments->pluck('user_id')->all());
        $this->assertDatabaseCount('client_project_memberships', 1);
    }

    public function test_inspection_never_creates_durable_state_including_current_locked_inspection(): void
    {
        [$workspace, $project, $owner] = $this->fixture();
        $this->task($workspace, $project, 'Synthetic read-only task');
        $action = app(TaskOrganizationAction::class);
        foreach ([false, true] as $current) {
            $before = [ClientTaskGraphState::query()->count(), AgentMutationReceipt::query()->count(), AgentMutationAudit::query()->count()];
            $graph = $action->inspect($owner, $workspace, $project->public_id, current: $current);
            $this->assertCount(1, $graph->tasks);
            $this->assertSame($before, [ClientTaskGraphState::query()->count(), AgentMutationReceipt::query()->count(), AgentMutationAudit::query()->count()]);
            $this->assertSame(0, $graph->stateVersion);
        }
        $this->assertDatabaseCount('client_task_graph_states', 0);
    }

    public function test_list_move_and_archive_preserve_task_facts_and_disclosure_with_the_flag_off(): void
    {
        [$workspace, $project, $owner] = $this->fixture();
        $task = $this->task($workspace, $project, 'Synthetic completed milestone');
        $task->forceFill(['status' => 'completed', 'completed_at' => '2026-01-01 12:00:00', 'milestone_price_amount' => 4200])->save();
        $child = $this->task($workspace, $project, 'Synthetic child');
        $action = app(TaskOrganizationAction::class);
        $graph = $action->inspect($owner, $workspace, $project->public_id);
        $listId = $action->perform($owner, $workspace, $project->public_id, TaskOrganizationCommand::fromInput('lists.create',
            ['name' => 'Synthetic private list', 'expected_graph_version' => $graph->graphVersion()]))[0];
        $this->assertFalse(ClientTaskList::query()->where('workspace_id', $workspace->id)->where('public_id', $listId)->sole()->is_visible_to_client);
        $before = $task->fresh()->getAttributes();
        $this->move($action, $owner, $workspace, $project, $task, null, $listId);
        $this->move($action, $owner, $workspace, $project, $child, $task->public_id, null);
        $graph = $action->inspect($owner, $workspace, $project->public_id);
        $this->assertSame($listId, $graph->rootList($child->public_id));
        $after = $task->fresh()->getAttributes();
        foreach (['workspace_id', 'client_project_id', 'title', 'description', 'status', 'completed_at', 'milestone_price_amount', 'client_invoice_line_id', 'is_visible_to_client'] as $field) {
            $this->assertSame($before[$field], $after[$field], $field);
        }
        $client = User::factory()->create();
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id, 'user_id' => $client->id, 'role' => 'client']);
        $this->assertFalse(app(TaskVisibility::class)->constrainPortal(ClientTask::query()->whereKey($child->id), $workspace, $client)->exists());
        $list = ClientTaskList::query()->where('workspace_id', $workspace->id)->where('public_id', $listId)->sole();
        $action->perform($owner, $workspace, $project->public_id, TaskOrganizationCommand::fromInput('lists.update',
            ['list_id' => $listId, 'is_visible_to_client' => true, 'expected_version' => AgentApiVersion::for($list), 'expected_graph_version' => $graph->graphVersion()]));
        $this->assertTrue(app(TaskVisibility::class)->constrainPortal(ClientTask::query()->whereKey($child->id), $workspace, $client)->exists());
        $graph = $action->inspect($owner, $workspace, $project->public_id);
        $action->perform($owner, $workspace, $project->public_id, TaskOrganizationCommand::fromInput('lists.archive',
            ['list_id' => $listId, 'confirm' => true, 'expected_version' => AgentApiVersion::for($list->fresh()), 'expected_graph_version' => $graph->graphVersion()]));
        $this->assertSoftDeleted($list);
        $this->assertFalse(app(TaskVisibility::class)->constrainPortal(ClientTask::query()->whereKey($child->id), $workspace, $client)->exists());
        config(['task_organization.enabled' => false]);
        $this->assertFalse(app(TaskVisibility::class)->constrainPortal(ClientTask::query()->whereKey($child->id), $workspace, $client)->exists());
        $this->expectException(HttpExceptionInterface::class);
        $action->inspect($owner, $workspace, $project->public_id);
    }

    public function test_reorder_requires_exact_siblings_and_rejects_metadata_staleness(): void
    {
        [$workspace, $project, $owner] = $this->fixture();
        $first = $this->task($workspace, $project, 'Synthetic first');
        $second = $this->task($workspace, $project, 'Synthetic second');
        $action = app(TaskOrganizationAction::class);
        $container = new TaskContainer(null, null);
        $graph = $action->inspect($owner, $workspace, $project->public_id);
        try {
            $action->perform($owner, $workspace, $project->public_id, TaskOrganizationCommand::fromInput('tasks.reorder',
                ['parent_id' => null, 'list_id' => null, 'ordered_ids' => [$first->public_id], 'expected_container_version' => $graph->containerVersion($container)]));
            $this->fail('An omitted sibling was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['organization' => ['Supply the complete current sibling order.']], $exception->errors());
        }
        $action->perform($owner, $workspace, $project->public_id, TaskOrganizationCommand::fromInput('tasks.reorder',
            ['parent_id' => null, 'list_id' => null, 'ordered_ids' => [$second->public_id, $first->public_id], 'expected_container_version' => $graph->containerVersion($container)]));
        $graph = $action->inspect($owner, $workspace, $project->public_id);
        $this->assertSame([$second->public_id, $first->public_id], $graph->siblings($container));
        app(WorkspaceTaskMutationAction::class)->update($owner, $workspace, $first->public_id, TaskPatchData::fromValidated(['title' => 'Synthetic edited title']), AgentApiVersion::for($first->fresh()));
        try {
            $action->perform($owner, $workspace, $project->public_id, TaskOrganizationCommand::fromInput('tasks.reorder',
                ['parent_id' => null, 'list_id' => null, 'ordered_ids' => [$first->public_id, $second->public_id], 'expected_container_version' => $graph->containerVersion($container)]));
            $this->fail('A stale sibling revision was accepted.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_agent_dual_authority_and_cross_project_moves_are_refused_without_changing_topology(): void
    {
        [$workspace, $project, $owner] = $this->fixture();
        $task = $this->task($workspace, $project, 'Synthetic scoped task');
        $other = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id, 'name' => 'Synthetic other project']);
        $foreign = $this->task($workspace, $other, 'Synthetic foreign task secret');
        $action = app(TaskOrganizationAction::class);
        try {
            $this->move($action, $owner, $workspace, $project, $task, $foreign->public_id, null);
            $this->fail('A foreign project parent was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringNotContainsString($foreign->title, json_encode($exception->errors(), JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString($foreign->public_id, json_encode($exception->errors(), JSON_THROW_ON_ERROR));
        }
        $graph = $action->inspect($owner, $workspace, $project->public_id);
        $command = TaskOrganizationCommand::fromInput('lists.create', ['name' => 'Synthetic dual list', 'expected_graph_version' => $graph->graphVersion()]);
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id, 'user_id' => $owner->id, 'role' => 'client']);
        try {
            $action->perform($owner, $workspace, $project->public_id, $command, TaskMutationOrigin::Agent);
            $this->fail('A dual agent inherited native management authority.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('client_task_lists', 0);
        $this->assertNull($task->fresh()->parent_task_id);
        $this->assertCount(1, $action->perform($owner, $workspace, $project->public_id, $command));
    }

    /** @return array{Workspace,ClientProject,User} */
    private function fixture(): array
    {
        config(['task_organization.enabled' => true]);
        $workspace = Workspace::query()->create(['name' => 'Synthetic task organization', 'slug' => 'synthetic-task-organization']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic organization company', 'slug' => 'synthetic-organization-company']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic organized project', 'is_visible_to_client' => true]);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$workspace, $project, $owner];
    }

    private function task(Workspace $workspace, ClientProject $project, string $title): ClientTask
    {
        return ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => $title, 'is_visible_to_client' => true]);
    }

    private function move(TaskOrganizationAction $action, User $actor, Workspace $workspace, ClientProject $project, ClientTask $task, ?string $parent, ?string $list): void
    {
        $graph = $action->inspect($actor, $workspace, $project->public_id);
        $action->perform($actor, $workspace, $project->public_id, TaskOrganizationCommand::fromInput('tasks.move',
            ['task_id' => $task->public_id, 'parent_id' => $parent, 'list_id' => $list, 'expected_graph_version' => $graph->graphVersion(), 'expected_version' => AgentApiVersion::for($task->fresh())]));
    }
}
