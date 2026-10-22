<?php

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\WorkspaceAccess;
use App\Services\WorkspaceProjectMutationAction;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\PortalAccessScope;
use App\Support\Access\StaffRole;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! str_starts_with($input['connection']['database'], 'svc_probe_')) {
    exit(2);
}
config(['database.connections.collaboration_race' => $input['connection'], 'database.default' => 'collaboration_race']);
DB::purge('collaboration_race');
$workspace = Workspace::query()->whereKey($input['workspace'])->firstOrFail();
$project = ClientProject::query()->where('workspace_id', $workspace->id)->whereKey($input['project'])->firstOrFail();
$owner = User::query()->whereKey($input['owner'])->firstOrFail();
$actor = User::query()->whereKey($input['actor'])->firstOrFail();
if ($input['operation'] === 'portal_granted') {
    $company = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($project->client_company_id)->firstOrFail();
    app(WorkspaceAccess::class)->grantPortal(AccessMutationContext::user($owner), $company, $actor, PortalAccessScope::Company);
} elseif ($input['operation'] === 'staff_demoted') {
    app(WorkspaceAccess::class)->changeStaffRole(AccessMutationContext::user($owner), $workspace, $actor, StaffRole::Member);
} else {
    app(WorkspaceProjectMutationAction::class)->updateAccess($owner, $workspace, $project, $actor->public_id, 'viewer');
}
echo "committed\n";
