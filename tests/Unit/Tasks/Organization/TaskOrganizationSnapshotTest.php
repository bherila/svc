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

#[CoversClass(TaskOrganizationSnapshot::class)]
#[CoversClass(TaskGraph::class)]
#[CoversClass(TaskContainer::class)]
final class TaskOrganizationSnapshotTest extends TestCase
{
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
        $this->assertCount(100, $view->data['lists']);
        $this->assertCount(100, $view->data['container']['tasks']);
        $this->assertStringNotContainsString('Synthetic private long description', $encoded);
        $this->assertStringNotContainsString('workspace_id', $encoded);
        $this->assertStringNotContainsString('client_project_id', $encoded);
        $this->assertSame('00000000-0000-4000-8000-000000000001', $view->data['container']['tasks'][0]['id']);
        $this->assertSame('00000000-0000-4000-8000-000000000100', $view->data['container']['tasks'][99]['id']);
    }
}
