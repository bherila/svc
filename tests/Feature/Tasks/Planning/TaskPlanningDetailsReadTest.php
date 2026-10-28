<?php

namespace Tests\Feature\Tasks\Planning;

use App\Exceptions\InvalidAgentApiCursor;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientContact;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\ClientTaskContactResponsibility;
use App\Models\ClientTaskDependency;
use App\Models\ClientTaskLabel;
use App\Models\ClientTaskLabelAssignment;
use App\Models\ClientTaskList;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentReadService;
use App\Services\Tasks\Planning\TaskPlanningDetailsPresenter;
use App\Services\Tasks\Planning\TaskPlanningDetailsReadService;
use App\Services\Tasks\TaskMutationOrigin;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentPageBudget;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mcp\Capability\Formatter\ToolResultFormatter;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class TaskPlanningDetailsReadTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    public function test_default_off_omits_bulk_facts_without_iterating_or_querying_and_direct_reads_refuse(): void
    {
        [$workspace, , $owner, $task] = $this->fixture();
        config(['task_planning_details.enabled' => false, 'task_organization.enabled' => true]);
        $neverIterated = (function () {
            throw new \LogicException('The disabled reader iterated task input.');
            yield;
        })();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame([], $this->reader()->summariesForAuthorizedTasks($owner, $workspace, TaskMutationOrigin::Internal, $neverIterated));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->expectReadStatus(404, fn () => $this->reader()->get($owner, $workspace, TaskMutationOrigin::Internal, $task->public_id));
        $this->assertDatabaseCount('client_task_graph_states', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_current_prerequisite_status_and_catalog_name_change_without_dependent_task_version(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        $prerequisite = $this->task($workspace, $project, 'Synthetic prerequisite');
        $dependent = $this->task($workspace, $project, 'Synthetic dependent');
        $this->edge($workspace, $project, $task, $prerequisite);
        $this->edge($workspace, $project, $dependent, $task);
        $label = $this->label($workspace, 'Synthetic label');
        $this->attach($workspace, $task, $label);
        $version = AgentApiVersion::for($task);
        $view = $this->reader()->get($owner, $workspace, TaskMutationOrigin::Agent, $task->public_id);
        $this->assertSame(['blocked' => true, 'incomplete_prerequisite_count' => 1, 'dependent_count' => 1], $view->dependencySummary);
        $this->assertSame($version, $view->version);
        $prerequisite->forceFill(['status' => 'completed'])->save();
        $label->forceFill(['name' => 'Équipe renamed', 'is_archived' => true])->save();
        $fresh = $this->reader()->get($owner, $workspace, TaskMutationOrigin::Agent, $task->public_id);
        $this->assertSame($version, $fresh->version);
        $this->assertSame($version, AgentApiVersion::for($task->fresh()));
        $this->assertSame(['blocked' => false, 'incomplete_prerequisite_count' => 0, 'dependent_count' => 1], $fresh->dependencySummary);
        $this->assertSame('Équipe renamed', $fresh->labels[0]['name']);
        $this->assertTrue($fresh->labels[0]['is_archived']);
        $this->assertNotSame($view->labels[0]['version'], $fresh->labels[0]['version']);
        $prerequisite->forceFill(['status' => 'in_progress'])->save();
        $this->assertTrue($this->reader()->get($owner, $workspace, TaskMutationOrigin::Agent, $task->public_id)->dependencySummary['blocked']);
        $this->assertSame('open', $task->fresh()->status);
        $this->assertNull($task->fresh()->completed_at);
        $this->assertDatabaseCount('client_task_graph_states', 0);
    }

    public function test_role_scope_and_dual_agent_omissions_preserve_trusted_internal_staff_access(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        $manager = $this->member($workspace, $project, 'manager');
        $viewer = $this->member($workspace, $project, 'viewer');
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id, 'name' => 'Synthetic other project']);
        $otherTask = $this->task($workspace, $otherProject, 'Synthetic unauthorized task');
        $this->assertSame($task->public_id, $this->reader()->get($viewer, $workspace, TaskMutationOrigin::Agent, $task->public_id)->taskId);
        $this->expectReadStatus(404, fn () => $this->reader()->get($viewer, $workspace, TaskMutationOrigin::Agent, $otherTask->public_id));
        $this->expectReadStatus(403, fn () => $this->reader()->labels($manager, $workspace, TaskMutationOrigin::Agent));
        $this->expectReadStatus(403, fn () => $this->reader()->contacts($manager, $workspace, TaskMutationOrigin::Internal, $task->public_id));
        $this->expectReadStatus(403, fn () => $this->reader()->contactOptions($viewer, $workspace, TaskMutationOrigin::Agent, $task->public_id));
        $this->expectReadStatus(403, fn () => $this->reader()->labelOptions($viewer, $workspace, TaskMutationOrigin::Agent, $project->public_id));
        $this->expectReadStatus(403, fn () => $this->reader()->dependencyOptions($viewer, $workspace, TaskMutationOrigin::Agent, $task->public_id));
        $this->expectReadStatus(403, fn () => $this->reader()->labelOptions($manager, $workspace, TaskMutationOrigin::Agent, $otherProject->public_id));
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id, 'user_id' => $owner->id, 'role' => 'client']);
        $this->assertSame([], $this->reader()->summariesForAuthorizedTasks($owner, $workspace, TaskMutationOrigin::Agent, [$task]));
        foreach (['get', 'contacts', 'contactOptions', 'dependencies', 'dependencyOptions'] as $method) {
            $this->expectReadStatus(403, fn () => $this->reader()->$method($owner, $workspace, TaskMutationOrigin::Agent, $task->public_id));
        }
        $this->expectReadStatus(403, fn () => $this->reader()->labels($owner, $workspace, TaskMutationOrigin::Agent));
        $this->assertSame($task->public_id, $this->reader()->get($owner, $workspace, TaskMutationOrigin::Internal, $task->public_id)->taskId);
        $this->assertSame([], $this->reader()->contacts($owner, $workspace, TaskMutationOrigin::Internal, $task->public_id)['data']['contacts']);
    }

    public function test_catalog_and_options_traverse_strict_actor_query_bound_pages_without_foreign_or_archived_rows(): void
    {
        [$workspace, $project, $owner] = $this->fixture();
        [$foreignWorkspace, $foreignProject, $foreignOwner] = $this->fixture('foreign');
        $foreign = $this->label($foreignWorkspace, 'Synthetic foreign label');
        $labels = [];
        for ($index = 1; $index <= 31; $index++) {
            $labels[] = $this->label($workspace, 'Synthetic label '.$index);
        }
        $archived = $this->label($workspace, 'Synthetic archived label');
        $archived->forceFill(['is_archived' => true])->save();
        $manager = $this->member($workspace, $project, 'manager');
        $first = $this->reader()->labelOptions($manager, $workspace, TaskMutationOrigin::Agent, $project->public_id);
        $second = $this->reader()->labelOptions($manager, $workspace, TaskMutationOrigin::Agent, $project->public_id, cursor: $first['meta']['next_cursor']);
        $this->assertSame(array_map(fn (ClientTaskLabel $label): string => $label->public_id, $labels), array_column([...$first['data'], ...$second['data']], 'id'));
        $this->assertCount(25, $first['data']);
        $this->assertCount(6, $second['data']);
        $this->assertNull($second['meta']['next_cursor']);
        $this->assertSame($archived->public_id, $this->reader()->labels($owner, $workspace, TaskMutationOrigin::Agent, 'archived')['data'][0]['id']);
        $this->expectReadStatus(404, fn () => $this->reader()->label($owner, $workspace, TaskMutationOrigin::Agent, $foreign->public_id));
        $this->expectReadStatus(404, fn () => $this->reader()->labelOptions($owner, $workspace, TaskMutationOrigin::Agent, $foreignProject->public_id));
        $this->assertSame($foreign->public_id, $this->reader()->label($foreignOwner, $foreignWorkspace, TaskMutationOrigin::Agent, $foreign->public_id)['data']['id']);
        foreach ([[$owner, TaskMutationOrigin::Agent, ''], [$manager, TaskMutationOrigin::Internal, ''], [$manager, TaskMutationOrigin::Agent, 'different']] as [$actor, $origin, $search]) {
            try {
                $this->reader()->labelOptions($actor, $workspace, $origin, $project->public_id, q: $search, cursor: $first['meta']['next_cursor']);
                $this->fail('A cursor escaped its actor/origin/query binding.');
            } catch (InvalidAgentApiCursor) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            $this->reader()->labelOptions($manager, $workspace, TaskMutationOrigin::Agent, $project->public_id, cursor: base64_encode('1'));
            $this->fail('Legacy numeric cursor was accepted.');
        } catch (InvalidAgentApiCursor) {
            $this->addToAssertionCount(1);
        }
        foreach ([['limit' => 0], ['limit' => 26], ['q' => str_repeat('a', 101)], ['cursor' => str_repeat('a', 2049)]] as $options) {
            $this->invalid(fn () => $this->reader()->labels($owner, $workspace, TaskMutationOrigin::Agent, ...$options));
        }
    }

    public function test_contacts_are_minimal_exact_company_active_options_with_retained_archived_details(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic other company', 'slug' => 'synthetic-other']);
        ClientContact::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $otherCompany->id, 'first_name' => 'Synthetic foreign company']);
        $contact = $this->contact($workspace, $project);
        $archived = $this->contact($workspace, $project, true);
        $unnamed = ClientContact::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id]);
        ClientTaskContactResponsibility::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id,
            'client_project_id' => $project->id, 'client_task_id' => $task->id, 'client_contact_id' => $archived->id]);
        $get = $this->reader()->contacts($owner, $workspace, TaskMutationOrigin::Agent, $task->public_id);
        $this->assertSame(['task_id' => $task->public_id, 'version' => AgentApiVersion::for($task), 'contacts' => [
            ['id' => $archived->public_id, 'display_name' => 'Synthetic contact', 'is_archived' => true, 'version' => AgentApiVersion::for($archived)],
        ]], $get['data']);
        $options = $this->reader()->contactOptions($owner, $workspace, TaskMutationOrigin::Agent, $task->public_id);
        $this->assertSame([$contact->public_id, $unnamed->public_id], array_column($options['data'], 'id'));
        $this->assertSame('Unnamed contact', $options['data'][1]['display_name']);
        $encoded = json_encode($get, JSON_THROW_ON_ERROR);
        foreach (['synthetic-private@example.test', 'Synthetic private notes', 'email', 'phone', 'notes', 'user_id', 'workspace_id', 'client_company_id'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        $summary = app(TaskPlanningDetailsPresenter::class)->response($this->reader()->get($owner, $workspace, TaskMutationOrigin::Agent, $task->public_id));
        $this->assertArrayNotHasKey('contacts', $summary['data']);
        $this->assertStringNotContainsString($archived->public_id, json_encode($summary, JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('client_task_graph_states', 0);
    }

    public function test_dependency_options_exclude_self_and_transitive_cycle_candidates_before_pagination(): void
    {
        [$workspace, $project, $owner, $source] = $this->fixture();
        $dependent = $this->task($workspace, $project, 'Synthetic direct dependent');
        $transitive = $this->task($workspace, $project, 'Synthetic transitive dependent');
        $eligible = $this->task($workspace, $project, 'Synthetic eligible prerequisite');
        $this->edge($workspace, $project, $dependent, $source);
        $this->edge($workspace, $project, $transitive, $dependent);
        $result = $this->reader()->dependencyOptions($owner, $workspace, TaskMutationOrigin::Agent, $source->public_id, limit: 1);
        $this->assertSame([$eligible->public_id], array_column($result['data'], 'id'));
        $this->assertNull($result['meta']['next_cursor']);
        $this->assertSame(route('tasks.show', [$workspace, $eligible]), $result['data'][0]['web_url']);
        $this->assertSame(['id', 'title', 'status', 'version', 'web_url'], array_keys($result['data'][0]));
        $this->assertDatabaseCount('client_task_graph_states', 0);
    }

    public function test_dependency_detail_repeats_complete_prerequisites_and_pages_every_incoming_reference(): void
    {
        [$workspace, $project, $owner, $source] = $this->fixture();
        $prerequisite = $this->task($workspace, $project, 'Synthetic prerequisite');
        $this->edge($workspace, $project, $source, $prerequisite);
        $expected = [];
        for ($index = 1; $index <= 31; $index++) {
            $task = $this->task($workspace, $project, str_repeat('🚀', 255));
            $this->edge($workspace, $project, $task, $source);
            $expected[] = $task->public_id;
        }
        $viewer = $this->member($workspace, $project, 'viewer');
        $first = $this->reader()->dependencies($viewer, $workspace, TaskMutationOrigin::Agent, $source->public_id);
        $second = $this->reader()->dependencies($viewer, $workspace, TaskMutationOrigin::Agent, $source->public_id, cursor: $first['meta']['next_cursor']);
        $this->assertSame($expected, array_column([...$first['data']['dependents'], ...$second['data']['dependents']], 'id'));
        $this->assertCount(25, $first['data']['dependents']);
        $this->assertCount(6, $second['data']['dependents']);
        $this->assertNull($second['meta']['next_cursor']);
        $this->assertSame([$prerequisite->public_id], array_column($first['data']['prerequisites'], 'id'));
        $this->assertSame($first['data']['prerequisites'], $second['data']['prerequisites']);
        $this->assertSame($first['data']['graph_version'], $second['data']['graph_version']);
        $this->assertSame(route('clients.tasks', [$workspace, $project->clientCompany, 'project' => $project->public_id]), $first['data']['dependents'][0]['web_url']);
        $this->assertLessThanOrEqual(AgentPageBudget::dataBytes(), strlen(json_encode($first['data'], JSON_THROW_ON_ERROR)));
        $result = new CallToolResult((new ToolResultFormatter)->format($first), structuredContent: $first);
        $this->assertLessThanOrEqual(config('agent_api.mcp_max_result_bytes'), strlen(json_encode($result, JSON_THROW_ON_ERROR)));
        $this->assertLessThanOrEqual(config('agent_api.mcp_max_response_body_bytes'), strlen(json_encode(new Response('synthetic-planning-page', $result), JSON_THROW_ON_ERROR)));
        $this->assertDatabaseCount('client_task_graph_states', 0);
    }

    public function test_labels_contacts_and_summaries_remain_available_above_dependency_project_size_cap(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        $rows = [];
        for ($index = 0; $index < 1000; $index++) {
            $rows[] = ['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'public_id' => (string) Str::uuid(), 'title' => 'Synthetic large legacy task'];
        }
        ClientTask::query()->insert($rows);
        $label = $this->label($workspace, 'Synthetic large-project label');
        $this->attach($workspace, $task, $label);
        $contact = $this->contact($workspace, $project);
        $this->assertSame($label->public_id, $this->reader()->get($owner, $workspace, TaskMutationOrigin::Agent, $task->public_id)->labels[0]['id']);
        $this->assertSame($label->public_id, $this->reader()->labelOptions($owner, $workspace, TaskMutationOrigin::Agent, $project->public_id)['data'][0]['id']);
        $this->assertSame($contact->public_id, $this->reader()->contactOptions($owner, $workspace, TaskMutationOrigin::Agent, $task->public_id)['data'][0]['id']);
        $this->assertSame([], $this->reader()->contacts($owner, $workspace, TaskMutationOrigin::Agent, $task->public_id)['data']['contacts']);
        $this->invalid(fn () => $this->reader()->dependencies($owner, $workspace, TaskMutationOrigin::Agent, $task->public_id), '1000 tasks');
        $this->invalid(fn () => $this->reader()->dependencyOptions($owner, $workspace, TaskMutationOrigin::Agent, $task->public_id), '1000 tasks');
        $this->assertDatabaseCount('client_task_graph_states', 0);
    }

    public function test_internal_retained_archived_lists_are_valid_but_cyclic_ancestry_is_generic(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        $list = ClientTaskList::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'name' => 'Synthetic archived list']);
        $task->forceFill(['client_task_list_id' => $list->id])->save();
        $list->delete();
        $this->assertSame($task->public_id, $this->reader()->get($owner, $workspace, TaskMutationOrigin::Internal, $task->public_id)->taskId);
        $child = $this->task($workspace, $project, 'Synthetic cyclic child');
        $task->forceFill(['client_task_list_id' => null, 'parent_task_id' => $child->id])->save();
        $child->forceFill(['parent_task_id' => $task->id])->save();
        $this->invalid(fn () => $this->reader()->get($owner, $workspace, TaskMutationOrigin::Internal, $task->public_id), 'The task organization is invalid.');
        $this->invalid(fn () => $this->reader()->contacts($owner, $workspace, TaskMutationOrigin::Internal, $task->public_id), 'The task organization is invalid.');
    }

    public function test_summary_queries_are_bounded_for_one_and_one_hundred_tasks_and_reject_larger_batches(): void
    {
        [$workspace, $project, $owner, $first] = $this->fixture();
        $label = $this->label($workspace, 'Synthetic batch label');
        $tasks = [$first];
        for ($index = 1; $index < 100; $index++) {
            $tasks[] = $this->task($workspace, $project, 'Synthetic batch task '.$index);
        }
        foreach ($tasks as $task) {
            $this->attach($workspace, $task, $label);
        }
        $one = $this->queryCount(fn () => $this->reader()->summariesForAuthorizedTasks($owner, $workspace, TaskMutationOrigin::Agent, [$first]));
        $many = $this->queryCount(function () use ($owner, $workspace, $tasks): void {
            $data = $this->reader()->summariesForAuthorizedTasks($owner, $workspace, TaskMutationOrigin::Agent, $tasks);
            $this->assertCount(100, $data);
            foreach ($tasks as $task) {
                $this->assertCount(1, $data[$task->id]->labels);
            }
        });
        $this->assertSame($one, $many);
        $this->assertLessThanOrEqual(12, $many);
        $this->expectException(\LogicException::class);
        $this->reader()->summariesForAuthorizedTasks($owner, $workspace, TaskMutationOrigin::Agent, [...$tasks, $first]);
    }

    public function test_unicode_catalog_and_contact_options_shorten_pages_to_the_actual_sdk_budget_without_losing_rows(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        config(['agent_api.mcp_max_result_bytes' => 60_000, 'agent_api.mcp_max_response_body_bytes' => 120_000]);
        $expectedLabels = [];
        $expectedContacts = [];
        for ($index = 1; $index <= 31; $index++) {
            $expectedLabels[] = $this->label($workspace, str_repeat('🚀', 77).sprintf('%03d', $index))->public_id;
            $expectedContacts[] = ClientContact::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id,
                'first_name' => str_repeat('🚀', 100), 'last_name' => str_repeat('🚀', 97).sprintf('%03d', $index)])->public_id;
        }
        foreach (['labels', 'contacts'] as $kind) {
            $actual = [];
            $cursor = null;
            $pageCount = 0;
            do {
                $page = $kind === 'labels'
                    ? $this->reader()->labels($owner, $workspace, TaskMutationOrigin::Agent, cursor: $cursor)
                    : $this->reader()->contactOptions($owner, $workspace, TaskMutationOrigin::Agent, $task->public_id, cursor: $cursor);
                $this->assertLessThan(25, count($page['data']));
                $this->assertLessThanOrEqual(AgentPageBudget::dataBytes(), strlen(json_encode($page['data'], JSON_THROW_ON_ERROR)));
                $result = new CallToolResult((new ToolResultFormatter)->format($page), structuredContent: $page);
                $this->assertLessThanOrEqual(60_000, strlen(json_encode($result, JSON_THROW_ON_ERROR)));
                $this->assertLessThanOrEqual(120_000, strlen(json_encode(new Response('synthetic-unicode-options', $result), JSON_THROW_ON_ERROR)));
                foreach ($page['data'] as $row) {
                    $this->assertSame($kind === 'labels' ? 80 : 201, mb_strlen($row[$kind === 'labels' ? 'name' : 'display_name'], 'UTF-8'));
                    $actual[] = $row['id'];
                }
                $cursor = $page['meta']['next_cursor'];
                $pageCount++;
                $this->assertLessThanOrEqual(31, $pageCount, 'A continuation failed to make progress.');
            } while ($cursor !== null);
            $this->assertSame($kind === 'labels' ? $expectedLabels : $expectedContacts, $actual);
            $this->assertGreaterThan(1, $pageCount);
        }
    }

    public function test_legacy_malformed_parent_label_contact_and_dependency_rows_are_generic_and_foreign_task_roots_are_hidden(): void
    {
        [$workspace, $project, $owner] = $this->fixture();
        [$foreignWorkspace, $foreignProject, , $foreignTask] = $this->fixture('foreign');
        $badParent = $this->task($workspace, $project, 'Synthetic broken parent');
        $badLabel = $this->task($workspace, $project, 'Synthetic broken label');
        $badContact = $this->task($workspace, $project, 'Synthetic broken contact');
        $badDependency = $this->task($workspace, $project, 'Synthetic broken dependency');
        $foreignRoot = $this->task($workspace, $project, 'Synthetic broken project');
        $foreignLabel = $this->label($foreignWorkspace, 'Synthetic foreign secret label');
        $foreignContact = $this->contact($foreignWorkspace, $foreignProject);
        $this->writingLegacyCrossTenantRows(function () use ($workspace, $project, $badParent, $badLabel, $badContact, $badDependency, $foreignRoot, $foreignTask, $foreignProject, $foreignLabel, $foreignContact): void {
            DB::table('client_tasks')->where('id', $badParent->id)->update(['parent_task_id' => $foreignTask->id]);
            DB::table('client_tasks')->where('id', $foreignRoot->id)->update(['client_project_id' => $foreignProject->id]);
            $this->attach($workspace, $badLabel, $foreignLabel);
            ClientTaskContactResponsibility::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id,
                'client_company_id' => $project->client_company_id, 'client_task_id' => $badContact->id, 'client_contact_id' => $foreignContact->id]);
            $this->edge($workspace, $project, $badDependency, $foreignTask);
        });
        foreach ([$badParent, $badLabel, $badDependency] as $task) {
            $this->invalid(fn () => $this->reader()->get($owner, $workspace, TaskMutationOrigin::Agent, $task->public_id), 'The task organization is invalid.');
        }
        $this->invalid(fn () => $this->reader()->contacts($owner, $workspace, TaskMutationOrigin::Agent, $badContact->public_id), 'The task organization is invalid.');
        $this->expectReadStatus(404, fn () => $this->reader()->get($owner, $workspace, TaskMutationOrigin::Agent, $foreignRoot->public_id));
        $this->assertSame([], $this->reader()->summariesForAuthorizedTasks($owner, $workspace, TaskMutationOrigin::Agent, [$foreignTask, $foreignRoot]));
    }

    public function test_malformed_retained_association_caps_fail_explicitly_instead_of_truncating_details(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        for ($index = 1; $index <= 21; $index++) {
            $this->attach($workspace, $task, $this->label($workspace, 'Synthetic retained '.$index));
        }
        $this->invalid(fn () => $this->reader()->get($owner, $workspace, TaskMutationOrigin::Agent, $task->public_id), 'The task organization is invalid.');
        for ($index = 1; $index <= 11; $index++) {
            $contact = $this->contact($workspace, $project);
            ClientTaskContactResponsibility::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id,
                'client_company_id' => $project->client_company_id, 'client_task_id' => $task->id, 'client_contact_id' => $contact->id]);
        }
        $this->invalid(fn () => $this->reader()->contacts($owner, $workspace, TaskMutationOrigin::Agent, $task->public_id), 'The task organization is invalid.');
        $this->assertDatabaseCount('client_task_label_assignments', 21);
        $this->assertDatabaseCount('client_task_contact_responsibilities', 11);
    }

    public function test_structural_reader_accepts_eight_parent_edges_and_refuses_a_ninth(): void
    {
        [$workspace, $project, $owner, $root] = $this->fixture();
        $current = $root;
        for ($depth = 1; $depth <= 8; $depth++) {
            $child = $this->task($workspace, $project, 'Synthetic depth '.$depth);
            $child->forceFill(['parent_task_id' => $current->id])->save();
            $current = $child;
        }
        $this->assertSame($current->public_id, $this->reader()->get($owner, $workspace, TaskMutationOrigin::Internal, $current->public_id)->taskId);
        $ninth = $this->task($workspace, $project, 'Synthetic depth nine');
        $ninth->forceFill(['parent_task_id' => $current->id])->save();
        $this->invalid(fn () => $this->reader()->get($owner, $workspace, TaskMutationOrigin::Internal, $ninth->public_id), 'The task organization is invalid.');
    }

    public function test_multi_project_internal_batches_never_treat_another_authorized_projects_parent_or_list_as_local(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id, 'name' => 'Synthetic other authorized project']);
        $otherTask = $this->task($workspace, $otherProject, 'Synthetic other authorized parent');
        $otherList = ClientTaskList::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $otherProject->id, 'name' => 'Synthetic other authorized list']);
        $listed = $this->task($workspace, $project, 'Synthetic malformed list');
        $this->writingLegacyCrossTenantRows(function () use ($task, $listed, $otherTask, $otherList): void {
            DB::table('client_tasks')->where('id', $task->id)->update(['parent_task_id' => $otherTask->id]);
            DB::table('client_tasks')->where('id', $listed->id)->update(['client_task_list_id' => $otherList->id]);
        });
        foreach ([$task, $listed] as $malformed) {
            $this->invalid(fn () => $this->reader()->summariesForAuthorizedTasks($owner, $workspace, TaskMutationOrigin::Internal, [$malformed, $otherTask]), 'The task organization is invalid.');
        }
    }

    public function test_dependency_pages_deduct_the_complete_outgoing_set_before_incoming_bytes_and_refuse_an_undersized_base(): void
    {
        [$workspace, $project, $owner, $source] = $this->fixture();
        config(['agent_api.mcp_max_result_bytes' => 300_000, 'agent_api.mcp_max_response_body_bytes' => 600_000]);
        $prerequisites = [];
        $incoming = [];
        for ($index = 1; $index <= 25; $index++) {
            $task = $this->task($workspace, $project, str_repeat('🚀', 255));
            $this->edge($workspace, $project, $source, $task);
            $prerequisites[] = $task->public_id;
        }
        for ($index = 1; $index <= 31; $index++) {
            $task = $this->task($workspace, $project, str_repeat('🚀', 255));
            $this->edge($workspace, $project, $task, $source);
            $incoming[] = $task->public_id;
        }
        $cursor = null;
        $actual = [];
        $pages = 0;
        do {
            $page = $this->reader()->dependencies($owner, $workspace, TaskMutationOrigin::Agent, $source->public_id, cursor: $cursor);
            $this->assertSame($prerequisites, array_column($page['data']['prerequisites'], 'id'));
            $this->assertNotEmpty($page['data']['dependents']);
            $this->assertLessThan(25, count($page['data']['dependents']));
            $this->assertLessThanOrEqual(AgentPageBudget::dataBytes(), strlen(json_encode($page['data'], JSON_THROW_ON_ERROR)));
            $result = new CallToolResult((new ToolResultFormatter)->format($page), structuredContent: $page);
            $this->assertLessThanOrEqual(300_000, strlen(json_encode($result, JSON_THROW_ON_ERROR)));
            $this->assertLessThanOrEqual(600_000, strlen(json_encode(new Response('synthetic-dependency-budget', $result), JSON_THROW_ON_ERROR)));
            foreach ($page['data']['dependents'] as $row) {
                $this->assertSame(str_repeat('🚀', 255), $row['title']);
                $actual[] = $row['id'];
            }
            $cursor = $page['meta']['next_cursor'];
            $pages++;
            $this->assertLessThanOrEqual(31, $pages);
        } while ($cursor !== null);
        $this->assertSame($incoming, $actual);
        config(['agent_api.mcp_max_result_bytes' => 20_000]);
        $this->expectReadStatus(422, fn () => $this->reader()->dependencies($owner, $workspace, TaskMutationOrigin::Agent, $source->public_id));
    }

    public function test_ordinary_task_get_list_and_project_embed_recompute_enrichment_but_never_disclose_contacts_or_portal_facts(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        $project->update(['is_visible_to_client' => true]);
        $task->update(['is_visible_to_client' => true]);
        $label = $this->label($workspace, 'Synthetic original label');
        $this->attach($workspace, $task, $label);
        $prerequisite = $this->task($workspace, $project, 'Synthetic prerequisite');
        $this->edge($workspace, $project, $task, $prerequisite);
        $token = AgentApiVersion::for($task);
        $reads = app(AgentReadService::class);
        $row = $reads->task($owner, $workspace, $task->public_id);
        $this->assertSame('Synthetic original label', $row['planning_details']['labels'][0]['name']);
        $this->assertTrue($row['planning_details']['dependency_summary']['blocked']);
        $this->assertArrayNotHasKey('contacts', $row['planning_details']);
        $this->assertArrayNotHasKey('planningDetails', $task->fresh()->toArray());
        $prerequisite->update(['status' => 'completed']);
        $label->update(['name' => 'Synthetic current label']);
        $row = $reads->task($owner, $workspace, $task->public_id);
        $this->assertSame($token, $row['version']);
        $this->assertFalse($row['planning_details']['dependency_summary']['blocked']);
        $this->assertSame('Synthetic current label', $row['planning_details']['labels'][0]['name']);
        $list = $reads->tasks($owner, $workspace, $project->public_id, 25, null);
        $this->assertSame($row['planning_details'], $list['data'][0]['planning_details']);
        $this->actingAsMcp($owner, ['projects:read', 'tasks:read']);
        $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/projects/'.$project->public_id)->assertOk()
            ->assertJsonPath('data.tasks.0.planning_details.labels.0.name', 'Synthetic current label');
        config(['task_planning_details.enabled' => false]);
        $this->assertArrayNotHasKey('planning_details', $reads->task($owner, $workspace, $task->public_id));
        config(['task_planning_details.enabled' => true]);
        $project->clientCompany->portalUsers()->attach($owner->id, ['role' => 'client', 'access_scope' => 'company']);
        $this->assertArrayNotHasKey('planning_details', $reads->task($owner, $workspace, $task->public_id));
        $this->assertArrayNotHasKey('planning_details', $reads->tasks($owner, $workspace, $project->public_id, 25, null)['data'][0]);
        $this->assertDatabaseCount('client_task_graph_states', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_task_page_sentinel_is_loaded_in_bounded_batches_and_every_enriched_id_survives_continuation(): void
    {
        [$workspace, $project, $owner, $source] = $this->fixture();
        for ($index = 1; $index <= 100; $index++) {
            $this->task($workspace, $project, 'Synthetic ordinary page '.$index);
        }
        $reads = app(AgentReadService::class);
        $first = $reads->tasks($owner, $workspace, $project->public_id, 100, null);
        $this->assertCount(100, $first['data']);
        $this->assertNotNull($first['meta']['next_cursor']);
        $next = $reads->tasks($owner, $workspace, $project->public_id, 100, $first['meta']['next_cursor']);
        $this->assertCount(1, $next['data']);
        $this->assertNull($next['meta']['next_cursor']);
        $ids = [];
        foreach ([...$first['data'], ...$next['data']] as $row) {
            $this->assertSame([], $row['planning_details']['labels']);
            $ids[] = $row['id'];
        }
        $this->assertCount(101, array_unique($ids));
        $this->assertDatabaseCount('client_task_graph_states', 0);
    }

    /** @return array{Workspace,ClientProject,User,ClientTask} */
    private function fixture(string $suffix = 'local'): array
    {
        config(['task_planning_details.enabled' => true]);
        $workspace = Workspace::query()->create(['name' => 'Synthetic read '.$suffix, 'slug' => 'synthetic-read-'.$suffix]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic company '.$suffix, 'slug' => 'synthetic-read-company-'.$suffix]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project '.$suffix]);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$workspace, $project, $owner, $this->task($workspace, $project, 'Synthetic source task')];
    }

    private function task(Workspace $workspace, ClientProject $project, string $title): ClientTask
    {
        return ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => $title]);
    }

    private function member(Workspace $workspace, ClientProject $project, string $role): User
    {
        $user = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function label(Workspace $workspace, string $name): ClientTaskLabel
    {
        return ClientTaskLabel::query()->create(['workspace_id' => $workspace->id, 'name' => $name, 'name_key' => hash('sha256', $name)]);
    }

    private function attach(Workspace $workspace, ClientTask $task, ClientTaskLabel $label): void
    {
        ClientTaskLabelAssignment::query()->create(['workspace_id' => $workspace->id, 'client_task_id' => $task->id, 'client_task_label_id' => $label->id]);
    }

    private function edge(Workspace $workspace, ClientProject $project, ClientTask $task, ClientTask $prerequisite): void
    {
        ClientTaskDependency::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'client_task_id' => $task->id, 'prerequisite_task_id' => $prerequisite->id]);
    }

    private function contact(Workspace $workspace, ClientProject $project, bool $archived = false): ClientContact
    {
        return ClientContact::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id,
            'first_name' => 'Synthetic', 'last_name' => 'contact', 'email' => 'synthetic-private@example.test', 'notes' => 'Synthetic private notes', 'is_archived' => $archived]);
    }

    private function reader(): TaskPlanningDetailsReadService
    {
        return app(TaskPlanningDetailsReadService::class);
    }

    private function expectReadStatus(int $status, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a refused read.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        } catch (ModelNotFoundException) {
            $this->assertSame(404, $status);
        }
    }

    private function invalid(callable $callback, ?string $message = null): void
    {
        try {
            $callback();
            $this->fail('Invalid planning read was accepted.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
            if ($message !== null) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    private function queryCount(callable $callback): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
