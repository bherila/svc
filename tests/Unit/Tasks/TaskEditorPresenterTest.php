<?php

namespace Tests\Unit\Tasks;

use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTaskAssignment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\TaskEditorPresenter;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(TaskEditorPresenter::class)]
final class TaskEditorPresenterTest extends TestCase
{
    /** @return iterable<string, array{?array{data:list<array{id:string,name:string}>,has_more:bool}}> */
    public static function initialPages(): iterable
    {
        yield 'unseeded scope' => [null];
        yield 'empty seed is authoritative' => [['data' => [], 'has_more' => false]];
        yield 'bounded seed has more' => [['data' => [['id' => 'synthetic-option', 'name' => 'Synthetic 選択肢 </script>']], 'has_more' => true]];
    }

    /** @param ?array{data:list<array{id:string,name:string}>,has_more:bool} $seed */
    #[DataProvider('initialPages')]
    public function test_task_and_project_keep_initial_page_presence_and_selected_assignees_independent(?array $seed): void
    {
        config(['task_organization.enabled' => false]);
        $workspace = new Workspace;
        $workspace->setRawAttributes(['id' => 3, 'public_id' => 'synthetic-workspace']);
        $project = new ClientProject;
        $project->setRawAttributes(['id' => 5, 'workspace_id' => 3, 'public_id' => 'synthetic-project', 'name' => 'Synthetic project']);
        $user = new User;
        $user->setRawAttributes(['id' => 7, 'public_id' => 'synthetic-selected-outside-page', 'name' => 'Synthetic already assigned']);
        $assignment = new ClientTaskAssignment;
        $assignment->setRelation('user', $user);
        $task = new ClientTask;
        $task->setRawAttributes(['id' => 9, 'workspace_id' => 3, 'client_project_id' => 5, 'public_id' => 'synthetic-task', 'title' => 'Synthetic title',
            'description' => 'Synthetic description', 'status' => 'in_progress', 'is_visible_to_client' => false,
            'starts_on' => '2026-10-20', 'due_on' => null, 'completed_at' => null, 'lock_version' => 2]);
        $task->setRelation('project', $project)->setRelation('assignments', new Collection([$assignment]));
        $presenter = app(TaskEditorPresenter::class);
        $projectView = $presenter->project($workspace, $project, $seed);
        $taskView = $presenter->task($workspace, $task, $seed);
        $this->assertSame($project->public_id, $projectView['id']);
        $this->assertSame($task->public_id, $taskView['id']);
        // The canonical API and pre-cutover web route share this resource
        // suffix. A presentation refactor must retain the finished write URL.
        $this->assertArrayHasKey('create_action', $projectView);
        $this->assertStringEndsWith('/workspaces/'.$workspace->public_id.'/projects/'.$project->public_id.'/tasks', $projectView['create_action']);
        $this->assertArrayHasKey('update_action', $taskView);
        $this->assertStringEndsWith('/workspaces/'.$workspace->public_id.'/tasks/'.$task->public_id, $taskView['update_action']);
        foreach ([$projectView, $taskView] as $view) {
            if ($seed === null) {
                $this->assertArrayNotHasKey('assignee_options', $view);
            } else {
                $this->assertSame($seed, $view['assignee_options']);
            }
            $this->assertSame(route('agent-api.v1.tasks.assignees.index', [$workspace, $project]), $view['assignees_action']);
        }
        $this->assertSame([['id' => $user->public_id, 'name' => $user->name]], $taskView['assignees']);
        $this->assertSame('2026-10-20', $taskView['starts_on']);
        $this->assertNull($taskView['due_on']);
        $this->assertSame('in_progress', $taskView['status']);
        $this->assertFalse($taskView['is_visible_to_client']);
    }
}
