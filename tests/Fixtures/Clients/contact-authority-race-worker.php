<?php

use App\Models\WorkspaceMembership;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || ! str_starts_with($input['connection']['database'], 'svc_probe_')) {
    exit(2);
}
config(['database.connections.contact_race' => $input['connection'], 'database.default' => 'contact_race']);
DB::purge('contact_race');
WorkspaceMembership::query()->where('workspace_id', $input['workspace'])->where('user_id', $input['actor'])->sole()->update(['role' => 'member']);
echo "committed\n";
