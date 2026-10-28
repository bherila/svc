<?php

use App\Http\Controllers\ClientTaskController;
use App\Http\Controllers\ClientTaskPlanningController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ClientCompany;
use App\Models\ClientContact;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Navigation\WorkspaceNavigationFactory;
use App\Services\Tasks\Planning\TaskPlanningCommand;
use App\Services\Tasks\Planning\TaskPlanningMutationAction;
use App\Services\Tasks\TaskMutationOrigin;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

// Only called with a newly created isolated SQLite database by the focused
// planning browser harness; no application env, accounts or records are reused.
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Synthetic planning props require the CLI.');
}
$app = require __DIR__.'/bootstrap.php';
$runtime = getenv('SVC_LAYOUT_RUNTIME');
if (filesize($runtime.'/database.sqlite') !== 0) {
    throw new RuntimeException('Refusing to seed an existing database.');
}
Artisan::call('migrate', ['--force' => true]);
config(['task_planning_details.enabled' => true]);
$owner = User::factory()->create(['name' => 'Synthetic Planning Owner', 'email' => 'planning-owner@example.test']);
$workspace = Workspace::query()->create(['name' => 'Synthetic Planning Workspace', 'slug' => 'synthetic-planning']);
$workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
$company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => str_repeat('SyntheticCompany', 12), 'slug' => 'synthetic-planning-company']);
$project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => str_repeat('SyntheticProject', 12)]);
$task = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => str_repeat('SyntheticTask', 16), 'description' => str_repeat('Synthetic task description. ', 40)]);
$contact = ClientContact::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'first_name' => 'Synthetic business contact']);
$actions = app(TaskPlanningMutationAction::class);
$label = $actions->perform($owner, $workspace, null, TaskPlanningCommand::fromInput('labels.create', ['name' => 'Synthetic controller label']), TaskMutationOrigin::Internal);
$actions->perform($owner, $workspace, $task->public_id, TaskPlanningCommand::fromInput('labels.replace', ['expected_version' => AgentApiVersion::for($task->fresh()), 'label_ids' => [$label]]), TaskMutationOrigin::Internal);
$actions->perform($owner, $workspace, $task->public_id, TaskPlanningCommand::fromInput('contacts.replace', ['expected_version' => AgentApiVersion::for($task->fresh()), 'contact_ids' => [$contact->public_id]]), TaskMutationOrigin::Internal);
Auth::onceUsingId($owner->id);
$request = Request::create(route('tasks.show', [$workspace, $task]), 'GET', server: ['HTTP_X_INERTIA' => 'true']);
$request->setUserResolver(static fn () => $owner);
$request->setLaravelSession(app('session')->driver());
$app->instance('request', $request);
Inertia::share(app(HandleInertiaRequests::class)->share($request));
Inertia::share('workspaceNavigation', app(WorkspaceNavigationFactory::class)->for($workspace, $owner, $company)->toArray());
$page = $app->call([app(ClientTaskController::class), 'show'], ['request' => $request, 'workspace' => $workspace, 'clientTask' => $task->fresh()])->toResponse($request);
$body = json_decode($page->getContent(), true, flags: JSON_THROW_ON_ERROR);
if ($body['component'] !== 'clients/task' || ! is_array($body['props']['planning_details'] ?? null)
    || ! is_array($body['props']['task']['assignee_options'] ?? null)) {
    throw new RuntimeException('The current task controller did not return seeded task and planning props.');
}
$catalog = $app->call([app(ClientTaskPlanningController::class), 'page'], ['request' => $request, 'workspace' => $workspace])->toResponse($request);
$body['catalog_page'] = json_decode($catalog->getContent(), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($runtime.'/controller-props.json', json_encode($body, JSON_THROW_ON_ERROR));
chmod($runtime.'/controller-props.json', 0600);
