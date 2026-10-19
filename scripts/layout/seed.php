<?php

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientPortalInvitation;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\ClientTimeEntry;
use App\Models\InvoiceReminderLog;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Queries\Expenses\WorkspaceExpenseSchedules;
use App\Services\Access\WorkspaceAccess;
use App\Services\Billing\ExpenseInvoiceAllocations;
use App\Services\Billing\InvoiceCorrectionService;
use App\Services\Billing\InvoiceFromTimeService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\RecordReceivedPayment;
use App\Services\Files\AttachmentStorageService;
use App\Services\Square\Mirror\SquareMirrorRepository;
use App\Services\Square\Mirror\SquareSnapshotMapper;
use App\Services\Square\SquareLinkService;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\PortalAccessScope;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\ReceivedPaymentData;
use App\Support\Expenses\NewExpense;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Browser fixtures can only be created from the CLI.');
}

$runtime = getenv('SVC_LAYOUT_RUNTIME');
if (! is_string($runtime)) {
    throw new RuntimeException('Run pnpm test:layout to create an isolated harness.');
}

$app = require __DIR__.'/bootstrap.php';
// The runner creates an empty file in a new mkdtemp directory for every run.
if (filesize($runtime.'/database.sqlite') !== 0) {
    throw new RuntimeException('Refusing to seed an existing database.');
}
// The API guard also needs signing keys for first-party sessions. Keep these
// disposable keys inside this run's private runtime, never in the repository.
$signingKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($signingKey === false || ! openssl_pkey_export($signingKey, $privateKey)) {
    throw new RuntimeException('Cannot initialize disposable API signing keys.');
}
$keyDetails = openssl_pkey_get_details($signingKey);
if (! is_array($keyDetails)) {
    throw new RuntimeException('Cannot initialize disposable API public key.');
}
$keyDirectory = $runtime.'/storage/app/private/oauth';
if (! is_dir($keyDirectory)) {
    mkdir($keyDirectory, 0700, true);
}
foreach (['private' => $privateKey, 'public' => $keyDetails['key']] as $kind => $value) {
    $keyFile = $keyDirectory.'/oauth-'.$kind.'.key';
    file_put_contents($keyFile, $value);
    chmod($keyFile, 0600);
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
$company->forceFill([
    'billing_email' => 'billing-notifications@synthetic-layout.example.test',
    'automatic_invoice_email_enabled' => true,
    'automatic_invoice_email_delay_days' => 365,
])->save();
$reviewInvoice = $service->issue($service->createDraft($workspace, $company, [
    'invoice_number' => 'REVIEW-SYNTHETIC-'.str_repeat('Y', 48), 'currency' => 'USD',
], [[
    'type' => 'adjustment', 'description' => str_repeat('SyntheticReviewDescriptionWithoutBreaks', 10),
    'quantity' => '1', 'unit_amount' => 12500, 'tax_amount' => 0, 'sort_order' => 1,
]]), $workspace);
app(InvoiceCorrectionService::class)->correct($reviewInvoice, $workspace, 1, true,
    app(WorkspaceClock::class)->today($workspace)->addDays(20)->toDateString(),
    str_repeat('SyntheticCorrectionReasonWithoutBreaks', 12), null);
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
// Portal contacts on the settings page: one reaching the whole client, one limited to the
// project above, both with a name and an address that will not break on their own.
foreach ([PortalAccessScope::Company, PortalAccessScope::Projects] as $index => $scope) {
    $contact = User::factory()->create([
        'name' => str_repeat('SyntheticPortalContact', 6).$index,
        'email' => str_repeat('synthetic', 12).$index.'@client.example.test',
        'oauth_provider' => 'synthetic', 'oauth_subject' => 'synthetic-portal-contact-'.$index,
    ]);
    app(WorkspaceAccess::class)->grantPortal(AccessMutationContext::system(), $company, $contact, $scope,
        $scope === PortalAccessScope::Projects ? [$project->public_id] : []);
    if ($scope === PortalAccessScope::Company) {
        $squarePortalContact = $contact;
    }
}
// Portal invitations (#430): one pending, with an address and a link that will not break on
// their own, and one not applied. Written directly: the seed has no identity provider to ask.
foreach (['pending', 'not_applied', 'revoked'] as $index => $status) {
    $email = str_repeat('syntheticinvitee', 8).$index.'@client.example.test';
    (new ClientPortalInvitation)->forceFill([
        'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'email' => $email,
        'pending_email' => $status === 'pending' ? $email : null, 'access_scope' => PortalAccessScope::Projects->value,
        'project_ids' => [$project->id], 'provider_invitation_id' => $index + 1, 'email_sent' => $status === 'pending',
        'link' => $status === 'pending' ? 'https://identity.example.test/invitations/'.str_repeat('synthetictoken', 8) : null,
        'expires_at' => now()->addDays(7), 'status' => $status,
        'not_applied_reason' => $status === 'not_applied' ? 'projects_moved' : null,
        'provider_revocation_pending' => $status === 'revoked',
    ])->save();
}
if (getenv('SVC_LAYOUT_PORTAL_PAGES') === '1') {
    for ($index = 0; $index < 100; $index++) {
        (new ClientPortalInvitation)->forceFill([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'email' => 'page-fixture-'.$index.'@client.example.test', 'access_scope' => PortalAccessScope::Company->value,
            'project_ids' => [], 'status' => 'not_applied', 'not_applied_reason' => 'projects_moved',
        ])->save();
    }
}
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

(new WorkspaceExpenseSchedules($workspace))->create($company, $project->public_id,
    new NewExpense(CarbonImmutable::parse($date)->subMonthsNoOverflow(2), 1200, 'USD', $description),
    BillingCadence::Monthly);
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

$logProject = ClientProject::query()->create([
    'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
    'name' => 'ZZZ Synthetic second logging project', 'status' => 'active',
]);

$correction = ClientTimeEntry::query()->create([
    'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
    'user_id' => $owner->id, 'worked_on' => $date, 'minutes' => 60, 'description' => 'Synthetic draft correction',
    'is_billable' => true, 'status' => 'approved', 'billing_rate_amount' => 12000, 'billing_rate_source' => 'explicit', 'currency' => 'USD',
]);
$companion = ClientTimeEntry::query()->create([
    'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
    'user_id' => $owner->id, 'worked_on' => $date, 'minutes' => 30, 'description' => 'Synthetic preserved time',
    'is_billable' => true, 'status' => 'approved', 'billing_rate_amount' => 12000, 'billing_rate_source' => 'explicit', 'currency' => 'USD',
]);
$draft = app(InvoiceFromTimeService::class)->create($workspace, $company,
    ['invoice_number' => 'SYNTHETIC-DRAFT-'.str_repeat('X', 50), 'currency' => 'USD'], [$correction->public_id, $companion->public_id],
    [['type' => 'service', 'description' => 'Synthetic preserved fee', 'quantity' => '1', 'unit_amount' => 500]]);

$squareInvoice = $service->issue($service->createDraft($workspace, $company, [
    'invoice_number' => 'SQUARE-SYNTHETIC-'.str_repeat('X', 100), 'currency' => 'USD',
], [[
    'type' => 'adjustment', 'description' => $description,
    'quantity' => '1', 'unit_amount' => 12_345, 'tax_amount' => 0, 'sort_order' => 1,
]]), $workspace);
$squareInvoice->forceFill(['is_visible_to_client' => true])->save();
config([
    'services.square.access_token' => 'synthetic-square-layout-token',
    'services.square.environment' => 'sandbox',
    'services.square.workspace' => $workspace->public_id,
]);
$squareRow = app(SquareSnapshotMapper::class)->invoice([
    'id' => 'inv_fixture_layout', 'version' => 1, 'invoice_number' => '000001-R-0001',
    'location_id' => 'LOCFIXTURE01', 'status' => 'UNPAID', 'title' => str_repeat('SyntheticSquareTitle', 12),
    'primary_recipient' => ['customer_id' => 'cust_fixture_layout', 'company_name' => str_repeat('SyntheticSquareCustomer', 8)],
    'payment_requests' => [['due_date' => $date, 'computed_amount_money' => ['amount' => 12_345, 'currency' => 'USD']]],
    'public_url' => 'https://squareupsandbox.com/pay-invoice/inv_fixture_layout',
]);
app(SquareMirrorRepository::class)->storeInvoices($workspace, [$squareRow], CarbonImmutable::now('UTC'));
app(SquareLinkService::class)->link($workspace, $squareInvoice, $owner, AgentApiVersion::for($squareInvoice),
    new SquareInvoiceReference('inv_fixture_layout', '000001-R-0001', 'USD', SquareMatchMethod::Manual), false);
app(RecordReceivedPayment::class)->record($workspace, $squareInvoice, ReceivedPaymentData::from([
    'amount' => 12_345, 'currency' => 'USD', 'received_on' => $date, 'method' => 'cash',
]));

// Payment reminders (#438): an open invoice of a client with the cadence on,
// and a logged reminder of every kind with oversized recipients and reasons.
$company->forceFill(['payment_reminders_enabled_at' => CarbonImmutable::parse($date)->subMonth()])->save();
$reminderInvoice = $service->issue($service->createDraft($workspace, $company, [
    'invoice_number' => 'REMINDER-SYNTHETIC-'.str_repeat('X', 80), 'currency' => 'USD',
    'issue_date' => CarbonImmutable::parse($date)->subDays(20)->toDateString(), 'due_date' => CarbonImmutable::parse($date)->subDays(5)->toDateString(),
], [[
    'type' => 'adjustment', 'description' => $description,
    'quantity' => '1', 'unit_amount' => 12_345, 'tax_amount' => 0, 'sort_order' => 1,
]]), $workspace);
foreach ([
    ['origin' => 'automatic', 'cadence_step' => 'due_minus_3', 'pay_link' => 'svc_portal', 'status' => 'sent', 'triggered_by' => 'scheduler'],
    ['origin' => 'automatic', 'cadence_step' => 'due', 'pay_link' => 'none', 'status' => 'failed', 'triggered_by' => 'scheduler', 'error_summary' => 'Email delivery failed (TransportException).'],
    ['origin' => 'manual', 'cadence_step' => null, 'pay_link' => 'none', 'status' => 'cancelled', 'triggered_by' => 'website', 'triggered_by_user_id' => $owner->id, 'error_summary' => 'Not sent: '.str_repeat('SyntheticUnbrokenReason', 10)],
] as $attributes) {
    InvoiceReminderLog::query()->create($attributes + [
        'workspace_id' => $workspace->id, 'client_invoice_id' => $reminderInvoice->id, 'channel' => 'email',
        'recipients' => [str_repeat('synthetic-accounts-payable', 4).'@synthetic-layout.example.test', 'billing-notifications@synthetic-layout.example.test'],
        'subject' => 'Payment reminder: invoice '.$reminderInvoice->invoice_number, 'currency' => 'USD', 'amount_due' => 12_345,
        'due_on' => $reminderInvoice->due_date, 'attempt_number' => 1, 'queued_at' => CarbonImmutable::now('UTC'), 'sent_at' => CarbonImmutable::now('UTC'),
    ]);
}

// The agreement page's deferred backlog (#366): its own client, so no other
// fixture's capacity or invoices change. Oversized on purpose - an unbroken
// client and agreement name, and a shelf priced in the millions.
$backlogAgreementPath = null;
if (getenv('SVC_LAYOUT_DEFERRED_BACKLOG') === '1') {
    $backlogCompany = ClientCompany::query()->create([
        'workspace_id' => $workspace->id, 'name' => str_repeat('SyntheticBacklog', 5),
        'slug' => 'synthetic-layout-backlog-client',
    ]);
    $backlogProject = ClientProject::query()->create([
        'workspace_id' => $workspace->id, 'client_company_id' => $backlogCompany->id,
        'name' => str_repeat('SyntheticBacklogProject', 4),
    ]);
    $backlogAgreement = ClientAgreement::query()->create([
        'workspace_id' => $workspace->id, 'client_company_id' => $backlogCompany->id,
        'title' => str_repeat('SyntheticRetainerAgreement', 4), 'status' => 'active', 'currency' => 'USD',
        'billing_cadence' => 'monthly', 'starts_on' => CarbonImmutable::parse($date)->subMonthsNoOverflow(8)->startOfMonth()->toDateString(),
        'retainer_minutes' => 1200, 'hourly_rate_amount' => 987_654,
    ]);
    foreach ([3, 2, 1, 0] as $monthsAgo) {
        ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $backlogCompany->id,
            'client_project_id' => $backlogProject->id, 'user_id' => $owner->id,
            'worked_on' => CarbonImmutable::parse($date)->subMonthsNoOverflow($monthsAgo)->startOfMonth()->toDateString(),
            'minutes' => 4_170 + 30 * $monthsAgo, 'description' => str_repeat('SyntheticDeferredBacklogWork', 6),
            'is_billable' => true, 'is_deferred' => true, 'status' => 'approved',
            'billing_rate_amount' => 987_654, 'currency' => 'USD',
        ]);
    }
    $backlogAgreementPath = route('clients.agreement', [$workspace, $backlogCompany, $backlogAgreement], absolute: false);
}

file_put_contents($runtime.'/fixture.json', json_encode([
    'agreement' => $backlogAgreementPath,
    'reminder_invoice' => route('clients.invoice', [$workspace, $company, $reminderInvoice], absolute: false),
    'square_explore' => route('square.explore', $workspace, absolute: false),
    'square_invoice' => route('clients.invoice', [$workspace, $company, $squareInvoice], absolute: false),
    'square_portal' => route('portal.invoice', [$company, $squareInvoice], absolute: false),
    'square_portal_user_id' => $squarePortalContact->id,
    'mcp_setup' => route('mcp.setup', $workspace, absolute: false),
    'draft_invoice' => route('clients.invoice', [$workspace, $company, $draft], absolute: false),
    'draft_time' => route('clients.time', [$workspace, $company, 'draft_invoice' => $draft->public_id], absolute: false),
    'workspace_id' => $workspace->id, 'log_project_id' => $logProject->id,
    'project_log' => route('clients.time', [$workspace, $company, 'log_project' => $logProject->public_id], absolute: false),
    'draft_id' => $draft->id, 'correction_id' => $correction->id, 'companion_id' => $companion->id,
    'preserved_lines' => $draft->lines()->whereDoesntHave('timeEntries')->orderBy('id')->get()->map->getAttributes()->all(),
    'draft_writes' => [
        'POST' => [route('agent-api.v1.time-entries.store', $workspace, absolute: false), route('agent-api.v1.invoices.add_time', [$workspace, $draft->public_id], absolute: false), route('svc.billing.invoices.issue', [$workspace, $draft], absolute: false), route('svc.billing.invoices.payments.store', [$workspace, $draft], absolute: false),
            '/api/v1/workspaces/'.$workspace->public_id.'/time-entries/'.$correction->public_id.'/unapprove', '/api/v1/workspaces/'.$workspace->public_id.'/time-entries/approve'],
        'PATCH' => ['/api/v1/workspaces/'.$workspace->public_id.'/time-entries/'.$correction->public_id],
    ],
    'receipts' => route('svc.expenses.receipts', [$workspace, $company, $receiptExpense->public_id], absolute: false),
    'user_id' => $owner->id, 'date' => $date,
    'expense_schedule_store' => route('svc.expense-schedules.store', [$workspace, $company], absolute: false),
    'expense_schedule_prefix' => '/workspaces/'.$workspace->public_id.'/expense-schedules/',
    'expense_schedules' => route('clients.expense-schedules', [$workspace, $company], absolute: false),
    'invoice' => route('clients.invoice', [$workspace, $company, $invoice], absolute: false),
    'invoice_review' => route('clients.invoice', [$workspace, $company, $reviewInvoice], absolute: false),
    'settings' => route('clients.settings', [$workspace, $company], absolute: false),
    'portal_settings' => route('clients.settings', [$workspace, $company], absolute: false).'?invitation_page=2',
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
