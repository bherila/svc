<?php

use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\WorkspaceAccess;
use App\Services\Tasks\Organization\TaskOrganizationAction;
use App\Services\Tasks\Organization\TaskOrganizationCommand;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\PortalAccessScope;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! str_starts_with($input['connection']['database'], 'svc_probe_')) {
    exit(2);
}
config(['database.connections.organization_race' => $input['connection'], 'database.default' => 'organization_race', 'task_organization.enabled' => true]);
DB::purge('organization_race');
$workspace = Workspace::query()->whereKey($input['workspace'])->firstOrFail();
$owner = User::query()->whereKey($input['owner'])->firstOrFail();
if ($input['operation'] === 'portal') {
    $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic new organization company', 'slug' => 'synthetic-new-organization-company']);
    $actor = User::query()->whereKey($input['actor'])->firstOrFail();
    app(WorkspaceAccess::class)->grantPortal(AccessMutationContext::user($owner), $company, $actor, PortalAccessScope::Company);
    echo "committed\n";
} else {
    if ($input['operation'] === 'move') {
        echo "ready\n";
        flush();
    }
    try {
        app(TaskOrganizationAction::class)->perform($owner, $workspace, $input['project'], TaskOrganizationCommand::fromInput($input['operation'] === 'move' ? 'tasks.move' : 'lists.create', $input['body']));
        echo "committed\n";
    } catch (HttpExceptionInterface $exception) {
        echo 'refused:'.$exception->getStatusCode()."\n";
    }
}
