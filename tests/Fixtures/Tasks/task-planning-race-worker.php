<?php

use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\TaskPatchData;
use App\Services\WorkspaceProjectMutationAction;
use App\Services\WorkspaceTaskMutationAction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! str_starts_with($input['connection']['database'], 'svc_probe_')) {
    exit(2);
}
config(['database.connections.task_race' => $input['connection'], 'database.default' => 'task_race']);
DB::purge('task_race');
$workspace = Workspace::query()->whereKey($input['workspace'])->firstOrFail();
$project = ClientProject::query()->where('workspace_id', $workspace->id)->whereKey($input['project'])->firstOrFail();
$owner = User::query()->whereKey($input['owner'])->firstOrFail();
if ($input['operation'] === 'assignment_added') {
    app(WorkspaceTaskMutationAction::class)->update($owner, $workspace, $input['task'], TaskPatchData::fromValidated(['assignee_ids' => [$input['target']]]));
} else {
    if ($input['operation'] === 'assignment_removed_and_target_revoked') {
        app(WorkspaceTaskMutationAction::class)->update($owner, $workspace, $input['task'], TaskPatchData::fromValidated(['assignee_ids' => []]));
    }
    $subject = $input['operation'] === 'actor_demoted' ? $input['actor'] : $input['target'];
    $role = $input['operation'] === 'actor_demoted' ? 'viewer' : 'none';
    app(WorkspaceProjectMutationAction::class)->updateAccess($owner, $workspace, $project, $subject, $role);
}
echo "committed\n";
