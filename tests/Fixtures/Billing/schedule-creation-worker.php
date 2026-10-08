<?php

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Services\Billing\CreateBillingScheduleAction;
use App\Support\AgentApi\AgentApiVersion;
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
$workspace = Workspace::query()->findOrFail($input['workspace']);
$company = ClientCompany::query()->where('workspace_id', $workspace->id)->findOrFail($input['company']);
$schedule = app(CreateBillingScheduleAction::class)->create($workspace, $company, $input['data'], $input['expected_version']);
$agreement = ClientAgreement::query()->where('workspace_id', $workspace->id)->findOrFail($schedule->client_agreement_id);
echo json_encode(['agreement_version' => AgentApiVersion::for($agreement)], JSON_THROW_ON_ERROR)."\n";
