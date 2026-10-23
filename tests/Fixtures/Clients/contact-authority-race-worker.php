<?php

use App\Models\ClientCompany;
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
if ($input['change'] === 'demote') {
    WorkspaceMembership::query()->where('workspace_id', $input['workspace'])->where('user_id', $input['actor'])->sole()->update(['role' => 'member']);
} else {
    $company = $input['change'] === 'portal_new_company'
        ? ClientCompany::query()->create(['workspace_id' => $input['workspace'], 'name' => 'New synthetic portal company', 'slug' => 'new-synthetic-portal-company'])
        : ClientCompany::query()->where('workspace_id', $input['workspace'])->whereKey($input['company'])->sole();
    $company->portalUsers()->attach($input['actor'], ['role' => 'client', 'access_scope' => 'company']);
}
echo "committed\n";
