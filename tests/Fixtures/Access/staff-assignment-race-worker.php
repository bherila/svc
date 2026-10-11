<?php

use App\Models\ClientProjectMembership;
use App\Support\AgentApi\ProjectRole;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! str_starts_with($input['connection']['database'], 'svc_probe_')) {
    exit(2);
}
config(['database.connections.race' => $input['connection'], 'database.default' => 'race']);
DB::purge('race');
// Committed on its own connection, after the caller's snapshot was taken.
ClientProjectMembership::query()->create([
    'workspace_id' => $input['workspace'],
    'client_project_id' => $input['project'],
    'user_id' => $input['user'],
    'role' => ProjectRole::Contributor,
]);
echo "assigned\n";
