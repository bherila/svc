<?php

use App\Models\ClientCompany;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\Artisan;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Browser fixtures can only be created from the CLI.');
}

$app = require __DIR__.'/bootstrap.php';
// The runner creates an empty file in a new mkdtemp directory for every run.
if (filesize($runtime.'/database.sqlite') !== 0) {
    throw new RuntimeException('Refusing to seed an existing database.');
}
Artisan::call('migrate', ['--force' => true]);

$owner = User::factory()->create([
    'name' => 'Synthetic Layout Owner', 'email' => 'layout-owner@example.test',
]);
$workspace = Workspace::query()->create([
    'name' => 'Synthetic Layout Workspace', 'slug' => 'synthetic-layout',
    'timezone' => 'America/Los_Angeles',
]);
$workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
$company = ClientCompany::query()->create([
    'workspace_id' => $workspace->id, 'name' => str_repeat('Synthetic', 8),
    'slug' => 'synthetic-layout-client',
]);
$description = str_repeat('Synthetic service description for layout verification. ', 8);
$service = app(InvoiceLifecycleService::class);
$invoice = $service->issue($service->createDraft($workspace, $company, [
    'invoice_number' => 'INV-SYNTHETIC-'.str_repeat('X', 50), 'currency' => 'USD',
], [[
    'type' => 'adjustment', 'description' => $description,
    'quantity' => '2', 'unit_amount' => 5000, 'tax_amount' => 0, 'sort_order' => 1,
]]), $workspace);
$date = app(WorkspaceClock::class)->today($workspace)->subDay()->toDateString();
$service->applyPayment($invoice, [
    'amount' => 1000, 'currency' => 'USD', 'method' => 'wire', 'received_on' => $date,
], $workspace);
$proposal = ClientProposal::query()->create([
    'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
    'title' => str_repeat('SyntheticProposal', 12), 'summary' => $description,
    'terms' => $description, 'currency' => 'USD', 'status' => 'draft',
]);
$proposal->items()->create([
    'workspace_id' => $workspace->id, 'description' => $description,
    'quantity' => 2, 'unit_amount' => 5000, 'cadence' => 'one_time', 'sort_order' => 0,
]);

file_put_contents($runtime.'/fixture.json', json_encode([
    'user_id' => $owner->id, 'date' => $date,
    'invoice' => route('clients.invoice', [$workspace, $company, $invoice], absolute: false),
    'proposal' => route('clients.proposal', [$workspace, $company, $proposal], absolute: false),
    'operations' => route('workspaces.operations', $workspace, absolute: false),
], JSON_THROW_ON_ERROR));
echo "Synthetic layout fixtures created.\n";
