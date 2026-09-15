<?php

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Services\Billing\InvoiceFromTimeService;
use App\Services\Billing\InvoiceLifecycleService;
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
$entry = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->findOrFail($input['entry']);
if ($input['operation'] === 'issue') {
    $invoice = ClientInvoice::query()->where('workspace_id', $workspace->id)->findOrFail($input['invoice']);
    $invoice = app(InvoiceLifecycleService::class)->issue($invoice, $workspace);
} else {
    $invoice = app(InvoiceFromTimeService::class)->create($workspace, $company,
        ['invoice_number' => 'SYNTHETIC-RACE', 'currency' => 'USD'], [$entry->public_id]);
}
echo json_encode(['invoice' => $invoice->id, 'version' => $entry->fresh()->lock_version], JSON_THROW_ON_ERROR)."\n";
