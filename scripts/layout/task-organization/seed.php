<?php

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\Organization\TaskContainer;
use App\Services\Tasks\Organization\TaskOrganizationAction;
use App\Services\Tasks\Organization\TaskOrganizationCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Synthetic organization fixtures require the CLI.');
}
$app = require dirname(__DIR__).'/bootstrap.php';
$runtime = getenv('SVC_LAYOUT_RUNTIME');
if (filesize($runtime.'/database.sqlite') !== 0) {
    throw new RuntimeException('Refusing to seed an existing database.');
}
$signingKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($signingKey === false || ! openssl_pkey_export($signingKey, $privateKey)) {
    throw new RuntimeException('Cannot initialize disposable signing keys.');
}
$details = openssl_pkey_get_details($signingKey);
if (! is_array($details)) {
    throw new RuntimeException('Cannot initialize disposable public key.');
}
mkdir($runtime.'/storage/app/private/oauth', 0700, true);
foreach (['private' => $privateKey, 'public' => $details['key']] as $kind => $value) {
    $file = $runtime.'/storage/app/private/oauth/oauth-'.$kind.'.key';
    file_put_contents($file, $value);
    chmod($file, 0600);
}
Artisan::call('migrate', ['--force' => true]);
require __DIR__.'/routes.php';
$owner = User::factory()->create(['name' => 'Synthetic Organization Owner', 'email' => 'organization-owner@example.test']);
$workspace = Workspace::query()->create(['name' => 'Synthetic Organization Workspace', 'slug' => 'synthetic-organization', 'timezone' => 'UTC']);
$workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
$company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => str_repeat('SyntheticCompany', 15), 'slug' => 'synthetic-organization-company']);
$project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => str_repeat('SyntheticProject', 15), 'is_visible_to_client' => true]);
$hiddenProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic private project', 'is_visible_to_client' => false]);
$action = app(TaskOrganizationAction::class);
$perform = static function (ClientProject $target, string $operation, array $data) use ($action, $owner, $workspace): string {
    $data += ['expected_graph_version' => $action->inspect($owner, $workspace, $target->public_id)->graphVersion()];

    return $action->perform($owner, $workspace, $target->public_id, TaskOrganizationCommand::fromInput($operation, $data))[0];
};
$createList = static fn (string $name, bool $visible): string => $perform($project, 'lists.create', ['name' => $name, 'is_visible_to_client' => $visible]);
$publicList = $createList(str_repeat('SyntheticPublicList', 12), true);
$privateList = $createList(str_repeat('SyntheticPrivateList', 12), false);
$archivedList = $createList(str_repeat('SyntheticArchivedList', 11), true);
$createTask = static fn (ClientProject $target, string $title, ?string $parent, ?string $list, bool $visible = true): string => $perform($target, 'tasks.create', [
    'task' => ['title' => $title, 'description' => str_repeat('Synthetic organization description. ', 60), 'is_visible_to_client' => $visible], 'parent_id' => $parent, 'list_id' => $list,
]);
$root = $createTask($project, str_repeat('SyntheticRoot', 18), null, $publicList);
$child = $createTask($project, str_repeat('SyntheticChild', 17), $root, null);
$grandchild = $createTask($project, str_repeat('SyntheticGrandchild', 12), $child, null);
$destination = $createTask($project, str_repeat('SyntheticDestination', 11), null, $publicList);
$createTask($project, 'Synthetic destination child', $destination, null);
$privateParent = $createTask($project, str_repeat('SyntheticPrivateParent', 10), null, $publicList, false);
$privateParentChild = $createTask($project, 'Synthetic checked child of hidden parent', $privateParent, null);
$privateListTask = $createTask($project, 'Synthetic checked task in hidden list', null, $privateList);
$archivedTask = $createTask($project, 'Synthetic checked task in archived list', null, $archivedList);
$hiddenTask = $createTask($hiddenProject, 'Synthetic checked task in hidden project', null, null);
$graph = $action->inspect($owner, $workspace, $project->public_id);
$perform($project, 'lists.archive', ['list_id' => $archivedList, 'expected_version' => $graph->lists[$archivedList]['version'], 'confirm' => true]);
// Every browser page is selected from real authorized native presentation.
$view = static fn (ClientProject $target, TaskContainer $container, ?string $moving = null): array => $action->nativeView($owner, $workspace, $company, $target->public_id, $container, $moving)->data;
$public = $view($project, new TaskContainer(null, $publicList));
$page = static fn (array $data): string => $data['organization']['container']['breadcrumbs'][array_key_last($data['organization']['container']['breadcrumbs'])]['href'];
$pages = [
    'public_list' => $page($public),
    'private_list' => $page($view($project, new TaskContainer(null, $privateList))),
    'private_parent' => $page($view($project, new TaskContainer($privateParent, null))),
    'archived_list' => $page($view($project, new TaskContainer(null, $archivedList))),
    'project_hidden' => $page($view($hiddenProject, new TaskContainer(null, null))),
    'move_self' => $page($view($project, new TaskContainer(null, $publicList), $root)),
    'move_descendant' => $page($view($project, new TaskContainer($child, null), $root)),
    'move_destination' => $page($view($project, new TaskContainer($destination, null), $root)),
];
$writes = ['POST' => [], 'PATCH' => [], 'PUT' => [], 'DELETE' => []];
$addWrite = static function (string $method, ?string $url) use (&$writes): void {
    if ($url !== null) {
        $writes[$method][] = parse_url($url, PHP_URL_PATH);
    }
};
$organization = $public['organization'];
$addWrite('POST', $organization['create_list_href']);
$addWrite('POST', $organization['container']['create_task_href']);
$addWrite('PUT', $organization['reorder_lists_href']);
$addWrite('PUT', $organization['container']['reorder_href']);
foreach ($organization['lists'] as $list) {
    $addWrite('PATCH', $list['update_href']);
    $addWrite('DELETE', $list['archive_href']);
    $addWrite('POST', $list['restore_href']);
}
foreach ([$root, $child, $grandchild, $destination, $privateParent, $privateParentChild, $privateListTask, $archivedTask] as $id) {
    $moving = $view($project, new TaskContainer(null, $publicList), $id);
    $addWrite('PATCH', $moving['organization']['moving']['move_href']);
}
$fingerprints = [];
foreach (['client_tasks', 'client_task_lists', 'client_task_graph_states', 'client_task_assignments', 'agent_mutation_receipts', 'agent_mutation_audits'] as $table) {
    $fingerprints[$table] = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR));
}
$fixture = ['user_id' => $owner->id, 'workspace_id' => $workspace->public_id, 'project_id' => $project->public_id, 'company_name' => $company->name,
    'advisory_task_page' => collect($organization['container']['tasks'])->firstWhere('id', $root)['task_href'],
    'pages' => $pages, 'labels' => ['public_list' => $organization['lists'][0]['name'], 'private_list' => str_repeat('SyntheticPrivateList', 12), 'archived_list' => str_repeat('SyntheticArchivedList', 11), 'root' => str_repeat('SyntheticRoot', 18), 'child' => str_repeat('SyntheticChild', 17), 'destination' => str_repeat('SyntheticDestination', 11)],
    'writes' => array_map(static fn (array $paths): array => array_values(array_unique($paths)), $writes),
    'verify_ids' => ['project_hidden' => $hiddenProject->public_id, 'root' => $root, 'child' => $child, 'grandchild' => $grandchild, 'destination' => $destination, 'private_parent' => $privateParent, 'private_parent_child' => $privateParentChild, 'private_list_task' => $privateListTask, 'archived_task' => $archivedTask, 'hidden_task' => $hiddenTask],
    'fingerprints' => $fingerprints];
file_put_contents($runtime.'/fixture.json', json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
chmod($runtime.'/fixture.json', 0600);
echo "Synthetic organization fixture seeded.\n";
