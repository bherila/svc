<?php

namespace Tests\Feature\Tasks\Organization;

use App\Http\Controllers\ClientTaskOrganizationController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveWorkspaceNavigation;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTaskList;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\Organization\TaskContainer;
use App\Services\Tasks\Organization\TaskOrganizationAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class TaskOrganizationNativeViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'auth:web', ResolveWorkspaceNavigation::class])->group(function (): void {
            Route::get('/synthetic-native/{workspace}/clients/{clientCompany}/projects/{project}/organization', [ClientTaskOrganizationController::class, 'page'])->name('tasks.organization.show');
            Route::get('/synthetic-native/{workspace}/projects/{project}/organization/snapshot', [ClientTaskOrganizationController::class, 'show'])->name('tasks.organization.snapshot');
            foreach ([['post', '/lists', 'lists.store', 'lists.create'], ['patch', '/lists/{list}', 'lists.update', 'lists.update'],
                ['delete', '/lists/{list}', 'lists.archive', 'lists.archive'], ['post', '/lists/{list}/restore', 'lists.restore', 'lists.restore'],
                ['put', '/lists/order', 'lists.reorder', 'lists.reorder'], ['post', '/tasks', 'tasks.store', 'tasks.create'],
                ['patch', '/tasks/{task}/container', 'tasks.move', 'tasks.move'], ['put', '/tasks/order', 'tasks.reorder', 'tasks.reorder']] as [$verb, $suffix, $name, $operation]) {
                Route::$verb('/synthetic-native/{workspace}/projects/{project}/organization'.$suffix, [ClientTaskOrganizationController::class, 'mutate'])
                    ->defaults('task_organization_operation', $operation)->name('tasks.organization.'.$name);
            }
        });
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_native_page_supplies_current_company_chrome_complete_urls_and_filtered_move_targets(): void
    {
        [$workspace, $company, $project, $owner, $list, $root, $child] = $this->fixture();
        $action = app(TaskOrganizationAction::class);
        $view = $action->nativeView($owner, $workspace, $company, $project->public_id, new TaskContainer(null, $list->public_id), $root->public_id)->data;
        $row = $view['organization']['container']['tasks'][0];
        $this->assertSame('In progress', $row['status_label']);
        $this->assertNull($row['move_target'], 'A root cannot move below itself.');
        $this->assertSame(['parent_id' => null, 'list_id' => null], $view['organization']['unlisted']['move_target']);
        $this->assertStringContainsString('moving_task_id='.$root->public_id, $row['browse_href']);
        $this->assertStringContainsString('/tasks/'.$root->public_id, $row['task_href']);
        $this->assertStringNotContainsString('Synthetic private full description', json_encode($view, JSON_THROW_ON_ERROR));
        $descendants = $action->nativeView($owner, $workspace, $company, $project->public_id, new TaskContainer($root->public_id, null), $root->public_id)->data['organization'];
        $this->assertNull($descendants['container']['move_target']);
        $this->assertNull($descendants['container']['tasks'][0]['move_target']);
        $this->assertSame(['Synthetic private list', 'Synthetic root'], array_column($descendants['container']['breadcrumbs'], 'label'));
        $this->assertSame(['parent_id' => $child->public_id, 'list_id' => null], $descendants['container']['tasks'][0]['child_target']);
        $this->assertDatabaseCount('client_task_graph_states', 0);
        $this->actingAs($owner, 'web')->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (app(HandleInertiaRequests::class)->version(Request::create('/')) ?? '')])->get(route('tasks.organization.show', [$workspace, $company, $project->public_id]))
            ->assertOk()->assertJsonPath('component', 'clients/task-organization')->assertJsonPath('props.workspaceNavigation.current_client_id', $company->public_id)
            ->assertJsonPath('props.organization.container.title', 'Unlisted tasks');
        $this->withoutHeader('X-Inertia')->withoutHeader('X-Inertia-Version');
        $this->assertDatabaseCount('client_task_graph_states', 0);
    }

    public function test_checked_children_report_effective_visibility_and_the_blocking_project_list_or_parent(): void
    {
        [$workspace, $company, $project, $owner, $list, $root, $child] = $this->fixture();
        $action = app(TaskOrganizationAction::class);
        $read = fn (): array => $action->nativeView($owner, $workspace, $company, $project->public_id, new TaskContainer($root->public_id, null), null)->data['organization']['container'];
        $container = $read();
        $this->assertTrue($container['tasks'][0]['is_visible_to_client']);
        $this->assertFalse($container['tasks'][0]['effective_visible_to_client']);
        $this->assertStringContainsString('list is hidden', $container['sharing_note']);
        $list->forceFill(['is_visible_to_client' => true])->save();
        $container = $read();
        $this->assertTrue($container['tasks'][0]['effective_visible_to_client']);
        $this->assertNull($container['sharing_note']);
        $root->forceFill(['is_visible_to_client' => false])->save();
        $container = $read();
        $this->assertFalse($container['tasks'][0]['effective_visible_to_client']);
        $this->assertStringContainsString('containing task is hidden', $container['sharing_note']);
        $root->forceFill(['is_visible_to_client' => true])->save();
        $project->forceFill(['is_visible_to_client' => false])->save();
        $container = $read();
        $this->assertFalse($container['tasks'][0]['effective_visible_to_client']);
        $this->assertStringContainsString('project is hidden', $container['sharing_note']);
        $project->forceFill(['is_visible_to_client' => true])->save();
        $list->delete();
        $container = $read();
        $this->assertFalse($container['tasks'][0]['effective_visible_to_client']);
        $this->assertStringContainsString('list is archived', $container['sharing_note']);
        $this->assertNull($container['create_task_href']);
        $this->assertNull($container['tasks'][0]['create_child_href']);
    }

    /** @return array{Workspace,ClientCompany,ClientProject,User,ClientTaskList,ClientTask,ClientTask} */
    private function fixture(): array
    {
        config(['task_organization.enabled' => true]);
        $workspace = Workspace::query()->create(['name' => 'Synthetic native organization', 'slug' => 'synthetic-native-organization']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic native company', 'slug' => 'synthetic-native-company']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic native project', 'is_visible_to_client' => true]);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $list = ClientTaskList::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'name' => 'Synthetic private list']);
        $root = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Synthetic root', 'status' => 'in_progress', 'is_visible_to_client' => true]);
        $root->forceFill(['client_task_list_id' => $list->id])->save();
        $child = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Synthetic child', 'description' => 'Synthetic private full description', 'is_visible_to_client' => true]);
        $child->forceFill(['parent_task_id' => $root->id])->save();

        return [$workspace, $company, $project, $owner, $list, $root, $child];
    }
}
