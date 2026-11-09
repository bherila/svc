<?php

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientAttachment;
use App\Models\ClientBillingSchedule;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientCompanyMembership;
use App\Models\ClientContact;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientPortalInvitation;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientProposal;
use App\Models\ClientStripeCustomer;
use App\Models\ClientStripePaymentMethod;
use App\Models\ClientTask;
use App\Models\ClientTaskAssignment;
use App\Models\ClientTaskLabel;
use App\Models\ClientTimeEntry;
use App\Models\InvoiceAutopayAttempt;
use App\Models\InvoiceReminderLog;
use App\Models\PaletteRecent;
use App\Models\ServicePackage;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Queries\Expenses\WorkspaceExpenseSchedules;
use App\Services\Access\WorkspaceAccess;
use App\Services\Billing\Autopay\AutopayConsentService;
use App\Services\Billing\ExpenseInvoiceAllocations;
use App\Services\Billing\InvoiceCorrectionService;
use App\Services\Billing\InvoiceFromTimeService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\RecordReceivedPayment;
use App\Services\Billing\StripeWebhookService;
use App\Services\Engagement\AgreementCancellations;
use App\Services\Files\AttachmentStorageService;
use App\Services\Finance\PaymentReconciliationService;
use App\Services\History\HistoricalLibraryRepository;
use App\Services\History\HistoricalRecordRepository;
use App\Services\Mcp\McpCredentialSettings;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Services\Packages\PackageRequestWorkflow;
use App\Services\Packages\ServicePackageWorkflow;
use App\Services\Payments\PaymentConnectionResolver;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Services\Review\ReviewerGrants;
use App\Services\SharedProjects\SharedProjectParticipations;
use App\Services\Square\Mirror\SquareMirrorRepository;
use App\Services\Square\Mirror\SquareSnapshotMapper;
use App\Services\Square\SquareLinkService;
use App\Services\Tasks\TaskCreateData;
use App\Services\WorkspaceTaskMutationAction;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\PortalAccessScope;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\Autopay\AutopayActorRole;
use App\Support\Billing\Autopay\AutopayConsentRequest;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\InvoiceHoursStatement;
use App\Support\Billing\ReceivedPaymentData;
use App\Support\Engagement\Cancellation\CancellationTerms;
use App\Support\Expenses\NewExpense;
use App\Support\History\HistoryDocument;
use App\Support\History\HistoryKind;
use App\Support\Packages\PackageTerms;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\PaymentProcessor;
use App\Support\Review\GranteeKind;
use App\Support\Square\SquareInvoiceReference;
use App\Support\Square\SquareMatchMethod;
use App\Support\WorkspaceClock;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

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
if (getenv('SVC_LAYOUT_WORKSPACES') === '1' || getenv('SVC_LAYOUT_PAYMENTS') === '1') {
    $workspace->update(['name' => str_repeat('SyntheticWorkspaceWithoutBreaks', 4)]);
}
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
$layoutPayment = $service->applyPayment($invoice, [
    'amount' => 1000, 'currency' => 'USD', 'method' => 'wire', 'received_on' => $date,
], $workspace);
// Reconciled in finance, split across two transactions: the badge and both
// numbered links share the status cell.
foreach (['5f0d7c1e-0000-4000-8000-000000000001', '5f0d7c1e-0000-4000-8000-000000000002'] as $transaction) {
    app(PaymentReconciliationService::class)->upsert($workspace, $layoutPayment, $owner, [
        'external_system_slug' => 'finance', 'external_transaction_uuid' => $transaction,
        'allocated_amount' => 500, 'currency' => 'USD', 'is_active' => true,
    ]);
}
$company->forceFill([
    'billing_address' => str_repeat('SyntheticAddressWithoutBreaks', 12),
    'phone' => '+1 555 0100', 'website_url' => 'https://client.example.test',
    'tax_id' => str_repeat('SyntheticTaxIdentifier', 8), 'currency' => 'USD',
    'notes' => str_repeat('SyntheticCompanyNotesWithoutBreaks', 16),
])->save();
$historicalContact = ClientContact::query()->create([
    'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
    'first_name' => str_repeat('SyntheticContact', 6), 'last_name' => 'Example',
    'title' => str_repeat('SyntheticRole', 12), 'email' => 'contact@example.test',
    'phone' => '555-0101', 'notes' => str_repeat('SyntheticContactNotesWithoutBreaks', 12),
    'is_archived' => true,
]);
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

// Revenue screens (--revenue): seven clients named without a break, each with an
// agreement whose title has none either, a recurring service, six months of
// issued invoices priced in the millions, and agreements ending inside the
// window. Everything the Dashboard, the roster and the client overview print
// is read from these rows by the same code that serves them in production.
if (getenv('SVC_LAYOUT_REVENUE') === '1') {
    $today = app(WorkspaceClock::class)->today($workspace);
    $revenueNumber = 0;
    foreach (range(0, 6) as $index) {
        $client = $index === 0 ? $company : ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => str_repeat('SyntheticRevenueClient', 3).$index,
            'slug' => 'synthetic-revenue-client-'.$index,
        ]);
        $revenueAgreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $client->id,
            'title' => str_repeat('SyntheticRevenueAgreementWithoutBreaks', 3), 'status' => 'active', 'currency' => 'USD',
            'starts_on' => $today->subMonths(8)->startOfMonth()->toDateString(),
            'ends_on' => $index % 2 === 1 ? $today->addMonths(2 + $index)->toDateString() : null,
            'billing_cadence' => 'monthly', 'retainer_amount' => 250000000 + $index * 1000000, 'retainer_minutes' => 600,
            'hourly_rate_amount' => 20000, 'signed_at' => $today->subMonths(8)->toDateTimeString(),
        ]);
        $item = ClientAgreementRecurringItem::query()->create([
            'workspace_id' => $workspace->id, 'client_agreement_id' => $revenueAgreement->id, 'description' => str_repeat('SyntheticHostingServiceWithoutBreaks', 3),
            'cadence' => 'quarterly', 'anchor_month' => 1, 'anchor_day' => 1, 'quantity' => '1.000', 'amount' => 30000000,
            'currency' => 'USD', 'is_taxable' => false, 'is_active' => true, 'sort_order' => 1,
        ]);
        foreach (range(6, 1) as $back) {
            $start = $today->startOfMonth()->subMonths($back);
            $next = $start->addMonth();
            $lines = [['retainer', 250000000 + $index * 1000000, $next->toDateString(), null]];
            if ($next->month % 3 === 1) {
                $lines[] = ['recurring_item', 30000000, $next->toDateString(), $item->id];
            }
            if ($back % 2 === 0) {
                $lines[] = ['additional_hours', 40000000, $start->endOfMonth()->toDateString(), null];
            }
            if ($back === 1) {
                $lines[] = ['expense', 5000000, $start->endOfMonth()->toDateString(), null];
            }
            $revenueNumber++;
            $revenueInvoice = ClientInvoice::query()->create([
                'workspace_id' => $workspace->id, 'client_company_id' => $client->id, 'client_agreement_id' => $revenueAgreement->id,
                'invoice_number' => 'SYNTHETIC-REV-'.str_repeat('Z', 40).$revenueNumber, 'status' => $back > 2 ? 'paid' : 'issued',
                'currency' => 'USD', 'invoice_kind' => 'cadence_period',
                'service_period_start' => $start->toDateString(), 'service_period_end' => $start->endOfMonth()->toDateString(),
                'issue_date' => $next->toDateString(), 'due_date' => $next->addDays(14)->toDateString(),
            ]);
            $total = 0;
            foreach ($lines as $sort => [$type, $amount, $lineDate, $itemId]) {
                ClientInvoiceLine::query()->create([
                    'workspace_id' => $workspace->id, 'client_invoice_id' => $revenueInvoice->id, 'client_agreement_id' => $revenueAgreement->id,
                    'client_agreement_recurring_item_id' => $itemId, 'type' => $type, 'description' => 'Synthetic '.$type,
                    'quantity' => '1', 'unit_amount' => $amount, 'tax_amount' => 0, 'total_amount' => $amount, 'line_date' => $lineDate, 'sort_order' => $sort,
                ]);
                $total += $amount;
            }
            $billedHours = array_sum(array_map(static fn (array $line): float => $line[0] === 'additional_hours' ? $line[1] / 20000 : 0.0, $lines));
            $revenueInvoice->forceFill([
                'hours_billed_at_rate' => number_format($billedHours, 4, '.', ''), 'subtotal_amount' => $total, 'tax_amount' => 0,
                'total_amount' => $total, 'paid_amount' => $back > 2 ? $total : 0, 'balance_amount' => $back > 2 ? 0 : $total,
            ])->save();
        }
    }
}

$engagementAgreementPath = null;
if (getenv('SVC_LAYOUT_ENGAGEMENT') === '1') {
    $proposal->forceFill(['status' => 'draft', 'sent_at' => null])->save();
    $draftAgreement = ClientAgreement::query()->create([
        'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
        'title' => str_repeat('SyntheticDraftAgreementWithoutBreaks', 8), 'status' => 'draft',
        'currency' => 'USD', 'starts_on' => $date, 'billing_cadence' => 'monthly',
    ]);
    $activeAgreement = ClientAgreement::query()->create([
        'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
        'title' => str_repeat('SyntheticActiveAgreementWithoutBreaks', 8), 'status' => 'active',
        'currency' => 'USD', 'starts_on' => $date, 'billing_cadence' => 'monthly',
    ]);
    $scheduledAgreement = ClientAgreement::query()->create([
        'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
        'title' => str_repeat('SyntheticScheduledAgreementWithoutBreaks', 6), 'status' => 'active',
        'currency' => 'USD', 'starts_on' => $date, 'billing_cadence' => 'monthly',
    ]);
    ClientBillingSchedule::query()->create([
        'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
        'client_agreement_id' => $scheduledAgreement->id, 'cadence' => 'monthly', 'next_run_on' => $date,
        'due_days' => 30, 'currency' => 'USD', 'is_active' => true,
        'line_template' => [['type' => 'service', 'description' => str_repeat('SyntheticScheduleLine', 8), 'quantity' => '1', 'unit_amount' => 10000]],
    ]);
    $engagementAgreementPath = route('clients.agreement', [$workspace, $company, $draftAgreement], absolute: false);
    // The activity feed that used to be on the retired Operations screen, with
    // events whose words and subject have nowhere to break.
    foreach (['invoice.issued', 'agreement.activated', 'invoice.payment_received'] as $action) {
        ClientCompanyActivity::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'actor_user_id' => $owner->id,
            'action' => $action, 'subject_type' => 'client_agreement', 'subject_public_id' => $draftAgreement->public_id,
            'payload' => ['invoice_number' => str_repeat('SyntheticActivityInvoiceNumber', 6), 'note' => str_repeat('SyntheticUnbrokenNote', 12)],
        ]);
    }
}

$project = ClientProject::query()->create([
    'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
    'name' => str_repeat('SyntheticProject', 10), 'status' => 'active',
]);
if (getenv('SVC_LAYOUT_PROJECTS') === '1') {
    $project->update(['description' => $description, 'repository' => 'git.internal.example.test/platform-engineering-group/synthetic-project-layout-repository']);
    $projectMember = User::factory()->create(['name' => str_repeat('SyntheticProjectMember', 12), 'email' => 'synthetic-project-member@example.test']);
    $workspace->memberships()->create(['user_id' => $projectMember->id, 'role' => 'member']);
}
$planningTask = null;
if (getenv('SVC_LAYOUT_TASKS') === '1') {
    $taskAssignees = [];
    for ($index = 0; $index < 28; $index++) {
        $staff = User::factory()->create([
            'name' => str_repeat('SyntheticAssigneeWithoutBreaks', 7).str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'email' => 'synthetic-task-staff-'.$index.'@example.test',
        ]);
        $workspace->memberships()->create(['user_id' => $staff->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $staff->id, 'role' => 'contributor']);
        if ($index < 10) {
            $taskAssignees[] = $staff->public_id;
        }
    }
    $planningTask = app(WorkspaceTaskMutationAction::class)->create($owner, $workspace, $project->public_id, TaskCreateData::fromValidated([
        'title' => str_repeat('SyntheticTaskWithoutBreaks', 9),
        'description' => str_repeat('Synthetic task description with planning context and enough prose to wrap. ', 24),
        'is_visible_to_client' => true, 'starts_on' => '2026-10-20', 'due_on' => '2026-10-30', 'assignee_ids' => $taskAssignees,
    ]));
    for ($index = 0; $index < 53; $index++) {
        ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id,
            'title' => 'ZZZ Synthetic paginated task '.str_repeat('UnbrokenTitle', 8).' '.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'status' => 'open', 'is_visible_to_client' => true]);
    }
}

if (getenv('SVC_LAYOUT_TASK_LOOKUPS') === '1') {
    for ($index = 0; $index < 28; $index++) {
        $name = 'Synthetic lookup label '.str_repeat('UnbrokenLabel', 10).$index;
        ClientTaskLabel::query()->create(['workspace_id' => $workspace->id, 'name' => $name, 'name_key' => hash('sha256', $name)]);
        ClientContact::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'first_name' => 'Synthetic lookup contact '.str_repeat('UnbrokenContact', 10).$index]);
    }
}

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
    // A client's open dispute and a settled one (docs/ux-overhaul.md 7b″), with
    // a note and a reply that will not break.
    ['description' => 'Synthetic disputed work', 'status' => 'approved', 'minutes' => 45,
        'client_visible_description' => 'Synthetic disputed client text', 'is_visible_to_client' => true,
        'client_disputed_at' => now(), 'client_dispute_note' => 'Synthetic operator-side question '.str_repeat('UnbrokenDisputeNote', 12)],
    ['description' => 'Synthetic written-off work', 'status' => 'approved', 'minutes' => 15, 'is_billable' => false,
        'client_visible_description' => 'Synthetic written-off client text', 'is_visible_to_client' => true,
        'client_dispute_settlement' => 'written_off', 'client_dispute_reply' => 'Synthetic reply '.str_repeat('UnbrokenDisputeReply', 12),
        'client_dispute_settled_at' => now(), 'client_dispute_settled_by_user_id' => $owner->id],
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
// One approved expense the client disputed, before anything claims it.
$disputedExpense = $expenseBoundary->record($company, $project, new NewExpense(
    CarbonImmutable::parse($date), 3300, 'USD', 'Synthetic disputed expense '.str_repeat('UnbrokenExpense', 8),
), $owner);
$expenseBoundary->approve($disputedExpense, $owner);
ClientExpense::query()->whereKey($disputedExpense->id)->update([
    'client_disputed_at' => now(), 'client_dispute_note' => 'Synthetic expense dispute '.str_repeat('UnbrokenDisputeNote', 12),
]);
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
    UploadedFile::fake()->createWithContent(str_repeat('SyntheticReceiptFilename', 8).'.pdf', 'Synthetic layout receipt'), $owner,
);

$logProject = ClientProject::query()->create([
    'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
    'name' => 'ZZZ Synthetic second logging project', 'status' => 'active',
]);

// A colleague's hour, because nobody approves their own: the correction journey
// returns it to draft and the owner approves it again.
$correctionAuthor = User::factory()->create(['name' => 'Synthetic Colleague', 'email' => 'layout-colleague@example.test']);
$workspace->memberships()->create(['user_id' => $correctionAuthor->id, 'role' => 'member']);
$correction = ClientTimeEntry::query()->create([
    'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
    'user_id' => $correctionAuthor->id, 'worked_on' => $date, 'minutes' => 60, 'description' => 'Synthetic draft correction',
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
// A client sees an invoice's "Still deferred" list only when its agreement opts in.
$squareAgreement = ClientAgreement::query()->create([
    'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic Square agreement',
    'status' => 'expired', 'currency' => 'USD', 'starts_on' => '2022-01-01', 'ends_on' => '2022-12-31',
    'billing_cadence' => 'monthly', 'retainer_minutes' => 0, 'retainer_amount' => 0, 'hourly_rate_amount' => 10000,
    'show_deferred_backlog_to_client' => true, 'is_visible_to_client' => false,
]);
$squareInvoice->forceFill([
    'is_visible_to_client' => true,
    'client_agreement_id' => $squareAgreement->id,
    'deferred_work_statement' => ['version' => 1, 'entries' => [
        ['id' => 1, 'workedOn' => '2026-04-01', 'minutes' => 1, 'description' => null],
        ['id' => 2, 'workedOn' => '2026-04-02', 'minutes' => 61, 'description' => str_repeat('SyntheticDeferredWork', 40)],
        ['id' => 3, 'workedOn' => '2026-04-03', 'minutes' => 180, 'description' => str_repeat('Synthetic deferred work description. ', 16)],
    ]],
])->save();
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
// A Square invoice it covers besides (#487), numbered with no break in it.
app(SquareLinkService::class)->cover($workspace, $squareInvoice->refresh(), $owner, AgentApiVersion::for($squareInvoice),
    new SquareInvoiceReference('inv_fixture_layout_covered', 'SYNTHETICCOVERED'.str_repeat('X', 120), 'USD', SquareMatchMethod::Manual));
app(RecordReceivedPayment::class)->record($workspace, $squareInvoice, ReceivedPaymentData::from([
    'amount' => 12_345, 'currency' => 'USD', 'received_on' => $date, 'method' => 'cash',
]));

if (getenv('SVC_LAYOUT_DISPUTES') === '1') {
    $service->setPaymentDisputed($layoutPayment, 500, $workspace);
    $service->setPaymentDisputed($squareInvoice->payments()->sole(), 345, $workspace);
}

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
$backlogCreatePath = null;
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
    // Billed in Square from a location whose id has no break in it (#488).
    $backlogAgreement->forceFill(['square_series_key' => '000999', 'square_location_id' => 'LOC'.str_repeat('SYNTHETICLAYOUT', 12)])->save();
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
    $backlogCreatePath = route('agent-api.v1.agreements.deferred-backlog.create', [$workspace, $backlogAgreement], absolute: false);
}

$historyRecords = app(HistoricalRecordRepository::class);
$historySource = $historyRecords->source($workspace, 'synthetic', hash('sha256', 'synthetic-layout-history'), 'Synthetic history');
$historyProjectRecord = $historyRecords->remember($workspace, $historySource, 'projects', '1', ['name' => 'Synthetic historical project']);
$historyProject = app(HistoricalLibraryRepository::class)->publish($workspace, $historyProjectRecord,
    new HistoryDocument(HistoryKind::Project, 'Synthetic historical project', 'Preserved project description'), $company);
$historyDiscussionRecord = $historyRecords->remember($workspace, $historySource, 'discussions', '1', ['title' => 'Synthetic discussion']);
$historyDiscussion = app(HistoricalLibraryRepository::class)->publish($workspace, $historyDiscussionRecord,
    new HistoryDocument(HistoryKind::Discussion, str_repeat('SyntheticHistoricalDiscussion', 12), str_repeat('SyntheticHistoricalBodyWithoutBreaks', 25)."\n<script>Displayed as text only</script>",
        str_repeat('SyntheticHistoricalAuthor', 8), '2020-03-04 12:34:56', [['label' => 'Original project', 'value' => str_repeat('SyntheticHistoricalProject', 12)]]), $company, $historyProjectRecord);
foreach ([HistoryKind::Comment, HistoryKind::Note] as $historyKind) {
    $historyRecord = $historyRecords->remember($workspace, $historySource, $historyKind->value.'s', '1', ['body' => 'Synthetic preserved content']);
    app(HistoricalLibraryRepository::class)->publish($workspace, $historyRecord,
        new HistoryDocument($historyKind, str_repeat('SyntheticHistorical'.$historyKind->label(), 12), 'Synthetic preserved content', 'Casey Example', '2020-03-05'), $company, $historyDiscussionRecord);
}
$historyInvoiceRecord = $historyRecords->remember($workspace, $historySource, 'invoices', '1', ['total' => '2.99', 'discount' => '0.01']);
$historyInvoice = app(HistoricalLibraryRepository::class)->publish($workspace, $historyInvoiceRecord,
    new HistoryDocument(HistoryKind::Invoice, 'SYNTHETIC-HISTORICAL-'.str_repeat('INVOICE', 35), 'Original invoice note', 'Casey Example', '2020-03-04', [
        ['label' => 'Original billing name', 'value' => str_repeat('SyntheticOriginalBillTo', 12)],
        ['label' => 'Subtotal', 'value' => 'USD 3.00'], ['label' => 'Discount', 'value' => 'USD 0.01'],
        ['label' => 'Total', 'value' => 'USD 2.99'], ['label' => 'Paid', 'value' => 'USD 2.99'], ['label' => 'Original balance', 'value' => 'USD 0.00'],
    ], [
        ['label' => 'Invoice items', 'columns' => ['Description', 'Quantity', 'Unit cost', 'Original discount', 'Gross total'], 'rows' => array_fill(0, 3, [str_repeat('SyntheticHistoricalLineDescription', 16), '1.00', '1.00', '0.00400', '1.00'])],
        ['label' => 'Payments', 'columns' => ['Method', 'Date', 'Amount'], 'rows' => [['Historical manual receipt', '2020-03-10', 'USD 2.99']]],
    ]), $company);

$taskViewer = $owner;
if ($planningTask !== null && getenv('SVC_LAYOUT_TASK_LOOKUPS') !== '1') {
    $taskViewer = User::factory()->create(['name' => 'Synthetic Project Manager', 'email' => 'layout-project-manager@example.test']);
    $workspace->memberships()->create(['user_id' => $taskViewer->id, 'role' => 'member']);
    ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $taskViewer->id, 'role' => 'manager']);
}

// Payment connections (--payments): one workspace per state, all owned by the
// same synthetic owner, with unbroken names. Connected through the repository,
// so no processor is asked; the keys are obviously fake.
// "My work" and the portal home (--work-portal). A member who is not an owner or
// admin holds one project as a contributor and one as a manager, with assigned
// tasks across every due-date group and time in every review state, all priced
// in the rows so that a rate reaching the page would show. The client contact
// sees an overdue invoice linked to a hosted payment page, a second open one, an
// agreement with a recurring service and an unbroken title, hours past the
// retainer, deferred work the agreement discloses, approved time and open tasks.
$workPortal = [];
if (getenv('SVC_LAYOUT_WORK_PORTAL') === '1') {
    $workToday = app(WorkspaceClock::class)->today($workspace);
    $project->forceFill(['is_visible_to_client' => true])->save();
    $logProject->forceFill(['is_visible_to_client' => true])->save();
    $invoice->forceFill(['is_visible_to_client' => true, 'due_date' => $workToday->addDays(20)->toDateString()])->save();

    $workMember = User::factory()->create(['name' => str_repeat('SyntheticSubcontractor', 4), 'email' => 'layout-subcontractor@example.test']);
    $workspace->memberships()->create(['user_id' => $workMember->id, 'role' => 'member']);
    foreach ([[$project, 'contributor'], [$logProject, 'manager']] as [$held, $role]) {
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $held->id, 'user_id' => $workMember->id, 'role' => $role]);
    }
    $assign = function (ClientProject $in, string $title, ?string $due, string $status = 'open') use ($workspace, $workMember): void {
        $task = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $in->id, 'title' => $title, 'status' => $status, 'due_on' => $due, 'is_visible_to_client' => true]);
        DB::table('client_task_assignments')->insert(['workspace_id' => $workspace->id, 'client_task_id' => $task->id, 'user_id' => $workMember->id, 'created_at' => now(), 'updated_at' => now()]);
    };
    foreach ([
        [$project, 'SyntheticOverdueTaskWithoutBreaks'.str_repeat('Overdue', 8), $workToday->subDays(4)->toDateString(), 'open'],
        [$logProject, 'SyntheticManagedTaskWithoutBreaks'.str_repeat('Managed', 8), $workToday->subDay()->toDateString(), 'in_progress'],
        [$project, 'Synthetic task due today with a description long enough to wrap onto a second line on a phone', $workToday->toDateString(), 'open'],
        [$logProject, 'SyntheticLaterThisWeekTask'.str_repeat('Week', 12), $workToday->endOfWeek()->toDateString(), 'open'],
        [$project, 'SyntheticLaterTask'.str_repeat('Later', 14), $workToday->addWeeks(3)->toDateString(), 'in_progress'],
        [$project, 'SyntheticUndatedTask'.str_repeat('Undated', 12), null, 'open'],
    ] as [$in, $title, $due, $status]) {
        $assign($in, $title, $due, $status);
    }
    foreach ([[0, $project, 90, 'approved'], [0, $logProject, 150, 'draft'], [1, $project, 60, 'submitted'], [3, $project, 45, 'invoiced'], [9, $logProject, 120, 'approved'], [20, $project, 30, 'invoiced']] as [$back, $in, $minutes, $status]) {
        ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $in->id, 'user_id' => $workMember->id,
            'worked_on' => $workToday->subDays($back)->toDateString(), 'minutes' => $minutes, 'description' => 'Synthetic subcontractor work '.str_repeat('Unbroken', 6),
            'is_billable' => true, 'status' => $status, 'billing_rate_amount' => 987654, 'billing_rate_source' => 'explicit', 'currency' => 'USD',
            'subcontractor_cost_amount' => 765432,
        ]);
    }

    $workAgreement = ClientAgreement::query()->create([
        'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
        'title' => str_repeat('SyntheticPortalAgreementWithoutBreaks', 3), 'status' => 'active', 'currency' => 'USD',
        'starts_on' => $workToday->subMonths(6)->startOfMonth()->toDateString(), 'billing_cadence' => 'monthly',
        'retainer_amount' => 250000000, 'retainer_minutes' => 600, 'hourly_rate_amount' => 20000,
        'is_visible_to_client' => true, 'show_deferred_backlog_to_client' => true, 'signed_at' => $workToday->subMonths(6)->toDateTimeString(),
    ]);
    ClientAgreementRecurringItem::query()->create([
        'workspace_id' => $workspace->id, 'client_agreement_id' => $workAgreement->id, 'description' => str_repeat('SyntheticWebHostingServiceWithoutBreaks', 3),
        'cadence' => 'monthly', 'anchor_day' => 20, 'quantity' => '1.000', 'amount' => 30000000, 'currency' => 'USD', 'is_taxable' => false, 'is_active' => true, 'sort_order' => 1,
    ]);
    foreach (range(0, 4) as $index) {
        ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'user_id' => $owner->id,
            'worked_on' => $workToday->startOfMonth()->addDays($index)->min($workToday)->toDateString(), 'minutes' => 150,
            'description' => 'Synthetic internal note '.str_repeat('Internal', 8),
            'client_visible_description' => 'Synthetic client summary '.str_repeat('WithoutBreaks', 8).' '.$index,
            'is_visible_to_client' => true, 'is_billable' => true, 'status' => 'approved',
        ]);
    }
    // "To approve": the client contact holds the permission by an explicit grant. This
    // month's five hours above are waiting; last month adds hours, expenses and one row the
    // contact already disputed, all with names that will not break and no price on the hours.
    ClientCompanyMembership::query()->where('client_company_id', $company->id)->where('user_id', $squarePortalContact->id)
        ->update(['can_approve_time_and_expenses' => true]);
    $lastMonth = $workToday->subMonthNoOverflow()->startOfMonth();
    foreach (range(0, 2) as $index) {
        ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'user_id' => $owner->id,
            'worked_on' => $lastMonth->addDays($index * 9)->toDateString(), 'minutes' => 195, 'description' => 'Synthetic internal note '.str_repeat('Internal', 8),
            'client_visible_description' => 'Synthetic last month '.str_repeat('WithoutBreaksAtAll', 9).' '.$index,
            'is_visible_to_client' => true, 'is_billable' => true, 'status' => 'approved', 'billing_rate_amount' => 987654, 'currency' => 'USD',
        ]);
    }
    ClientTimeEntry::query()->create([
        'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'user_id' => $owner->id,
        'worked_on' => $lastMonth->addDays(20)->toDateString(), 'minutes' => 45, 'description' => 'Synthetic internal note',
        'client_visible_description' => 'Synthetic already disputed '.str_repeat('Unbroken', 10),
        'is_visible_to_client' => true, 'is_billable' => true, 'status' => 'approved',
        'client_disputed_at' => now(), 'client_disputed_by_user_id' => $squarePortalContact->id, 'client_dispute_note' => 'Synthetic dispute note '.str_repeat('WithoutBreaks', 12),
    ]);
    foreach ([[$lastMonth->addDays(3), 123456789], [$workToday->startOfMonth(), 98765]] as $index => [$spent, $cents]) {
        ClientExpense::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'created_by_user_id' => $owner->id,
            'spent_on' => $spent->toDateString(), 'amount' => $cents, 'currency' => 'USD', 'status' => 'approved',
            'description' => 'Synthetic expense '.str_repeat('UnbrokenExpenseDescription', 5).$index,
        ]);
    }
    foreach (range(0, 3) as $index) {
        ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id,
            'title' => 'Synthetic client task '.str_repeat('UnbrokenTitle', 8).$index, 'status' => $index % 2 === 0 ? 'open' : 'in_progress', 'is_visible_to_client' => true]);
    }

    // The invoice to deal with first: overdue, priced in the millions, linked to a hosted payment page.
    $payInvoice = $service->issue($service->createDraft($workspace, $company, [
        'invoice_number' => 'PAY-SYNTHETIC-'.str_repeat('P', 60), 'currency' => 'USD',
    ], [[
        'type' => 'adjustment', 'description' => $description,
        'quantity' => '1', 'unit_amount' => 920000000, 'tax_amount' => 0, 'sort_order' => 1,
    ]]), $workspace);
    $payInvoice->forceFill([
        'is_visible_to_client' => true, 'due_date' => $workToday->subDays(40)->toDateString(), 'client_agreement_id' => $workAgreement->id,
        'deferred_work_statement' => ['version' => 1, 'entries' => [
            ['id' => 11, 'workedOn' => '2026-04-01', 'minutes' => 90, 'description' => str_repeat('SyntheticDeferredWork', 20)],
            ['id' => 12, 'workedOn' => '2026-04-02', 'minutes' => 240, 'description' => null],
        ]],
    ])->save();
    $payRow = app(SquareSnapshotMapper::class)->invoice([
        'id' => 'inv_fixture_pay', 'version' => 1, 'invoice_number' => '000002-R-0001',
        'location_id' => 'LOCFIXTURE01', 'status' => 'UNPAID', 'title' => str_repeat('SyntheticSquareTitle', 4),
        'primary_recipient' => ['customer_id' => 'cust_fixture_layout', 'company_name' => 'Synthetic'],
        'payment_requests' => [['due_date' => $date, 'computed_amount_money' => ['amount' => 920000000, 'currency' => 'USD']]],
        'public_url' => 'https://squareupsandbox.com/pay-invoice/inv_fixture_pay',
    ]);
    app(SquareMirrorRepository::class)->storeInvoices($workspace, [$payRow], CarbonImmutable::now('UTC'));
    app(SquareLinkService::class)->link($workspace, $payInvoice->refresh(), $owner, AgentApiVersion::for($payInvoice),
        new SquareInvoiceReference('inv_fixture_pay', '000002-R-0001', 'USD', SquareMatchMethod::Manual), false);

    $workPortal = [
        'my_work' => route('workspaces.my-work', $workspace, absolute: false),
        'work_member_id' => $workMember->id,
        'portal_home' => route('portal.show', $company, absolute: false),
    ];
}

// The invoice document (--invoice-document): one issued invoice with every part
// the document can print, all of it unbroken. Lines in four sections, a hours
// statement with rollover and a catch-up, a still-deferred list the agreement
// discloses, an appendix of entries (one with only an internal note, so the
// client copy prints the generic label), a partial payment, and a Stripe
// connection beside the Square one so the portal offers both. A second client
// contact reads the portal; the active agreement lets the proforma preview.
$documentPages = [];
if (getenv('SVC_LAYOUT_INVOICE_DOCUMENT') === '1') {
    $documentToday = app(WorkspaceClock::class)->today($workspace);
    $project->forceFill(['is_visible_to_client' => true])->save();
    $documentAgreement = ClientAgreement::query()->create([
        'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => str_repeat('SyntheticDocumentRetainer', 5),
        'status' => 'expired', 'currency' => 'USD', 'starts_on' => $documentToday->subMonths(8)->startOfMonth()->toDateString(), 'ends_on' => $documentToday->subMonths(2)->toDateString(),
        'billing_cadence' => 'monthly', 'retainer_minutes' => 600, 'retainer_amount' => 100000, 'hourly_rate_amount' => 10000,
        'show_deferred_backlog_to_client' => true, 'is_visible_to_client' => true,
    ]);
    // The agreement the proforma previews: active, with nothing billed under it yet.
    $proformaAgreement = ClientAgreement::query()->create([
        'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => str_repeat('SyntheticProformaRetainer', 5),
        'status' => 'active', 'currency' => 'USD', 'starts_on' => $documentToday->subMonths(1)->startOfMonth()->toDateString(),
        'billing_cadence' => 'monthly', 'retainer_minutes' => 600, 'retainer_amount' => 100000, 'hourly_rate_amount' => 10000,
    ]);
    $documentInvoice = ClientInvoice::query()->create([
        'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $documentAgreement->id,
        'invoice_number' => 'DOC-SYNTHETIC-'.str_repeat('Z', 60), 'invoice_kind' => 'ad_hoc', 'status' => 'partially_paid', 'currency' => 'USD',
        'issue_date' => $documentToday->subDays(5)->toDateString(), 'due_date' => $documentToday->addDays(25)->toDateString(),
        'service_period_start' => $documentToday->subMonth()->startOfMonth()->toDateString(), 'service_period_end' => $documentToday->subMonth()->endOfMonth()->toDateString(),
        'subtotal_amount' => 228_700, 'tax_amount' => 5_000, 'total_amount' => 233_700, 'paid_amount' => 50_000, 'balance_amount' => 183_700,
        'is_visible_to_client' => true, 'reissued_at' => $documentToday->subDay()->toDateTimeString(),
    ]);
    $documentInvoice->forceFill([
        'hours_statement' => (new InvoiceHoursStatement(...[
            'cadence' => 'monthly', 'workStart' => '2026-10-01', 'workEnd' => '2026-10-31', 'retainerStart' => '2026-11-01', 'retainerEnd' => '2026-11-30',
            'openingRetainerHours' => 10.0, 'openingRolloverHours' => 3.5, 'openingDeficitHours' => 1.0, 'openingExpiredHours' => 2.0, 'expiredWithinPeriodHours' => 0.0,
            'ordinaryHours' => 14.5, 'ordinaryAppliedToWorkPool' => 12.5, 'ordinaryAppliedToNextRetainer' => 0.5, 'ordinaryBilledAtRate' => 1.5,
            'subcontractorHours' => 2.0, 'deferredAppliedHours' => 1.25, 'deferredAppliedEntries' => 2, 'recarriedSettledHours' => 0.5,
            'deferredBilledOnTerminationHours' => 0.0, 'catchUpBilledHours' => 1.0, 'minimumAvailabilityHours' => 2.0, 'minimumAvailabilityThresholdHours' => 8.0,
            'interimBilledHours' => 0.25, 'rolledForwardHours' => 1.5, 'expiringHours' => 0.5, 'deficitCarriedForwardHours' => 0.0,
            'deferredBacklogHours' => 3.5, 'deferredBacklogEntries' => 2, 'recarriedRemainingHours' => 0.25, 'nextRetainerHours' => 10.0,
            'retainerSoldBy' => null, 'poolRemainingHours' => null, 'availableBeforeHours' => null,
        ]))->toArray(),
        'deferred_work_statement' => ['version' => 2, 'entries' => [
            ['id' => 901, 'workedOn' => '2026-06-01', 'minutes' => 90, 'description' => str_repeat('SyntheticDeferredWork', 20), 'projectId' => $project->id],
            ['id' => 902, 'workedOn' => '2026-06-15', 'minutes' => 120, 'description' => null, 'projectId' => $project->id],
        ]],
    ])->save();
    $documentLines = [];
    foreach ([
        ['retainer', 'SyntheticRetainerLineWithoutBreaks'.str_repeat('R', 40), '1', 150_000, 0],
        ['additional_hours', 'Additional hours '.str_repeat('SyntheticOverageDescription', 6), '2.5', 15_000, 5_000],
        ['recurring_item', 'SyntheticHostingWithoutBreaks'.str_repeat('H', 40), '1', 10_000, 0],
        ['expense', 'SyntheticStagingDatabaseWithoutBreaks'.str_repeat('E', 40), '1', 41_200, 0],
        ['carried_deferred_applied', 'Deferred work applied (1.25 hrs)', '1', 0, 0],
        ['credit', 'SyntheticCreditWithoutBreaks'.str_repeat('C', 40), '1', -5_000, 0],
    ] as $order => [$type, $text, $quantity, $unit, $tax]) {
        $documentLines[$type] = ClientInvoiceLine::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_id' => $documentInvoice->id, 'type' => $type, 'description' => $text,
            'quantity' => $quantity, 'unit_amount' => $unit, 'tax_amount' => $tax, 'total_amount' => (int) round($unit * (float) $quantity) + $tax,
            'sort_order' => $order + 1,
        ]);
    }
    // Appendix B: three expenses billed on three expense lines. The first has a
    // PNG, a PDF and a file whose name has no break; the second a JPEG; the
    // third none. Only the two images are shared with the client. The names have no break either. Real bytes, stored through
    // the application's own adapter on the harness's private disk.
    $documentExpenseLines = [$documentLines['expense']];
    foreach ([['SyntheticLabResultsApiUsageWithoutBreaks'.str_repeat('A', 40), 9_900, 8], ['SyntheticParkingWithoutBreaks'.str_repeat('P', 40), 1_500, 9]] as [$text, $amount, $order]) {
        $documentExpenseLines[] = ClientInvoiceLine::query()->create([
            'workspace_id' => $workspace->id, 'client_invoice_id' => $documentInvoice->id, 'type' => 'expense', 'description' => $text,
            'quantity' => '1', 'unit_amount' => $amount, 'tax_amount' => 0, 'total_amount' => $amount, 'sort_order' => $order,
        ]);
    }
    $documentInvoice->forceFill(['subtotal_amount' => 240_100, 'total_amount' => 245_100, 'balance_amount' => 195_100])->save();
    foreach ($documentExpenseLines as $position => $expenseLine) {
        $documentExpense = ClientExpense::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'created_by_user_id' => $owner->id,
            'spent_on' => sprintf('2026-10-%02d', 10 + $position), 'amount' => $expenseLine->unit_amount, 'currency' => 'USD',
            'description' => $expenseLine->description, 'status' => 'invoiced', 'client_invoice_line_id' => $expenseLine->id,
        ]);
        $files = match ($position) {
            0 => [
                UploadedFile::fake()->image(str_repeat('SyntheticReceiptPhoto', 8).'.png', 640, 420),
                UploadedFile::fake()->createWithContent('scan.pdf', '%PDF-1.4 synthetic layout receipt'),
                UploadedFile::fake()->createWithContent(str_repeat('SyntheticReceiptScanWithoutBreaks', 6).'.pdf', '%PDF-1.4 synthetic layout receipt'),
            ],
            1 => [UploadedFile::fake()->image('api-usage.jpg', 480, 640)],
            default => [],
        };
        foreach ($files as $index => $file) {
            $stored = app(AttachmentStorageService::class)->store($workspace, $documentExpense, $file, $owner);
            // A receipt is created private. The two images are shared with the
            // client, as the sharing action does it; the two PDFs stay private,
            // so the portal invoice and the client PDF show B1 (one image) and B2.
            if (in_array($position.'.'.$index, ['0.0', '1.0'], true)) {
                DB::table('client_attachments')->where('id', $stored->id)->update(['is_visible_to_client' => true]);
            }
        }
    }
    foreach ([[3, 90, 'SyntheticInternalNoteWithoutBreaks'.str_repeat('N', 40), false, null], [4, 60, 'Internal only', true, 'Reconciled the purchase order '.str_repeat('SyntheticClientNote', 8)], [5, 45, 'Internal only', false, null]] as [$day, $minutes, $note, $visible, $written]) {
        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'user_id' => $owner->id,
            'worked_on' => sprintf('2026-10-%02d', $day), 'minutes' => $minutes, 'description' => $note, 'is_billable' => true, 'is_deferred' => false,
            'status' => 'approved', 'billing_rate_amount' => 15_000, 'currency' => 'USD', 'is_visible_to_client' => $visible, 'client_visible_description' => $written,
        ]);
        $documentLines['additional_hours']->timeEntries()->attach($entry->id, ['workspace_id' => $workspace->id]);
    }
    $documentConnections = app(WorkspacePaymentConnections::class);
    $documentConnections->connect($workspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, $owner, [
        'external_account_id' => 'acct_synthetic_document', 'display_name' => 'Synthetic', 'environment' => 'test',
        'secret' => 'sk_test_'.str_repeat('synthetic', 4).'0001', 'webhook_secret' => 'whsec_synthetic',
        // The workspace's own publishable key: the portal offers card payment only for a key it knows is this account's.
        'publishable_key' => 'pk_test_layout_synthetic',
    ]);
    $documentPages = [
        'document_invoice' => route('clients.invoice', [$workspace, $company, $documentInvoice], absolute: false),
        'document_portal' => route('portal.invoice', [$company, $documentInvoice], absolute: false),
        'document_proforma' => route('clients.proforma', [$workspace, $company, $proformaAgreement], absolute: false),
    ];
}

// MCP credentials for the MCP setup page's "Show all tools" switches (--credentials):
// three connected apps (one on) and an MCP token, each with a name that has no
// break opportunity. Synthetic ids and reserved domains only.
if (getenv('SVC_LAYOUT_CREDENTIALS') === '1') {
    $makeClient = static function (string $name, bool $personal = false): string {
        $id = (string) Str::uuid();
        Passport::client()->forceFill([
            'id' => $id, 'name' => $name, 'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => $personal ? ['personal_access'] : ['authorization_code', 'refresh_token'], 'revoked' => false,
        ])->save();

        return $id;
    };
    $makeToken = static function (string $client, string $name) use ($owner): string {
        $token = Passport::token()->forceFill([
            'id' => Str::random(80), 'user_id' => $owner->id, 'client_id' => $client, 'name' => $name,
            'scopes' => ['mcp:use'], 'revoked' => false, 'expires_at' => now()->addYear(),
            'resource_uri' => OAuthResourceIndicator::resource('mcp'),
        ]);
        $token->save();

        return (string) $token->id;
    };
    $on = $makeClient(str_repeat('SyntheticConnectedAppNameWithoutBreaks', 4));
    $makeToken($on, 'Synthetic grant');
    $makeToken($makeClient('Short Synthetic App'), 'Synthetic grant');
    $makeToken($makeClient(str_repeat('SyntheticConnectedAppNameWithoutBreaks', 4).'Second'), 'Synthetic grant');
    $tokenId = $makeToken($makeClient('Synthetic personal client', personal: true), (string) config('bherila-auth.oauth_server.credentials.token_name_prefix').str_repeat('SyntheticMcpTokenNameWithoutBreaks', 4));
    app(McpCredentialSettings::class)->setForConnection($owner->id, $on, true);
}

// Autopay (--autopay, #636): a provider with unbroken names, one client with autopay
// on (a consent record of four entries, upcoming charges either side of the limit,
// a refused charge and a skipped invoice) and one with it off. Nothing here asks Stripe.
$autopayPages = [];
if (getenv('SVC_LAYOUT_AUTOPAY') === '1') {
    $apWorkspace = Workspace::query()->create(['name' => str_repeat('SyntheticAutopayProvider', 4), 'slug' => 'layout-autopay', 'timezone' => 'America/Los_Angeles']);
    $apWorkspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
    app(WorkspacePaymentConnections::class)->connect($apWorkspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, $owner, [
        'external_account_id' => 'acct_synthetic_autopay', 'display_name' => 'Synthetic', 'environment' => 'test',
        'secret' => 'sk_test_'.str_repeat('synthetic', 4).'0002', 'webhook_secret' => 'whsec_synthetic',
        // The portal offers a client "Add payment method" only for a key it knows is this account's (#642).
        'publishable_key' => 'pk_test_layout_synthetic',
    ]);
    $apToday = app(WorkspaceClock::class)->today($apWorkspace);
    $apMakeCompany = static function (string $name, string $slug, string $contactName, string $email) use ($apWorkspace): array {
        $apCompany = ClientCompany::query()->create(['workspace_id' => $apWorkspace->id, 'name' => $name, 'slug' => $slug, 'billing_email' => $email, 'currency' => 'USD']);
        $apContact = User::factory()->create(['name' => $contactName, 'email' => 'layout-'.$slug.'@example.test']);
        $apCompany->portalUsers()->attach($apContact->id, ['workspace_id' => $apWorkspace->id]);
        $apCustomer = ClientStripeCustomer::query()->create(['workspace_id' => $apWorkspace->id, 'client_company_id' => $apCompany->id, 'stripe_customer_id' => 'cus_layout_'.$slug]);
        foreach ([['pm_layout_'.$slug.'_card', 'card', 'visa', '4242', true], ['pm_layout_'.$slug.'_bank', 'us_bank_account', null, '6789', false]] as [$providerId, $type, $brand, $last4, $default]) {
            ClientStripePaymentMethod::query()->create([
                'workspace_id' => $apWorkspace->id, 'client_company_id' => $apCompany->id, 'client_stripe_customer_id' => $apCustomer->id,
                'stripe_payment_method_id' => $providerId, 'type' => $type, 'brand' => $brand, 'last4' => $last4,
                'exp_month' => $type === 'card' ? 8 : null, 'exp_year' => $type === 'card' ? 2038 : null, 'is_default' => $default,
            ]);
        }

        return [$apCompany, $apContact];
    };
    [$apOn, $apOnContact] = $apMakeCompany(str_repeat('SyntheticAutopayClientName', 4), 'autopay-on', str_repeat('SyntheticContactName', 4), 'billing@autopay-on.example.test');
    [$apOff, $apOffContact] = $apMakeCompany(str_repeat('SyntheticAutopayOffClient', 4), 'autopay-off', 'Off Contact', 'billing@autopay-off.example.test');
    $apInvoice = static function (ClientCompany $apCompany, string $number, int $amount, int $dueInDays) use ($apWorkspace, $apToday): ClientInvoice {
        return ClientInvoice::query()->create([
            'workspace_id' => $apWorkspace->id, 'client_company_id' => $apCompany->id, 'invoice_number' => $number, 'invoice_kind' => 'ad_hoc',
            'status' => 'issued', 'currency' => 'USD', 'subtotal_amount' => $amount, 'tax_amount' => 0, 'total_amount' => $amount, 'paid_amount' => 0,
            'balance_amount' => $amount, 'issue_date' => $apToday->subDays(20)->toDateString(), 'due_date' => $apToday->addDays($dueInDays)->toDateString(),
            'issued_at' => $apToday->subDays(20)->toDateTimeString(), 'is_visible_to_client' => true,
        ]);
    };
    $apService = app(AutopayConsentService::class);
    $apClientAsks = static function (int $limit) use ($apService, $apWorkspace, $apOn, $apOnContact): AutopayConsentRequest {
        return new AutopayConsentRequest(AutopayActorRole::Client, $apOnContact, $limit, wording: (string) $apService->authorizationWording($apWorkspace, $apOn->refresh(), $limit));
    };
    $apService->turnOn($apWorkspace, $apOn, $apClientAsks(400_000));
    $apService->turnOff($apWorkspace, $apOn->refresh(), AutopayActorRole::Client, $apOnContact);
    $apService->turnOn($apWorkspace, $apOn->refresh(), new AutopayConsentRequest(
        AutopayActorRole::Provider, $owner, 500_000,
        attestation: 'Agreed in the signed services agreement, clause 7: '.str_repeat('SyntheticAttestationWithoutBreaks', 6),
    ));
    $apWill = $apInvoice($apOn, 'INV-AP-WILL-'.str_repeat('X', 40), 375_000, 10);
    $apOver = $apInvoice($apOn, 'INV-AP-OVER-'.str_repeat('X', 40), 720_000, 20);
    $apFailed = $apInvoice($apOn, 'INV-AP-FAILED-'.str_repeat('X', 40), 250_000, 0);
    $apSkipped = $apInvoice($apOn, 'INV-AP-SKIPPED', 125_000, 5);
    $apOffInvoice = $apInvoice($apOff, 'INV-AP-OFF', 90_000, 10);
    $apHeld = $apInvoice($apOn, 'INV-AP-HELD-'.str_repeat('X', 40), 175_000, 0);
    $apClosed = $apInvoice($apOn, 'INV-AP-CLOSED-'.str_repeat('X', 40), 160_000, 0);
    InvoiceAutopayAttempt::query()->create([
        'workspace_id' => $apWorkspace->id, 'client_invoice_id' => $apFailed->id, 'client_company_id' => $apOn->id, 'state' => 'failed',
        'amount' => 250_000, 'currency' => 'USD', 'limit_amount' => 500_000, 'payment_method_label' => 'Visa ending 4242',
        'failure_code' => 'card_declined', 'failure_reason' => 'Your card was declined: '.str_repeat('SyntheticDeclineReasonWithoutBreaks', 5),
        'claimed_at' => now(), 'resolved_at' => now(),
    ]);
    // #641: a claim Stripe never answered (held for review), and one an admin closed out as not charged.
    InvoiceAutopayAttempt::query()->create([
        'workspace_id' => $apWorkspace->id, 'client_invoice_id' => $apHeld->id, 'client_company_id' => $apOn->id, 'state' => 'claimed',
        'amount' => 175_000, 'currency' => 'USD', 'limit_amount' => 500_000, 'payment_method_label' => 'Visa ending 4242',
        'claimed_at' => now()->subMinutes(45),
    ]);
    InvoiceAutopayAttempt::query()->create([
        'workspace_id' => $apWorkspace->id, 'client_invoice_id' => $apClosed->id, 'client_company_id' => $apOn->id, 'state' => 'not_charged',
        'amount' => 160_000, 'currency' => 'USD', 'limit_amount' => 500_000, 'payment_method_label' => 'Visa ending 4242',
        'claimed_at' => now()->subMinutes(90), 'resolved_at' => now()->subMinutes(30), 'resolved_by_user_id' => $owner->id,
        'resolution_note' => 'Checked the Stripe dashboard, no payment: '.str_repeat('SyntheticResolutionNoteWithoutBreaks', 5),
    ]);
    // #641: Stripe refused the workspace's connection after it was last saved: the provider's cards warn.
    DB::table('workspaces')->where('id', $apWorkspace->id)->update(['stripe_refused_at' => now()->addMinute()]);
    InvoiceAutopayAttempt::query()->create([
        'workspace_id' => $apWorkspace->id, 'client_invoice_id' => $apSkipped->id, 'client_company_id' => $apOn->id, 'state' => 'skipped',
        'skipped_by_user_id' => $owner->id, 'skipped_at' => now(), 'claimed_at' => now(), 'resolved_at' => now(),
    ]);
    $autopayPages = [
        'autopay_billing' => route('portal.billing', $apOn, absolute: false),
        'autopay_billing_off' => route('portal.billing', $apOff, absolute: false),
        'autopay_portal_will' => route('portal.invoice', [$apOn, $apWill], absolute: false),
        'autopay_portal_over' => route('portal.invoice', [$apOn, $apOver], absolute: false),
        'autopay_portal_failed' => route('portal.invoice', [$apOn, $apFailed], absolute: false),
        'autopay_settings' => route('clients.settings', [$apWorkspace, $apOn], absolute: false),
        'autopay_invoice_will' => route('clients.invoice', [$apWorkspace, $apOn, $apWill], absolute: false),
        'autopay_invoice_failed' => route('clients.invoice', [$apWorkspace, $apOn, $apFailed], absolute: false),
        'autopay_invoice_skipped' => route('clients.invoice', [$apWorkspace, $apOn, $apSkipped], absolute: false),
        'autopay_invoice_held' => route('clients.invoice', [$apWorkspace, $apOn, $apHeld], absolute: false),
        'autopay_invoice_closed' => route('clients.invoice', [$apWorkspace, $apOn, $apClosed], absolute: false),
        'autopay_portal_closed' => route('portal.invoice', [$apOn, $apClosed], absolute: false),
        'autopay_portal_user_id' => $apOnContact->id,
        'autopay_portal_off_user_id' => $apOffContact->id,
        'autopay_off_invoice' => route('portal.invoice', [$apOff, $apOffInvoice], absolute: false),
    ];
}

$paymentPages = [];
if (getenv('SVC_LAYOUT_PAYMENTS') === '1') {
    $connections = app(WorkspacePaymentConnections::class);
    $stripeWorkspace = Workspace::query()->create(['name' => str_repeat('SyntheticStripeWorkspaceWithoutBreaks', 3), 'slug' => 'layout-payments-stripe', 'timezone' => 'America/Los_Angeles']);
    $squareWorkspace = Workspace::query()->create(['name' => str_repeat('SyntheticSquareWorkspaceWithoutBreaks', 3), 'slug' => 'layout-payments-square', 'timezone' => 'America/Los_Angeles']);
    $reusedWorkspace = Workspace::query()->create(['name' => str_repeat('SyntheticReusedWorkspaceWithoutBreaks', 3), 'slug' => 'layout-payments-reused', 'timezone' => 'America/Los_Angeles']);
    foreach ([$stripeWorkspace, $squareWorkspace, $reusedWorkspace] as $paymentWorkspace) {
        $paymentWorkspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
    }
    $stripeRow = $connections->connect($stripeWorkspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, $owner, [
        'external_account_id' => 'acct_synthetic', 'display_name' => str_repeat('SyntheticBusinessNameWithoutBreaks', 6), 'environment' => 'live',
        'secret' => 'rk_live_'.str_repeat('synthetic', 6).'a1b2', 'webhook_secret' => 'whsec_synthetic',
        'publishable_key' => 'pk_live_'.str_repeat('Synthetic', 20),
    ]);
    $connections->connect($squareWorkspace, PaymentProcessor::Square, ConnectionOrigin::Entered, $owner, [
        'external_account_id' => 'MERCHANT_SYNTHETIC', 'display_name' => str_repeat('SyntheticSquareBusinessWithoutBreaks', 5), 'environment' => 'production',
        'secret' => 'EAAA'.str_repeat('synthetic', 6).'wxyz', 'webhook_secret' => 'synthetic-signature', 'location_id' => str_repeat('L', 64),
    ]);
    $connections->reuse($reusedWorkspace, $stripeRow, $owner);
    // The webhook health line (the check itself never runs here, no processor is
    // asked): one workspace per result the card can show, written the way the
    // check records it, and one a member (no buttons, no URL) reads.
    $required = StripeWebhookService::REQUIRED_EVENTS;
    $recordCheck = static function (string $name, string $slug, string $status, array $missing, string $role = 'owner') use ($connections, $owner): Workspace {
        $healthWorkspace = Workspace::query()->create(['name' => $name, 'slug' => $slug, 'timezone' => 'America/Los_Angeles']);
        $healthWorkspace->memberships()->create(['user_id' => $owner->id, 'role' => $role]);
        $row = $connections->connect($healthWorkspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, $owner, [
            'external_account_id' => 'acct_synthetic_'.$slug, 'display_name' => 'Synthetic webhook business', 'environment' => 'live',
            'secret' => 'rk_live_'.str_repeat('synthetic', 6).'a1b2', 'webhook_secret' => 'whsec_synthetic',
            'endpoint_token' => substr(hash('sha256', $slug), 0, 40),
        ]);
        $row->forceFill(['webhook_check_status' => $status, 'webhook_check_missing' => $missing, 'webhook_checked_at' => now()->subHours(3)])->save();

        return $healthWorkspace;
    };
    $recordCheck('Webhook healthy workspace', 'layout-webhook-healthy', 'healthy', []);
    $stripeRow->forceFill(['webhook_check_status' => 'healthy', 'webhook_check_missing' => [], 'webhook_checked_at' => now()->subHours(3)])->save();
    $webhookPages = [
        'payments_webhook_missing' => $recordCheck('Webhook missing workspace', 'layout-webhook-missing', 'missing_events', array_slice($required, 0, 14)),
        'payments_webhook_notfound' => $recordCheck('Webhook not found workspace', 'layout-webhook-notfound', 'not_found', []),
        'payments_webhook_denied' => $recordCheck('Webhook denied workspace', 'layout-webhook-denied', 'permission_denied', []),
        'payments_webhook_disabled' => $recordCheck('Webhook disabled workspace', 'layout-webhook-disabled', 'disabled', []),
        'payments_webhook_member' => $recordCheck('Webhook member workspace', 'layout-webhook-member', 'missing_events', ['payment_method.attached', 'customer.updated'], 'member'),
    ];
    $paymentPages = [
        ...array_map(static fn (Workspace $page): string => route('workspaces.payments', $page, absolute: false), $webhookPages),
        'payments' => route('workspaces.payments', $workspace, absolute: false),
        'payments_stripe' => route('workspaces.payments', $stripeWorkspace, absolute: false),
        'payments_square' => route('workspaces.payments', $squareWorkspace, absolute: false),
        'payments_reused' => route('workspaces.payments', $reusedWorkspace, absolute: false),
    ];
}

// Shared projects (--shared-projects): the host's project with oversized names,
// two participating workspaces (one of them administered by the participant
// who reads the participant page), tasks handed across workspaces, shared and
// internal comments, an open invitation on the host side and an invitation
// waiting for a person who owns two workspaces. The host's rates and time are
// priced in the rows so that one reaching a participant's page would show.
$sharedPages = [];
if (getenv('SVC_LAYOUT_SHARED_PROJECTS') === '1') {
    $project->forceFill(['name' => 'Shared '.str_repeat('UnbrokenProjectName', 6), 'description' => 'SECRET-HOST-PROJECT-NOTE', 'is_visible_to_client' => true])->save();
    $participations = app(SharedProjectParticipations::class);
    $partnerOwner = User::factory()->create(['name' => 'Partner '.str_repeat('Owner', 12), 'email' => 'layout-partner-owner@example.test']);
    $partnerMember = User::factory()->create(['name' => 'Partner '.str_repeat('Member', 12), 'email' => 'layout-partner-member@example.test']);
    $partnerOne = Workspace::query()->create(['name' => str_repeat('SyntheticPartnerWorkspaceWithoutBreaks', 3), 'slug' => 'layout-partner-one', 'timezone' => 'America/Los_Angeles']);
    $partnerTwo = Workspace::query()->create(['name' => str_repeat('SyntheticSecondPartnerWithoutBreaks', 3), 'slug' => 'layout-partner-two', 'timezone' => 'America/Los_Angeles']);
    $partnerOne->memberships()->create(['user_id' => $partnerOwner->id, 'role' => 'owner']);
    $partnerOne->memberships()->create(['user_id' => $partnerMember->id, 'role' => 'member']);
    $partnerTwo->memberships()->create(['user_id' => $partnerOwner->id, 'role' => 'admin']);
    foreach ([$partnerOne, $partnerTwo] as $partner) {
        $invitation = $participations->invite($owner, $workspace, $project->public_id, (string) $partnerOwner->email);
        $participations->accept($partnerOwner, $invitation->public_id, $partner);
    }
    $partnerCompany = ClientCompany::query()->create(['workspace_id' => $partnerOne->id, 'name' => 'SECRET-PARTNER-CLIENT', 'slug' => 'layout-partner-client']);
    $partnerLocal = ClientProject::query()->create(['workspace_id' => $partnerOne->id, 'client_company_id' => $partnerCompany->id, 'name' => 'Our own '.str_repeat('LocalProject', 8)]);
    ClientProject::query()->create(['workspace_id' => $partnerOne->id, 'client_company_id' => $partnerCompany->id, 'name' => 'Another own project']);
    $participations->linkLocalProject($partnerOwner, $partnerOne, $project->public_id, $partnerLocal->public_id);
    $inviteeUser = User::factory()->create(['name' => 'Synthetic Invitee', 'email' => 'layout-invitee@example.test']);
    $inviteeWorkspace = Workspace::query()->create(['name' => str_repeat('SyntheticInviteeWorkspaceWithoutBreaks', 3), 'slug' => 'layout-invitee-one', 'timezone' => 'America/Los_Angeles']);
    $inviteeWorkspace->memberships()->create(['user_id' => $inviteeUser->id, 'role' => 'owner']);
    $inviteeMember = Workspace::query()->create(['name' => 'Invitee member only', 'slug' => 'layout-invitee-two', 'timezone' => 'America/Los_Angeles']);
    $inviteeMember->memberships()->create(['user_id' => $inviteeUser->id, 'role' => 'member']);
    $sharedInvitation = $participations->invite($owner, $workspace, $project->public_id, (string) $inviteeUser->email);
    $participations->invite($owner, $workspace, $project->public_id, 'layout-open.'.str_repeat('unbroken', 8).'@example.test');
    $sharedTasks = [];
    foreach (range(1, 5) as $index) {
        $task = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'description' => 'Shared details '.str_repeat('Unbroken', 12),
            'title' => 'Shared task '.str_repeat('UnbrokenTaskTitle', 6).$index, 'status' => $index % 2 === 0 ? 'open' : 'in_progress', 'due_on' => '2026-12-0'.$index, 'is_visible_to_client' => true, 'milestone_price_amount' => 987654]);
        $sharedTasks[] = $task;
        foreach ([[$partnerOwner, $partnerOne], [$partnerMember, $partnerOne], [$owner, null]] as [$person, $assigneeWorkspace]) {
            $assignment = new ClientTaskAssignment;
            $assignment->forceFill(['workspace_id' => $workspace->id, 'client_task_id' => $task->id, 'user_id' => $person->id, 'assignee_workspace_id' => $assigneeWorkspace?->id])->save();
        }
    }
    ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'SECRET-HOST-INTERNAL-TASK', 'status' => 'open', 'is_visible_to_client' => false]);
    foreach ([['Host '.str_repeat('SharedUnbrokenComment', 8), true, null], ['SECRET-HOST-INTERNAL-COMMENT', false, null], ['Partner '.str_repeat('ReplyWithoutBreaks', 10), true, $partnerOne]] as [$body, $shared, $authorWorkspace]) {
        $comment = new ClientComment;
        $comment->forceFill([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'client_task_id' => $sharedTasks[0]->id,
            'body' => $body, 'author_user_id' => $authorWorkspace === null ? $owner->id : $partnerOwner->id, 'author_name' => $authorWorkspace === null ? $owner->name : $partnerOwner->name,
            'is_visible_to_client' => false, 'shared_with_participants' => $shared, 'author_workspace_id' => $authorWorkspace?->id,
        ])->save();
    }
    $sharedPages = [
        'shared_index' => route('shared-projects.index', $workspace, absolute: false),
        'shared_host' => route('shared-projects.show', [$workspace, $project->public_id], absolute: false),
        'shared_participant' => route('shared-projects.show', [$partnerOne, $project->public_id], absolute: false),
        'shared_invitation' => route('shared-project-invitations.show', $sharedInvitation->public_id, absolute: false),
        'shared_participant_user_id' => $partnerOwner->id,
        'shared_invitee_user_id' => $inviteeUser->id,
    ];
}

// Reviewer grants (--reviewers): the reviewer reads one "To approve" holding a
// provider they are the billing contact of and a provider that gave them review
// access, with names that will not break, a priced and invoiced hour and a
// receipt-bearing expense whose price, invoice number and note must never reach
// the reviewer's page. The owner reads the grants page with a contact grant, a
// workspace grant and a grant already taken back.
$reviewerPages = [];
if (getenv('SVC_LAYOUT_REVIEWERS') === '1') {
    $reviewDay = app(WorkspaceClock::class)->today($workspace)->subMonthNoOverflow()->startOfMonth();
    $reviewer = User::factory()->create(['name' => 'Reviewer '.str_repeat('Person', 10), 'email' => 'layout-reviewer@example.test']);
    $project->forceFill(['is_visible_to_client' => true])->save();
    $company->forceFill(['billing_email' => 'layout-reviewer@example.test'])->save();
    ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $reviewer->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);
    foreach (range(0, 2) as $index) {
        ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'user_id' => $owner->id,
            'worked_on' => $reviewDay->addDays($index * 6)->toDateString(), 'minutes' => 95 + $index, 'description' => 'SECRET-INTERNAL-NOTE',
            'client_visible_description' => 'Portal provider work '.str_repeat('WithoutBreaks', 8).' '.$index,
            'is_visible_to_client' => true, 'is_billable' => true, 'status' => 'approved', 'billing_rate_amount' => 987654, 'currency' => 'USD',
        ]);
    }

    $providerB = Workspace::query()->create(['name' => str_repeat('SyntheticOtherEngineeringProvider', 3), 'slug' => 'layout-provider-b', 'timezone' => 'America/Los_Angeles']);
    $providerBOwner = User::factory()->create(['name' => 'Provider B owner', 'email' => 'layout-provider-b-owner@example.test']);
    $providerBWorker = User::factory()->create(['name' => 'Provider B worker', 'email' => 'layout-provider-b-worker@example.test']);
    $providerB->memberships()->create(['user_id' => $providerBOwner->id, 'role' => 'owner']);
    $providerB->memberships()->create(['user_id' => $providerBWorker->id, 'role' => 'member']);
    $companyB = ClientCompany::query()->create(['workspace_id' => $providerB->id, 'name' => str_repeat('SyntheticVeterinaryClientName', 4), 'slug' => 'layout-vet-b']);
    $projectB = ClientProject::query()->create(['workspace_id' => $providerB->id, 'client_company_id' => $companyB->id, 'name' => str_repeat('SyntheticProviderProject', 5), 'is_visible_to_client' => true]);
    $billedB = null;
    foreach (range(0, 3) as $index) {
        $entryB = ClientTimeEntry::query()->create([
            'workspace_id' => $providerB->id, 'client_company_id' => $companyB->id, 'client_project_id' => $projectB->id, 'user_id' => $providerBWorker->id,
            'worked_on' => $reviewDay->addDays($index * 5)->toDateString(), 'minutes' => 130 + $index, 'description' => 'SECRET-INTERNAL-NOTE',
            'client_visible_description' => 'Granted provider work '.str_repeat('Unbroken', 12).' '.$index,
            'is_visible_to_client' => true, 'is_billable' => true, 'status' => 'approved', 'billing_rate_amount' => 987654, 'currency' => 'USD',
        ]);
        $billedB ??= $entryB;
    }
    ClientExpense::query()->create([
        'workspace_id' => $providerB->id, 'client_company_id' => $companyB->id, 'client_project_id' => $projectB->id, 'created_by_user_id' => $providerBWorker->id,
        'spent_on' => $reviewDay->addDays(3)->toDateString(), 'amount' => 123456789, 'currency' => 'USD', 'status' => 'approved',
        'description' => 'Granted expense '.str_repeat('UnbrokenExpenseDescription', 5),
    ]);
    $secretInvoice = ClientInvoice::query()->create([
        'workspace_id' => $providerB->id, 'client_company_id' => $companyB->id, 'invoice_number' => 'SECRET-INVOICE-NUMBER', 'status' => 'issued', 'currency' => 'USD',
        'issue_date' => $reviewDay->toDateString(), 'due_date' => $reviewDay->addDays(30)->toDateString(), 'subtotal_amount' => 31415926, 'tax_amount' => 0,
        'total_amount' => 31415926, 'paid_amount' => 0, 'balance_amount' => 31415926, 'is_visible_to_client' => true,
    ]);
    $secretLine = ClientInvoiceLine::query()->create(['workspace_id' => $providerB->id, 'client_invoice_id' => $secretInvoice->id, 'type' => 'service', 'description' => 'SECRET-LINE', 'quantity' => 1, 'unit_amount' => 31415926, 'tax_amount' => 0, 'total_amount' => 31415926, 'sort_order' => 0]);
    $secretLine->timeEntries()->attach($billedB->id, ['workspace_id' => $providerB->id]);
    $billedB->forceFill(['status' => 'invoiced'])->save();

    $reviewerGrants = app(ReviewerGrants::class);
    $contactB = ClientContact::query()->create(['workspace_id' => $providerB->id, 'client_company_id' => $companyB->id, 'first_name' => 'Reviewer', 'last_name' => 'Person', 'email' => 'layout-reviewer@example.test']);
    $reviewerGrants->grant($providerBOwner, $providerB, $companyB->public_id, null, GranteeKind::Contact, $contactB->public_id);
    // A provider with a grant and nothing waiting.
    $providerC = Workspace::query()->create(['name' => 'Provider with nothing waiting', 'slug' => 'layout-provider-c', 'timezone' => 'America/Los_Angeles']);
    $providerCOwner = User::factory()->create(['name' => 'Provider C owner', 'email' => 'layout-provider-c-owner@example.test']);
    $providerC->memberships()->create(['user_id' => $providerCOwner->id, 'role' => 'owner']);
    $companyC = ClientCompany::query()->create(['workspace_id' => $providerC->id, 'name' => 'Quiet client', 'slug' => 'layout-vet-c']);
    $contactC = ClientContact::query()->create(['workspace_id' => $providerC->id, 'client_company_id' => $companyC->id, 'first_name' => 'Reviewer', 'last_name' => 'Person', 'email' => 'layout-reviewer@example.test']);
    $reviewerGrants->grant($providerCOwner, $providerC, $companyC->public_id, null, GranteeKind::Contact, $contactC->public_id);

    // The owner's own page: a contact grant, a workspace grant and one taken back.
    $granteeContact = ClientContact::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'first_name' => str_repeat('Unbroken', 6), 'last_name' => str_repeat('Contact', 6), 'email' => 'layout-grantee.'.str_repeat('unbroken', 8).'@example.test']);
    $reviewerGrants->grant($owner, $workspace, $company->public_id, null, GranteeKind::Contact, $granteeContact->public_id);
    $oldContact = ClientContact::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'first_name' => 'Taken', 'last_name' => 'Back', 'email' => 'layout-taken-back@example.test']);
    $oldGrant = $reviewerGrants->grant($owner, $workspace, $company->public_id, $project->public_id, GranteeKind::Contact, $oldContact->public_id);
    $reviewerGrants->revoke($owner, $workspace, $oldGrant->public_id);
    $reviewerParticipations = app(SharedProjectParticipations::class);
    $partnerWorkspace = Workspace::query()->create(['name' => str_repeat('SyntheticSharingPartnerWorkspace', 3), 'slug' => 'layout-sharing-partner', 'timezone' => 'America/Los_Angeles']);
    $partnerOwner = User::factory()->create(['name' => 'Sharing partner', 'email' => 'layout-sharing-partner@example.test']);
    $partnerWorkspace->memberships()->create(['user_id' => $partnerOwner->id, 'role' => 'owner']);
    $partnerInvitation = $reviewerParticipations->invite($owner, $workspace, $project->public_id, (string) $partnerOwner->email);
    $reviewerParticipations->accept($partnerOwner, $partnerInvitation->public_id, $partnerWorkspace);
    $reviewerGrants->grant($owner, $workspace, $company->public_id, $project->public_id, GranteeKind::Workspace, $partnerWorkspace->public_id);

    $reviewerPages = [
        'reviewer_queue' => route('approvals.index', absolute: false),
        'reviewer_user_id' => $reviewer->id,
        'reviewer_grants' => route('workspaces.reviewers', $workspace, absolute: false),
    ];
}

// The light partner workspace (--light-workspace): a partner who accepted the host's
// invitation with no workspace of their own works in a light one. Their hours are
// drafts, submitted, approved by a reviewer and disputed, all with names that will not
// break; the approved timesheet carries two reviewers' approvals. The invitation page
// is read as a person who has no workspace, so it offers to make one.
$lightPages = [];
if (getenv('SVC_LAYOUT_LIGHT') === '1') {
    $lightMonth = app(WorkspaceClock::class)->today($workspace)->startOfMonth();
    $lightPartner = User::factory()->create(['name' => 'Partner '.str_repeat('Contractor', 8), 'email' => 'layout-light-partner@example.test']);
    $lightReviewer = User::factory()->create(['name' => 'Reviewer '.str_repeat('Approver', 8), 'email' => 'layout-light-reviewer@example.test']);
    $light = Workspace::query()->create(['name' => str_repeat('SyntheticLightPartnerWorkspace', 3), 'slug' => 'layout-light-partner', 'timezone' => 'America/Los_Angeles']);
    $light->forceFill(['edition' => 'light'])->save();
    $light->memberships()->create(['user_id' => $lightPartner->id, 'role' => 'owner']);
    $lightCompany = ClientCompany::query()->create(['workspace_id' => $light->id, 'name' => str_repeat('SyntheticLightClientName', 4), 'slug' => 'layout-light-client']);
    $lightProject = ClientProject::query()->create(['workspace_id' => $light->id, 'client_company_id' => $lightCompany->id, 'name' => str_repeat('SyntheticLightProject', 5), 'is_visible_to_client' => false]);
    $submitted = ['submitted_at' => $lightMonth->addDays(10)->toDateTimeString(), 'submitted_by_user_id' => $lightPartner->id];
    $approved = $submitted + ['client_approved_at' => $lightMonth->addDays(11)->toDateTimeString(), 'client_approved_by_user_id' => $lightReviewer->id, 'client_approval_source' => 'reviewer'];
    $disputed = $submitted + ['client_disputed_at' => $lightMonth->addDays(11)->toDateTimeString(), 'client_disputed_by_user_id' => $lightReviewer->id, 'client_dispute_note' => 'Which ticket? '.str_repeat('UnbrokenDisputeNote', 6)];
    $states = [['draft', []], ['draft', []], ['submitted', $submitted], ['approved', $approved], ['approved', $approved], ['disputed', $disputed]];
    foreach ($states as $index => [$state, $extra]) {
        ClientTimeEntry::query()->create($extra + [
            'workspace_id' => $light->id, 'client_company_id' => $lightCompany->id, 'client_project_id' => $lightProject->id, 'user_id' => $lightPartner->id,
            'worked_on' => $lightMonth->addDays($index)->toDateString(), 'minutes' => 75 + $index, 'description' => 'Private note '.$state,
            'client_visible_description' => ucfirst($state).' work '.str_repeat('UnbrokenClientLine', 8).' '.$index,
            'is_visible_to_client' => true, 'is_billable' => true, 'status' => 'draft', 'currency' => 'USD',
        ]);
    }
    $lightInvitee = User::factory()->create(['name' => 'New partner', 'email' => 'layout-light-invitee@example.test']);
    $lightInvitation = app(SharedProjectParticipations::class)->invite($owner, $workspace, $project->public_id, (string) $lightInvitee->email);
    $lightPeriod = $lightMonth->format('Y-m');
    $lightPages = [
        'light_my_work' => route('workspaces.my-work', $light, absolute: false),
        'light_submissions' => route('workspaces.submissions', [$light, 'period' => $lightPeriod], absolute: false),
        'light_timesheet' => route('workspaces.timesheet', [$light, 'period' => $lightPeriod], absolute: false),
        'light_invitation' => route('shared-project-invitations.show', $lightInvitation->public_id, absolute: false),
        'light_user_id' => $lightPartner->id,
        'light_invitee_user_id' => $lightInvitee->id,
    ];
}

// The command palette (--palette). Every kind it can find, under one shared word
// and names that will not break: an expense, an agreement, a proposal, a file, time
// (one entry with a client-safe description for the portal), a contact and a second
// portal user, plus the owner's recent items. Read as four people - an owner (with
// and without a client in context), a member scoped to the one project, and the
// client contact - each on a page of their own, since the harness picks the viewer
// by path. The palette's own requests are answered as whoever the page belongs to.
$palettePages = [];
if (getenv('SVC_LAYOUT_PALETTE') === '1') {
    $unbroken = str_repeat('SyntheticPaletteProbe', 5);
    $project->forceFill(['is_visible_to_client' => true])->save();
    $invoice->forceFill(['is_visible_to_client' => true])->save();

    $paletteMember = User::factory()->create(['name' => 'Synthetic Palette Member', 'email' => 'layout-palette-member@example.test']);
    $workspace->memberships()->create(['user_id' => $paletteMember->id, 'role' => 'member']);
    ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $paletteMember->id, 'role' => 'contributor']);

    ClientExpense::query()->create([
        'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'created_by_user_id' => $owner->id,
        'spent_on' => $date, 'amount' => 123456, 'currency' => 'USD', 'status' => 'draft', 'description' => 'Probe expense '.$unbroken,
    ]);
    ClientAgreement::query()->create([
        'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'title' => 'Probe agreement '.$unbroken,
        'status' => 'active', 'currency' => 'USD', 'starts_on' => $date, 'billing_cadence' => 'monthly', 'is_visible_to_client' => true,
    ]);
    ClientProposal::query()->create([
        'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'title' => 'Probe proposal '.$unbroken,
        'status' => 'sent', 'currency' => 'USD', 'is_visible_to_client' => true,
    ]);
    $paletteFile = ClientAttachment::query()->create([
        'workspace_id' => $workspace->id, 'record_type' => 'project', 'record_public_id' => $project->public_id, 'object_key' => 'synthetic/palette-probe',
        'original_filename' => 'Probe-'.$unbroken.'.pdf', 'media_type' => 'application/pdf', 'bytes' => 1, 'sha256' => str_repeat('b', 64),
        'uploader_id' => $owner->id, 'lifecycle_state' => ClientAttachment::STATE_AVAILABLE,
    ]);
    DB::table('client_attachments')->where('id', $paletteFile->id)->update(['is_visible_to_client' => true]);
    ClientTimeEntry::query()->create([
        'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'user_id' => $paletteMember->id,
        'worked_on' => $date, 'minutes' => 90, 'description' => 'Probe internal note '.$unbroken, 'client_visible_description' => 'Probe client summary '.$unbroken,
        'is_visible_to_client' => true, 'is_billable' => true, 'status' => 'approved',
    ]);
    ClientContact::query()->create([
        'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'first_name' => 'Probe'.$unbroken, 'last_name' => 'Contact',
    ]);
    $probePortalUser = User::factory()->create([
        'name' => 'Probe portal user '.$unbroken, 'email' => 'probe-portal-user@client.example.test',
        'oauth_provider' => 'synthetic', 'oauth_subject' => 'synthetic-palette-portal-user',
    ]);
    app(WorkspaceAccess::class)->grantPortal(AccessMutationContext::system(), $company, $probePortalUser, PortalAccessScope::Company);
    foreach ([['client', $company->public_id], ['project', $project->public_id], ['invoice', $invoice->public_id]] as $index => [$kind, $publicId]) {
        PaletteRecent::query()->create([
            'workspace_id' => $workspace->id, 'user_id' => $owner->id, 'kind' => $kind, 'record_public_id' => $publicId,
            'opened_at' => now()->subMinutes($index + 1),
        ]);
    }

    $palettePages = [
        'palette_owner' => route('workspaces.dashboard', $workspace, absolute: false),
        'palette_owner_client' => route('clients.show', [$workspace, $company], absolute: false),
        'palette_member' => route('clients.time', [$workspace, $company], absolute: false),
        'palette_portal' => route('portal.show', $company, absolute: false),
        'palette_viewers' => [
            route('workspaces.dashboard', $workspace, absolute: false) => $owner->id,
            route('clients.show', [$workspace, $company], absolute: false) => $owner->id,
            route('clients.time', [$workspace, $company], absolute: false) => $paletteMember->id,
            route('portal.show', $company, absolute: false) => $squarePortalContact->id,
        ],
    ];
}

// Service packages (--packages): a list, the editor, and the two public pages,
// all with deliberately oversized names, summaries, link names, descriptions
// and questions. One package is live and listed, one live and unlisted, one a
// draft that could be published, one a draft the server refuses to publish
// (nothing priced, auto-issue with hours), and one retired. The flag is on
// through the environment the runner sets, so the routes exist.
$packagePages = [];
if (getenv('SVC_LAYOUT_PACKAGES') === '1') {
    config(['svc.service_packages.enabled' => true]);
    $packages = app(ServicePackageWorkflow::class);
    $word = str_repeat('Unbroken', 14);
    $oversized = [
        'name' => $word,
        'summary' => str_repeat('Unbroken', 30),
        'body_markdown' => '- '.str_repeat('LongBulletWithoutSpaces', 8)."\n- Managed hosting, SSL and daily backups\n\nhttps://example.test/".str_repeat('very-long-path-segment-', 6)."\n\n".str_repeat('A paragraph that wraps normally. ', 6),
        'components' => [
            ['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'monthly', 'amount' => 4500, 'quantity' => 1, 'is_taxable' => false],
            ['kind' => 'recurring', 'description' => str_repeat('Unbroken', 50), 'cadence' => 'monthly', 'amount' => 12900, 'quantity' => 2, 'is_taxable' => true],
            ['kind' => 'setup_fee', 'description' => 'Setup and migration', 'cadence' => 'one_time', 'amount' => 25000, 'quantity' => 1, 'is_taxable' => false],
        ],
        'retainer_amount' => 60000,
        'retainer_minutes' => 300,
        'rollover_months' => 2,
        'hourly_rate_amount' => 14000,
        'minimum_term_months' => 6,
        'notice_days' => 30,
        'agreement_text' => str_repeat('This agreement covers the services described above. ', 8),
        'intake_questions' => [
            ['label' => str_repeat('Unbroken', 20), 'kind' => 'short_text', 'required' => true],
            ['label' => 'Where is the site hosted now?', 'kind' => 'short_text', 'required' => false],
            ['label' => 'What should the first hours go toward?', 'kind' => 'paragraph', 'required' => false],
        ],
    ];
    $live = $packages->create($owner, $workspace, [...$oversized, 'link_name' => str_repeat('longlinkname', 5), 'is_listed' => true]);
    $packages->publish($owner, $workspace, $live);
    $second = $packages->create($owner, $workspace, [
        'name' => 'Second synthetic service', 'summary' => 'A plain second listed service.', 'link_name' => 'second-service', 'is_listed' => true,
        'components' => [['kind' => 'recurring', 'description' => 'Care plan', 'cadence' => 'monthly', 'amount' => 9500, 'quantity' => 1]],
    ]);
    $packages->publish($owner, $workspace, $second);
    $unlisted = $packages->create($owner, $workspace, [
        'name' => 'Site health audit', 'summary' => 'One-time review with a written report.', 'link_name' => 'site-audit', 'is_listed' => false,
        'billing_cadence' => 'one_time', 'minimum_term_months' => null,
        'components' => [['kind' => 'recurring', 'description' => 'Audit and report', 'cadence' => 'one_time', 'amount' => 90000, 'quantity' => 1]],
    ]);
    $packages->publish($owner, $workspace, $unlisted);
    $ready = $packages->create($owner, $workspace, [...$oversized, 'name' => 'Ready '.$word, 'link_name' => 'ready-'.str_repeat('draftname', 5), 'billing_cadence' => 'quarterly']);
    $blocked = $packages->create($owner, $workspace, [
        'name' => 'Not ready to publish', 'link_name' => 'not-ready', 'invoice_issuing' => 'automatic', 'hourly_rate_amount' => 12000,
    ]);
    $retired = $packages->create($owner, $workspace, [
        'name' => 'Legacy hosting', 'link_name' => 'legacy-hosting', 'is_listed' => true,
        'components' => [['kind' => 'recurring', 'description' => 'Legacy plan', 'cadence' => 'monthly', 'amount' => 3500, 'quantity' => 1]],
    ]);
    $packages->publish($owner, $workspace, $retired);
    $packages->retire($owner, $workspace, $retired->refresh());
    $workspace->refresh();

    // Sign-up requests: every field the visitor types is oversized; one open request comes
    // from an address a client already holds (so it is suggested), one is declined with a
    // reason, one declined and then erased, and one is plain.
    $requests = app(PackageRequestWorkflow::class);
    $submit = static fn (array $input, ?string $fingerprint = null) => $requests->submit($workspace, $live, [
        'company_name' => $word, 'contact_name' => str_repeat('Contact', 12), 'phone' => str_repeat('5', 40),
        ...$input,
    ], null, $fingerprint);
    $questionKeys = array_map(static fn ($question): string => $question->key, PackageTerms::fromVersion($live->refresh()->publishedVersion)->intakeQuestions);
    $answers = static fn (): array => ['answers' => array_combine($questionKeys, [str_repeat('Unbroken', 25), str_repeat('Unbroken', 12), str_repeat('Unbroken', 100).' <b>not bold</b>'])];
    $submit(['email' => 'layout-'.str_repeat('unbroken', 6).'@example.test', ...$answers()]);
    $submit(['company_name' => 'Synthetic Client Company', 'email' => 'contact@example.test', ...$answers()]);
    $submit(['company_name' => 'Plain Bakery', 'contact_name' => 'Morgan Ortiz', 'phone' => '', 'email' => 'layout-plain@example.test', 'answers' => [$questionKeys[0] => 'plain.example.test']]);
    $declined = $submit(['email' => 'layout-declined@example.test', ...$answers()]);
    $requests->decline($owner, $workspace, $declined, 'We do not serve that area yet. '.str_repeat('Unbroken', 20));
    $erased = $submit(['company_name' => 'Quick Loans', 'email' => 'layout-erased@example.test', 'answers' => [$questionKeys[0] => 'gone.example.test']]);
    $requests->decline($owner, $workspace, $erased->refresh());
    $requests->erase($owner, $workspace, $erased->refresh());

    // Slice 3a: the client's Services tab and review-and-sign page, read as the company
    // contact, and the operator's agreement page for a signed service waiting for review.
    // One offer was made and then a new version published (EDGE-7), one signed service waits
    // for review, one signed and started on its own; every name is oversized.
    $enrollments = app(PackageEnrollmentWorkflow::class);
    $signer = ['signer_name' => str_repeat('Signer', 20), 'signer_title' => str_repeat('Title', 20), 'accepted_terms' => true];
    $portalPackage = $packages->create($owner, $workspace, [
        ...$oversized, 'name' => 'Offered '.$word, 'link_name' => 'offered-care', 'is_listed' => false,
        'billing_cadence' => 'monthly', 'activation_mode' => 'review',
    ]);
    $packages->publish($owner, $workspace, $portalPackage);
    $offered = $enrollments->addFromPortal($squarePortalContact, $company, $portalPackage->refresh());
    $packages->update($owner, $workspace, $portalPackage->refresh(), [
        'notice_days' => 45, 'rollover_months' => 3,
        'components' => [
            ['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'monthly', 'amount' => 5500, 'quantity' => 1, 'is_taxable' => false],
            ['kind' => 'setup_fee', 'description' => 'Setup and migration', 'cadence' => 'one_time', 'amount' => 25000, 'quantity' => 1, 'is_taxable' => false],
        ],
    ]);
    $packages->publish($owner, $workspace, $portalPackage->refresh());
    $reviewPackage = $packages->create($owner, $workspace, [
        'name' => 'Review '.$word, 'link_name' => 'review-care', 'is_listed' => false, 'activation_mode' => 'review',
        'agreement_text' => str_repeat('This agreement covers the services described above. ', 30),
        'components' => [['kind' => 'recurring', 'description' => 'Care plan', 'cadence' => 'monthly', 'amount' => 64500, 'quantity' => 1]],
    ]);
    $packages->publish($owner, $workspace, $reviewPackage);
    $waiting = $enrollments->addFromPortal($squarePortalContact, $company, $reviewPackage->refresh());
    $waiting = $enrollments->accept($squarePortalContact, $company, $waiting, $signer);
    $autoPackage = $packages->create($owner, $workspace, [
        'name' => 'Automatic '.$word, 'link_name' => 'automatic-care', 'is_listed' => false, 'activation_mode' => 'automatic',
        'components' => [['kind' => 'recurring', 'description' => 'Hosting', 'cadence' => 'monthly', 'amount' => 4500, 'quantity' => 1]],
    ]);
    $packages->publish($owner, $workspace, $autoPackage);
    $started = $enrollments->addFromPortal($squarePortalContact, $company, $autoPackage->refresh());
    $enrollments->accept($squarePortalContact, $company, $started, $signer);

    // Slice 3b: the sign-up checkout card on the review page. One package charges a setup fee and
    // the first period and requires autopay; one offer is still an estimate, one accepted service
    // waits for payment (the invoice's own lines). The checkout asks for payment, so the
    // workspace needs a Stripe connection, written locally: no seed here asks Stripe. A version
    // that takes money is published by writing the publish the workflow would, because the
    // workflow asks Stripe which currencies the account can charge before it publishes. A
    // dedicated company keeps the first period clear of every other invoice the seed writes.
    if (app(PaymentConnectionResolver::class)->stripe($workspace) === null) {
        app(WorkspacePaymentConnections::class)->connect($workspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, $owner, [
            'external_account_id' => 'acct_synthetic_packages', 'display_name' => 'Synthetic', 'environment' => 'test',
            'secret' => 'sk_test_'.str_repeat('synthetic', 4).'0003', 'webhook_secret' => 'whsec_synthetic',
            'publishable_key' => 'pk_test_layout_synthetic',
        ]);
    }
    $checkoutCompany = ClientCompany::query()->create([
        'workspace_id' => $workspace->id, 'name' => str_repeat('SyntheticCheckoutClient', 5), 'slug' => 'layout-checkout',
        'billing_email' => 'billing@checkout.example.test', 'currency' => 'USD',
    ]);
    $checkoutContact = User::factory()->create([
        'name' => str_repeat('SyntheticCheckoutContact', 5), 'email' => 'layout-checkout@client.example.test',
        'oauth_provider' => 'synthetic', 'oauth_subject' => 'synthetic-checkout-contact',
    ]);
    app(WorkspaceAccess::class)->grantPortal(AccessMutationContext::system(), $checkoutCompany, $checkoutContact, PortalAccessScope::Company, []);
    // Two packages: a company holds one open offer per package, so the estimate and the
    // accepted sign-up need one each.
    $checkoutPackageInput = static fn (string $name, string $link): array => [
        ...$oversized, 'name' => $name, 'link_name' => $link, 'is_listed' => false,
        'billing_cadence' => 'monthly', 'activation_mode' => 'automatic', 'invoice_issuing' => 'automatic',
        'charge_setup_fee_at_signup' => true, 'charge_first_period' => true, 'require_autopay' => true,
        'autopay_limit_amount' => 150_000, 'pay_before_activation' => true,
        'components' => [
            ['kind' => 'recurring', 'description' => 'Managed hosting '.str_repeat('RecurringLineWithoutBreaks', 4), 'cadence' => 'monthly', 'amount' => 54_500, 'quantity' => 1, 'is_taxable' => false],
            ['kind' => 'setup_fee', 'description' => 'Setup '.str_repeat('SetupFeeLineWithoutBreaks', 4), 'cadence' => 'one_time', 'amount' => 25_000, 'quantity' => 1, 'is_taxable' => false],
            ['kind' => 'setup_fee', 'description' => str_repeat('SecondSetupFeeWithoutBreaks', 6), 'cadence' => 'one_time', 'amount' => 12_500, 'quantity' => 1, 'is_taxable' => false],
        ],
    ];
    $publishCheckoutPackage = static function (ServicePackage $package) use ($owner): ServicePackage {
        $version = $package->refresh()->versions()->where('draft_marker', 1)->firstOrFail();
        $version->forceFill(['draft_marker' => null, 'published_at' => now(), 'published_by_user_id' => $owner->id])->save();
        $package->forceFill(['status' => 'published', 'published_version_id' => $version->id])->save();

        return $package->refresh();
    };
    $estimatePackage = $publishCheckoutPackage($packages->create($owner, $workspace, $checkoutPackageInput('Checkout estimate '.$word, 'estimate-'.str_repeat('checkoutname', 4))));
    $checkoutPackage = $publishCheckoutPackage($packages->create($owner, $workspace, $checkoutPackageInput('Checkout '.$word, 'checkout-'.str_repeat('checkoutname', 4))));
    $checkoutOffered = $enrollments->addFromPortal($checkoutContact, $checkoutCompany, $estimatePackage);
    $checkoutAccepted = $enrollments->addFromPortal($checkoutContact, $checkoutCompany, $checkoutPackage);
    $checkoutAccepted = $enrollments->accept($checkoutContact, $checkoutCompany, $checkoutAccepted, $signer);
    $workspace->refresh();
    $waitingAgreement = $waiting->refresh()->agreement;

    // Slice 5: cancellation. Three started services of the same company and contact, each on
    // its own project: one self-serve with the cancel form, one with a request pending (the
    // portal's waiting state and the operator's decision), and one whose end the client
    // scheduled (with the undo). Names and the reason are oversized; dates are the server's.
    $cancellations = app(AgreementCancellations::class);
    $cancelPackage = static function (string $name, string $link, string $mode) use ($packages, $owner, $workspace, $word): ServicePackage {
        $package = $packages->create($owner, $workspace, [
            'name' => $name.' '.$word, 'link_name' => $link, 'is_listed' => false, 'activation_mode' => 'automatic',
            'billing_cadence' => 'monthly', 'cancellation_mode' => $mode, 'notice_days' => 30, 'minimum_term_months' => null,
            'components' => [['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'monthly', 'amount' => 4500, 'quantity' => 1]],
        ]);

        return $packages->publish($owner, $workspace, $package)->refresh();
    };
    $startService = static function (ServicePackage $package) use ($enrollments, $squarePortalContact, $company, $signer) {
        return $enrollments->accept($squarePortalContact, $company, $enrollments->addFromPortal($squarePortalContact, $company, $package), $signer)->refresh();
    };
    $cancelForm = $startService($cancelPackage('Cancel', 'cancel-self-serve', 'self_serve'));
    $cancelPending = $startService($cancelPackage('Requested', 'cancel-request', 'request'));
    $cancelScheduled = $startService($cancelPackage('Ending', 'cancel-scheduled', 'self_serve'));
    $pendingAgreement = $cancelPending->agreement;
    $offeredEnds = static fn ($agreement): array => CancellationTerms::of($agreement)->offer(now($workspace->timezone))?->offeredDateStrings() ?? [];
    $cancellations->cancelByContact($squarePortalContact, $company, $pendingAgreement, $offeredEnds($pendingAgreement)[1], str_repeat('UnbrokenReason', 30).' and a reason that wraps normally across the line.');
    $cancellations->cancelByContact($squarePortalContact, $company, $cancelScheduled->agreement, $offeredEnds($cancelScheduled->agreement)[0], null);

    $edit = static fn ($package): string => route('packages.edit', ['workspace' => $workspace, 'servicePackage' => $package->public_id], absolute: false);
    $packagePages = [
        'packages_list' => route('packages.index', $workspace, absolute: false),
        'packages_requests' => route('packages.requests.index', $workspace, absolute: false),
        'packages_new' => route('packages.create', $workspace, absolute: false),
        'packages_editor' => $edit($ready),
        'packages_editor_blocked' => $edit($blocked),
        'packages_editor_live' => $edit($live),
        'packages_catalog' => route('packages.public.catalog', $workspace->package_catalog_token, absolute: false),
        'packages_public' => route('packages.public.show', [$workspace->package_catalog_token, $live->link_name], absolute: false),
        'packages_public_short' => route('packages.public.show', [$workspace->package_catalog_token, $unlisted->link_name], absolute: false),
        'packages_portal_services' => route('portal.services', $company, absolute: false),
        'packages_portal_review' => route('portal.services.show', [$company, $offered->public_id], absolute: false),
        'packages_operator_agreement' => route('clients.agreement', [$workspace, $company, $waitingAgreement], absolute: false),
        'packages_portal_checkout_estimate' => route('portal.services.show', [$checkoutCompany, $checkoutOffered->public_id], absolute: false),
        'packages_portal_checkout_invoice' => route('portal.services.show', [$checkoutCompany, $checkoutAccepted->public_id], absolute: false),
        'packages_portal_cancel' => route('portal.services.show', [$company, $cancelForm->public_id], absolute: false),
        'packages_portal_cancel_pending' => route('portal.services.show', [$company, $cancelPending->public_id], absolute: false),
        'packages_portal_cancel_scheduled' => route('portal.services.show', [$company, $cancelScheduled->public_id], absolute: false),
        'packages_operator_cancellation' => route('clients.agreement', [$workspace, $company, $pendingAgreement], absolute: false),
        // Read as the company's contact rather than the owner.
        'package_viewers' => [
            route('portal.services.show', [$company, $cancelForm->public_id], absolute: false) => $squarePortalContact->id,
            route('portal.services.show', [$company, $cancelPending->public_id], absolute: false) => $squarePortalContact->id,
            route('portal.services.show', [$company, $cancelScheduled->public_id], absolute: false) => $squarePortalContact->id,
            route('portal.services.show', [$checkoutCompany, $checkoutOffered->public_id], absolute: false) => $checkoutContact->id,
            route('portal.services.show', [$checkoutCompany, $checkoutAccepted->public_id], absolute: false) => $checkoutContact->id,
            route('portal.services', $company, absolute: false) => $squarePortalContact->id,
            route('portal.services.show', [$company, $offered->public_id], absolute: false) => $squarePortalContact->id,
        ],
    ];
}

file_put_contents($runtime.'/fixture.json', json_encode([
    ...$packagePages,
    ...$autopayPages,
    ...$lightPages,
    ...$paymentPages,
    ...$reviewerPages,
    ...$workPortal,
    ...$palettePages,
    ...$sharedPages,
    ...$documentPages,
    'selector' => route('workspaces.index', absolute: false),
    'workspace_create' => route('agent-api.v1.workspaces.store', absolute: false),
    'task_editor' => $planningTask === null ? null : route('tasks.show', [$workspace, $planningTask], absolute: false),
    'task_list' => route('clients.tasks', [$workspace, $company], absolute: false),
    'task_project' => route('clients.project', [$workspace, $company, $project], absolute: false),
    'project_page' => route('clients.project', [$workspace, $company, $project], absolute: false),
    'task_portal' => route('portal.tasks', $company, absolute: false),
    'task_create' => $planningTask === null ? null : route('agent-api.v1.tasks.store', [$workspace, $project->public_id], absolute: false),
    'task_update' => $planningTask === null ? null : route('agent-api.v1.tasks.update', [$workspace, $planningTask->public_id], absolute: false),
    'history_index' => route('history.index', $workspace, absolute: false),
    'history_discussion' => route('history.show', [$workspace, $historyDiscussion], absolute: false),
    'history_invoice' => route('history.show', [$workspace, $historyInvoice], absolute: false),
    'agreement' => $engagementAgreementPath ?? $backlogAgreementPath,
    'buydown_create' => $backlogCreatePath,
    'reminder_invoice' => route('clients.invoice', [$workspace, $company, $reminderInvoice], absolute: false),
    'square_explore' => route('square.explore', $workspace, absolute: false),
    'square_invoice' => route('clients.invoice', [$workspace, $company, $squareInvoice], absolute: false),
    'square_portal' => route('portal.invoice', [$company, $squareInvoice], absolute: false),
    'square_portal_user_id' => $squarePortalContact->id,
    'mcp_setup' => route('mcp.setup', $workspace, absolute: false),
    'draft_invoice' => route('clients.invoice', [$workspace, $company, $draft], absolute: false),
    'draft_time' => route('clients.time', [$workspace, $company, 'draft_invoice' => $draft->public_id], absolute: false),
    'workspace_id' => $workspace->id, 'workspace_public_id' => $workspace->public_id, 'log_project_id' => $logProject->id,
    'project_log' => route('clients.time', [$workspace, $company, 'log_project' => $logProject->public_id], absolute: false),
    'draft_id' => $draft->id, 'draft_public_id' => $draft->public_id, 'correction_id' => $correction->id, 'companion_id' => $companion->id,
    'preserved_lines' => $draft->lines()->whereDoesntHave('timeEntries')->orderBy('id')->get()->map->getAttributes()->all(),
    'draft_writes' => [
        'POST' => [route('agent-api.v1.time-entries.store', $workspace, absolute: false), route('agent-api.v1.invoices.add_time', [$workspace, $draft->public_id], absolute: false), route('agent-api.v1.invoices.issue', [$workspace, $draft->public_id], absolute: false), route('agent-api.v1.payments.store', $workspace, absolute: false),
            '/api/v1/workspaces/'.$workspace->public_id.'/time-entries/'.$correction->public_id.'/unapprove', '/api/v1/workspaces/'.$workspace->public_id.'/time-entries/approve'],
        'PATCH' => ['/api/v1/workspaces/'.$workspace->public_id.'/time-entries/'.$correction->public_id],
    ],
    'receipt_upload' => route('agent-api.v1.expense-receipts.store', [$workspace, $receiptExpense->public_id], absolute: false),
    'receipt_prefix' => '/api/v1/workspaces/'.$workspace->public_id.'/expenses/'.$receiptExpense->public_id.'/receipts/',
    'receipts' => route('svc.expenses.receipts', [$workspace, $company, $receiptExpense->public_id], absolute: false),
    'user_id' => $taskViewer->id, 'date' => $date,
    'expense_schedule_store' => route('agent-api.v1.expense-schedules.store', $workspace, absolute: false),
    'expense_schedule_prefix' => '/api/v1/workspaces/'.$workspace->public_id.'/expense-schedules/',
    'expense_api_store' => route('agent-api.v1.expenses.store', $workspace, absolute: false),
    'expense_api_prefix' => '/api/v1/workspaces/'.$workspace->public_id.'/expenses/',
    'expense_schedules' => route('clients.expense-schedules', [$workspace, $company], absolute: false),
    'invoice' => route('clients.invoice', [$workspace, $company, $invoice], absolute: false),
    'invoice_review' => route('clients.invoice', [$workspace, $company, $reviewInvoice], absolute: false),
    'project_writes' => [
        'POST' => [route('agent-api.v1.projects.store', $workspace, absolute: false)],
        'PATCH' => [route('agent-api.v1.projects.update', [$workspace, $project->public_id], absolute: false)],
        'PUT' => [route('agent-api.v1.projects.members.update', [$workspace, $project->public_id], absolute: false)],
    ],
    'client_profile_update' => route('agent-api.v1.clients.update', [$workspace, $company], absolute: false),
    'contact_restore' => route('agent-api.v1.clients.contacts.restore', [$workspace, $company, $historicalContact], absolute: false),
    'settings' => route('clients.settings', [$workspace, $company], absolute: false),
    'portal_settings' => route('clients.settings', [$workspace, $company], absolute: false).'?invitation_page=2',
    'proposal' => route('clients.proposal', [$workspace, $company, $proposal], absolute: false),
    'proposal_acceptance' => route('portal.proposal', [$company, $proposal], absolute: false),
    'expenses' => route('clients.expenses', [$workspace, $company], absolute: false),
    'time' => route('clients.time', [$workspace, $company], absolute: false),
    'client_agreements' => route('clients.agreements', [$workspace, $company], absolute: false),
    'client_invoices' => route('clients.invoices', [$workspace, $company], absolute: false),
    'dashboard' => route('workspaces.dashboard', $workspace, absolute: false),
    'client_overview' => route('clients.show', [$workspace, $company], absolute: false),
    'workspace_clients' => route('clients.index', $workspace, absolute: false),
    'workspace_invoices' => route('workspaces.invoices', $workspace, absolute: false),
    'workspace_time' => route('workspaces.time', $workspace, absolute: false),
    'workspace_finance' => route('workspaces.finance', $workspace, absolute: false),
], JSON_THROW_ON_ERROR));
// Wayfinder runs Artisan from the repository during the asset build. Give it
// this already-sanitized configuration so it skips the repository's .env too.
file_put_contents($runtime.'/config.php', '<?php return '.var_export(config()->all(), true).';');
echo "Synthetic layout fixtures created.\n";
