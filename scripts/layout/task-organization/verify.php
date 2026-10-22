<?php

use App\Http\Requests\TaskOrganizationInput;
use App\Models\ClientCompany;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\Organization\TaskOrganizationAction;
use App\Services\Tasks\TaskCompletionAdvisory;
use App\Services\Tasks\TaskEditorPresenter;
use App\Services\Tasks\WorkspaceTaskRepository;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Synthetic fixture verification requires the CLI.');
}
$app = require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/routes.php';
$runtime = getenv('SVC_LAYOUT_RUNTIME');
$fixture = json_decode(file_get_contents($runtime.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
$owner = User::query()->findOrFail($fixture['user_id']);
$workspace = Workspace::query()->where('public_id', $fixture['workspace_id'])->firstOrFail();
$action = app(TaskOrganizationAction::class);
foreach ([$fixture['project_id'], $fixture['verify_ids']['project_hidden']] as $projectId) {
    $action->inspect($owner, $workspace, $projectId, current: true)->assertValid();
}
$company = ClientCompany::query()->where('workspace_id', $workspace->id)->sole();
$views = [];
foreach ($fixture['pages'] as $name => $url) {
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
    $moving = $query['moving_task_id'] ?? null;
    unset($query['moving_task_id']);
    $container = TaskOrganizationInput::container(['container' => true] + $query);
    $projectId = $name === 'project_hidden' ? $fixture['verify_ids']['project_hidden'] : $fixture['project_id'];
    $views[$name] = $action->nativeView($owner, $workspace, $company, $projectId, $container, $moving)->data['organization'];
}
foreach (['private_list' => 'list is hidden', 'private_parent' => 'containing task is hidden', 'archived_list' => 'list is archived', 'project_hidden' => 'project is hidden'] as $name => $reason) {
    $container = $views[$name]['container'];
    if (! str_contains($container['sharing_note'] ?? '', $reason)
        || count($container['tasks']) !== 1
        || $container['tasks'][0]['is_visible_to_client'] !== true
        || $container['tasks'][0]['effective_visible_to_client'] !== false) {
        throw new RuntimeException('The '.$name.' fixture lost its checked-but-hidden publication case.');
    }
}
$root = collect($views['public_list']['container']['tasks'])->firstWhere('id', $fixture['verify_ids']['root']);
$movingRoot = collect($views['move_self']['container']['tasks'])->firstWhere('id', $fixture['verify_ids']['root']);
if ($root['effective_visible_to_client'] !== true || $movingRoot['move_target'] !== null
    || $views['move_descendant']['container']['move_target'] !== null
    || $views['move_destination']['container']['move_target'] === null
    || $views['archived_list']['container']['create_task_href'] !== null) {
    throw new RuntimeException('The publication, archive or filtered move-target fixtures changed.');
}
$advisoryTask = app(WorkspaceTaskRepository::class)->find($workspace, $fixture['verify_ids']['root']);
app(TaskCompletionAdvisory::class)->load($workspace, [$advisoryTask]);
$editor = app(TaskEditorPresenter::class)->task($workspace, $advisoryTask);
if ($editor['completion_advisory'] !== 'This task has unfinished subtasks. Completing it leaves those subtasks unchanged.'
    || $editor['status'] !== 'open' || $editor['completed_at'] !== null
    || route('tasks.show', [$workspace, $advisoryTask]) !== $fixture['advisory_task_page']
    || $editor['assignees_action'] !== $fixture['advisory_assignees_page']) {
    throw new RuntimeException('The native editor fixture lost its pre-completion advisory.');
}
foreach (['child', 'grandchild'] as $key) {
    $descendant = ClientTask::query()->where('workspace_id', $workspace->id)->where('client_project_id', $advisoryTask->client_project_id)
        ->where('public_id', $fixture['verify_ids'][$key])->sole();
    if ($descendant->status !== 'open' || $descendant->completed_at !== null) {
        throw new RuntimeException('The native editor journey changed an unfinished descendant.');
    }
}
foreach ($fixture['fingerprints'] as $table => $expected) {
    $actual = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR));
    if (! hash_equals($expected, $actual)) {
        throw new RuntimeException('Read-only browser journeys changed synthetic '.$table.'.');
    }
}
echo "Organization topology valid; all task, list, state, assignment, receipt and audit rows unchanged.\n";
