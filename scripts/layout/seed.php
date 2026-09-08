<?php

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Services\Billing\ExpenseInvoiceAllocations;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Files\AttachmentStorageService;
use App\Support\Expenses\NewExpense;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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
    'terms' => $description, 'currency' => 'USD', 'status' => 'sent',
    'is_visible_to_client' => true, 'sent_at' => app(WorkspaceClock::class)->now($workspace),
]);
$proposal->items()->create([
    'workspace_id' => $workspace->id, 'description' => $description,
    'quantity' => 2, 'unit_amount' => 5000, 'cadence' => 'one_time', 'sort_order' => 0,
]);

$project = ClientProject::query()->create([
    'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
    'name' => str_repeat('SyntheticProject', 10), 'status' => 'active',
]);
foreach ([
    ['description' => str_repeat('SyntheticUnbrokenWork', 12), 'status' => 'approved', 'minutes' => 100],
    ['description' => $description, 'status' => 'approved', 'minutes' => 60,
        'subcontractor_billing_mode' => 'flat_hourly', 'subcontractor_cost_amount' => 7500, 'subcontractor_cost_currency' => 'USD'],
    ['description' => 'Synthetic unapproved work', 'status' => 'draft', 'minutes' => 30],
    ['description' => 'Synthetic deferred work', 'status' => 'approved', 'minutes' => 30, 'is_deferred' => true],
] as $attributes) {
    ClientTimeEntry::query()->create($attributes + [
        'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
        'client_project_id' => $project->id, 'user_id' => $owner->id,
        'worked_on' => $date, 'is_billable' => true, 'is_deferred' => false,
        'billing_rate_amount' => 12345, 'currency' => 'USD',
    ]);
}

$expenseBoundary = new WorkspaceExpenses($workspace);
foreach (['USD', 'EUR'] as $currency) {
    $expense = $expenseBoundary->record($company, $project, new NewExpense(
        CarbonImmutable::parse($date), 12500, $currency, str_repeat('SyntheticExpenseDescription', 15),
    ), $owner);
    $expenseBoundary->approve($expense, $owner);
}
$expenseBoundary->record($company, $project, new NewExpense(
    CarbonImmutable::parse($date), 2500, 'USD', $description,
), $owner);
$expenseInvoice = $service->createDraft($workspace, $company, [
    'invoice_number' => 'EXP-SYNTHETIC-'.str_repeat('X', 50), 'currency' => 'USD',
    'invoice_kind' => 'cadence_period', 'service_period_start' => $date, 'service_period_end' => $date,
], [['type' => 'adjustment', 'description' => 'Synthetic expense allocation fixture', 'quantity' => '1', 'unit_amount' => 100]]);
DB::transaction(fn () => app(ExpenseInvoiceAllocations::class)->rebuild($expenseInvoice, $date));

$receiptExpense = app(WorkspaceExpenses::class, ['workspace' => $workspace])->record(
    $company, null, new NewExpense(
        CarbonImmutable::parse($date), 12500, 'USD', str_repeat('SyntheticReceiptDescription', 15),
    ), $owner,
);
app(AttachmentStorageService::class)->store(
    $workspace, $receiptExpense,
    UploadedFile::fake()->createWithContent(str_repeat('SyntheticReceiptFilename', 8).'.txt', 'Synthetic layout receipt'), $owner,
);

file_put_contents($runtime.'/fixture.json', json_encode([
    'receipts' => route('svc.expenses.receipts', [$workspace, $company, $receiptExpense->public_id], absolute: false),
    'user_id' => $owner->id, 'date' => $date,
    'invoice' => route('clients.invoice', [$workspace, $company, $invoice], absolute: false),
    'proposal' => route('clients.proposal', [$workspace, $company, $proposal], absolute: false),
    'proposal_acceptance' => route('portal.proposal', [$company, $proposal], absolute: false),
    'expenses' => route('clients.expenses', [$workspace, $company], absolute: false),
    'time' => route('clients.time', [$workspace, $company], absolute: false),
    'operations' => route('workspaces.operations', $workspace, absolute: false),
], JSON_THROW_ON_ERROR));
// Wayfinder runs Artisan from the repository during the asset build. Give it
// this already-sanitized configuration so it skips the repository's .env too.
file_put_contents($runtime.'/config.php', '<?php return '.var_export(config()->all(), true).';');
echo "Synthetic layout fixtures created.\n";
