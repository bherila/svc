<?php

namespace Tests\Unit\Tasks\Organization;

use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTaskGraphState;
use App\Models\ClientTaskList;
use App\Services\Tasks\Organization\TaskContainer;
use App\Services\Tasks\Organization\TaskGraph;
use App\Services\Tasks\Organization\TaskOrganizationRecords;
use App\Services\Tasks\Organization\TaskOrganizationSnapshot;
use Illuminate\Database\Eloquent\Collection;
use Mcp\Capability\Formatter\ToolResultFormatter;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(ClientProject::class)]
#[CoversClass(ClientTask::class)]
#[CoversClass(ClientTaskGraphState::class)]
#[CoversClass(ClientTaskList::class)]
#[CoversClass(TaskOrganizationRecords::class)]
#[CoversClass(TaskOrganizationSnapshot::class)]
#[CoversClass(TaskGraph::class)]
#[CoversClass(TaskContainer::class)]
final class TaskOrganizationSnapshotTest extends TestCase
{
    public function test_database_revision_strings_are_exposed_as_integer_versions(): void
    {
        $state = new ClientTaskGraphState;
        $state->setRawAttributes(['id' => 9, 'workspace_id' => 5, 'client_project_id' => 7, 'lock_version' => '12']);
        $this->assertSame(12, $state->lock_version);
        $this->assertSame(['lock_version' => 12], $state->toArray());
    }

    public function test_snapshot_preserves_the_public_topology_and_editor_contract_with_stable_list_ties(): void
    {
        $project = new ClientProject;
        $project->setRawAttributes(['id' => 99, 'workspace_id' => 5, 'public_id' => 'synthetic-project']);
        $lists = new Collection;
        $listFacts = [];
        // Input order differs from position order, and equal positions require the internal ID tie breaker.
        foreach ([[1, 'later', 7, true, null], [3, 'second', 2, false, '2026-10-22 00:00:00'], [2, 'first', 2, true, null]] as [$id, $public, $position, $visible, $deleted]) {
            $model = new ClientTaskList;
            $model->setRawAttributes(['id' => $id, 'public_id' => $public, 'name' => 'Synthetic '.$public,
                'organization_position' => $position, 'is_visible_to_client' => $visible, 'deleted_at' => $deleted]);
            $lists->push($model);
            $listFacts[$public] = ['id' => $id, 'deleted' => $deleted !== null, 'position' => $position, 'version' => 'list-'.$public];
        }
        $tasks = new Collection;
        $taskFacts = [];
        foreach ([[3, 'root', null, 'first', 4, 'open', true], [2, 'child-b', 'root', null, 1, 'completed', false], [1, 'child-a', 'root', null, 0, 'in_progress', true]] as [$id, $public, $parent, $list, $position, $status, $visible]) {
            $model = new ClientTask;
            $model->setRawAttributes(['id' => $id, 'public_id' => $public, 'title' => 'Synthetic '.$public,
                'description' => 'Private description excluded from organization snapshots', 'status' => $status, 'is_visible_to_client' => $visible]);
            $tasks->push($model);
            $taskFacts[$public] = ['id' => $id, 'parent' => $parent, 'list' => $list, 'position' => $position, 'version' => 'task-'.$public];
        }
        $graph = new TaskGraph($taskFacts, $listFacts, 3);
        $records = new TaskOrganizationRecords($project, new ClientTaskGraphState, $tasks, $lists);
        $container = new TaskContainer('root', null);
        $expected = [
            'project_id' => 'synthetic-project', 'graph_version' => $graph->graphVersion(), 'lists_version' => $graph->graphVersion(),
            'lists' => [
                ['id' => 'first', 'name' => 'Synthetic first', 'is_visible_to_client' => true, 'archived' => false, 'version' => 'list-first'],
                ['id' => 'second', 'name' => 'Synthetic second', 'is_visible_to_client' => false, 'archived' => true, 'version' => 'list-second'],
                ['id' => 'later', 'name' => 'Synthetic later', 'is_visible_to_client' => true, 'archived' => false, 'version' => 'list-later'],
            ],
            'tree' => [
                ['id' => 'root', 'parent_id' => null, 'list_id' => 'first', 'position' => 4, 'version' => 'task-root'],
                ['id' => 'child-b', 'parent_id' => 'root', 'list_id' => null, 'position' => 1, 'version' => 'task-child-b'],
                ['id' => 'child-a', 'parent_id' => 'root', 'list_id' => null, 'position' => 0, 'version' => 'task-child-a'],
            ],
            'container' => ['parent_id' => 'root', 'list_id' => null, 'version' => $graph->containerVersion($container), 'tasks' => [
                ['id' => 'child-a', 'title' => 'Synthetic child-a', 'status' => 'in_progress', 'is_visible_to_client' => true, 'version' => 'task-child-a'],
                ['id' => 'child-b', 'title' => 'Synthetic child-b', 'status' => 'completed', 'is_visible_to_client' => false, 'version' => 'task-child-b'],
            ]],
        ];
        $this->assertSame($expected, TaskOrganizationSnapshot::capture($records, $graph, $container)->data);
        $expected['container'] = null;
        $this->assertSame($expected, TaskOrganizationSnapshot::capture($records, $graph)->data);
        $root = new TaskContainer(null, 'first');
        $expected['container'] = ['parent_id' => null, 'list_id' => 'first', 'version' => $graph->containerVersion($root), 'tasks' => [
            ['id' => 'root', 'title' => 'Synthetic root', 'status' => 'open', 'is_visible_to_client' => true, 'version' => 'task-root'],
        ]];
        $this->assertSame($expected, TaskOrganizationSnapshot::capture($records, $graph, $root)->data);
    }

    public function test_maximum_unicode_names_and_bounded_sibling_titles_fit_actual_default_sdk_envelopes(): void
    {
        $tasks = new Collection;
        $lists = new Collection;
        $taskFacts = [];
        $listFacts = [];
        for ($index = 1; $index <= 100; $index++) {
            $id = sprintf('10000000-0000-4000-8000-%012d', $index);
            $list = new ClientTaskList;
            $list->setRawAttributes(['id' => $index, 'workspace_id' => 1, 'client_project_id' => 1, 'public_id' => $id,
                'name' => str_repeat('🚀', 255), 'is_visible_to_client' => false, 'organization_position' => $index]);
            $lists->push($list);
            $listFacts[$id] = ['id' => $index, 'deleted' => false, 'position' => $index, 'version' => str_repeat('a', 64)];
        }
        for ($index = 1; $index <= 1000; $index++) {
            $id = sprintf('00000000-0000-4000-8000-%012d', $index);
            $list = sprintf('10000000-0000-4000-8000-%012d', (int) ceil($index / 100));
            $task = new ClientTask;
            $task->setRawAttributes(['id' => $index, 'workspace_id' => 1, 'client_project_id' => 1, 'public_id' => $id,
                'title' => str_repeat('🚀', 255), 'description' => 'Synthetic private long description', 'status' => 'open', 'is_visible_to_client' => false]);
            $tasks->push($task);
            $taskFacts[$id] = ['id' => $index, 'parent' => null, 'list' => $list, 'position' => $index, 'version' => str_repeat('b', 64)];
        }
        $project = new ClientProject;
        $project->setRawAttributes(['id' => 1, 'workspace_id' => 1, 'public_id' => '20000000-0000-4000-8000-000000000001']);
        $state = new ClientTaskGraphState;
        $records = new TaskOrganizationRecords($project, $state, $tasks, $lists);
        $view = TaskOrganizationSnapshot::capture($records, new TaskGraph($taskFacts, $listFacts, 1), new TaskContainer(null, array_key_first($listFacts)));
        $payload = ['data' => $view->data];
        $result = new CallToolResult((new ToolResultFormatter)->format($payload), structuredContent: $payload);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertLessThanOrEqual(2_097_152, strlen($encoded));
        $this->assertLessThanOrEqual(4_194_304, strlen(json_encode(new Response('synthetic-organization-budget', $result), JSON_THROW_ON_ERROR)));
        $this->assertCount(1000, $view->data['tree']);
        $this->assertSame(1, $view->data['tree'][0]['position']);
        $this->assertSame(1000, $view->data['tree'][999]['position']);
        $this->assertCount(100, $view->data['lists']);
        $this->assertCount(100, $view->data['container']['tasks']);
        $this->assertStringNotContainsString('Synthetic private long description', $encoded);
        $this->assertStringNotContainsString('workspace_id', $encoded);
        $this->assertStringNotContainsString('client_project_id', $encoded);
        $this->assertSame('00000000-0000-4000-8000-000000000001', $view->data['container']['tasks'][0]['id']);
        $this->assertSame('00000000-0000-4000-8000-000000000100', $view->data['container']['tasks'][99]['id']);
    }
}
