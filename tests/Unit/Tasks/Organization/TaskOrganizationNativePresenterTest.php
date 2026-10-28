<?php

namespace Tests\Unit\Tasks\Organization;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTaskGraphState;
use App\Models\ClientTaskList;
use App\Models\Workspace;
use App\Services\Tasks\Organization\TaskContainer;
use App\Services\Tasks\Organization\TaskGraph;
use App\Services\Tasks\Organization\TaskOrganizationNativePresenter;
use App\Services\Tasks\Organization\TaskOrganizationRecords;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(TaskOrganizationNativePresenter::class)]
final class TaskOrganizationNativePresenterTest extends TestCase
{
    public function test_native_container_keeps_snapshot_versions_safe_identities_and_ui_only_labels(): void
    {
        $workspace = new Workspace;
        $workspace->setRawAttributes(['id' => 1, 'public_id' => 'synthetic-workspace']);
        $company = new ClientCompany;
        $company->setRawAttributes(['public_id' => 'synthetic-company', 'name' => 'Synthetic client']);
        $project = new ClientProject;
        $project->setRawAttributes(['public_id' => 'synthetic-project', 'name' => 'Synthetic project',
            'is_visible_to_client' => true, 'repository' => 'private.example.test/hidden/repository']);
        $models = new Collection;
        $facts = [];
        $statuses = ['open' => 'Open', 'in_progress' => 'In progress', 'completed' => 'Completed', 'unclassified' => 'Unknown'];
        foreach (array_keys($statuses) as $index => $status) {
            $task = new ClientTask;
            $task->setRawAttributes(['id' => $index + 10, 'public_id' => 'synthetic-task-'.$index,
                'title' => 'Synthetic title '.$index, 'description' => 'Synthetic private full description',
                'status' => $status, 'is_visible_to_client' => $index !== 0]);
            $models->push($task);
            $facts[$task->public_id] = ['id' => $index + 10, 'parent' => null, 'list' => null,
                'position' => $index, 'version' => 'synthetic-snapshot-version-'.$index];
        }
        $records = new TaskOrganizationRecords($project, new ClientTaskGraphState, $models, new Collection);
        $graph = new TaskGraph($facts, [], 3);
        $view = (new TaskOrganizationNativePresenter)->present($workspace, $company, $records, $graph,
            new TaskContainer(null, null), 'synthetic-task-0')->data;
        $this->assertSame(['id' => 'synthetic-project', 'name' => 'Synthetic project',
            'href' => route('clients.project', [$workspace, $company, $project])], $view['project']);
        $this->assertSame(['name' => 'Synthetic client', 'href' => route('clients.show', [$workspace, $company])], $view['company']);
        $rows = $view['organization']['container']['tasks'];
        $this->assertCount(4, $rows);
        foreach (array_values($statuses) as $index => $label) {
            $this->assertSame('synthetic-task-'.$index, $rows[$index]['id']);
            $this->assertSame('Synthetic title '.$index, $rows[$index]['title']);
            $this->assertSame('synthetic-snapshot-version-'.$index, $rows[$index]['version']);
            $this->assertSame($index !== 0, $rows[$index]['is_visible_to_client']);
            $this->assertSame($label, $rows[$index]['status_label']);
            $this->assertArrayNotHasKey('description', $rows[$index]);
        }
        $this->assertSame('synthetic-task-0', $view['organization']['moving']['id']);
        $this->assertSame('Synthetic title 0', $view['organization']['moving']['title']);
        $this->assertSame('synthetic-snapshot-version-0', $view['organization']['moving']['version']);
        $this->assertSame([], $project->getRelations());
        foreach ($models as $task) {
            $this->assertSame([], $task->getRelations());
        }
        $this->assertStringNotContainsString('Synthetic private full description', json_encode($view, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('private.example.test', json_encode($view, JSON_THROW_ON_ERROR));
    }

    public function test_list_rows_graph_revision_and_create_capability_remain_complete_at_the_list_limit(): void
    {
        $workspace = new Workspace;
        $workspace->setRawAttributes(['id' => 1, 'public_id' => 'synthetic-workspace']);
        $company = new ClientCompany;
        $company->setRawAttributes(['public_id' => 'synthetic-company', 'name' => 'Synthetic client']);
        $project = new ClientProject;
        $project->setRawAttributes(['public_id' => 'synthetic-project', 'name' => 'Synthetic project', 'is_visible_to_client' => true]);
        foreach ([0, 1, TaskGraph::MAX_LISTS - 1, TaskGraph::MAX_LISTS] as $count) {
            $models = new Collection;
            $facts = [];
            for ($index = 0; $index < $count; $index++) {
                $list = new ClientTaskList;
                $list->setRawAttributes(['id' => $index + 1, 'public_id' => 'synthetic-list-'.$index, 'name' => 'Synthetic list '.$index, 'is_visible_to_client' => true, 'deleted_at' => null]);
                $models->push($list);
                $facts[$list->public_id] = ['id' => $index + 1, 'deleted' => false, 'position' => $index, 'version' => 'synthetic-list-version-'.$index];
            }
            $graph = new TaskGraph([], $facts, 7);
            $records = new TaskOrganizationRecords($project, new ClientTaskGraphState, new Collection, $models);
            $container = new TaskContainer(null, $count === 0 ? null : 'synthetic-list-0');
            $view = (new TaskOrganizationNativePresenter)->present($workspace, $company, $records, $graph, $container, null)->data['organization'];
            $this->assertSame($graph->graphVersion(), $view['graph_version']);
            $this->assertSame($graph->graphVersion(), $view['lists_version']);
            $this->assertSame($count === 0 ? 'Unlisted tasks' : 'Synthetic list 0', $view['container']['title']);
            $this->assertCount($count, $view['lists']);
            $this->assertSame(array_keys($facts), $view['ordered_list_ids']);
            $this->assertSame($count === TaskGraph::MAX_LISTS ? null : route('agent-api.v1.task-organization.lists.store', [$workspace, $project->public_id]), $view['create_list_href']);
            foreach ($view['lists'] as $index => $row) {
                $this->assertSame('Synthetic list '.$index, $row['name']);
                $this->assertSame('synthetic-list-version-'.$index, $row['version']);
                $this->assertFalse($row['archived']);
                $this->assertSame(route('agent-api.v1.task-organization.lists.update', [$workspace, $project->public_id, $row['id']]), $row['update_href']);
            }
            $this->assertSame([], $project->getRelations());
        }
    }
}
