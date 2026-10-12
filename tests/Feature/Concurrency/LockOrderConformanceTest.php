<?php

namespace Tests\Feature\Concurrency;

use App\Console\Commands\Billing\RehearseGenerationCommand;
use App\Console\Commands\Billing\ReplayInvoicesCommand;
use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientProposal;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Services\Access\WorkspaceAccess;
use App\Services\AgentApi\AgentInvoiceOperations;
use App\Services\AgentApi\TimeEntryMutationService;
use App\Services\Billing\AllocationService;
use App\Services\Billing\BillingScheduleService;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InterimOverageGenerator;
use App\Services\Billing\InvoiceFromTimeService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\TimeEntrySplitter;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\Engagement\ProposalWorkflow;
use App\Services\Files\AttachmentAction;
use App\Services\Finance\PaymentReconciliationService;
use App\Services\WorkspaceProjectMutationAction;
use App\Services\WorkspaceTaskMutationAction;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\PortalAccessScope;
use App\Support\Access\StaffRole;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\LockMode;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use App\Support\Expenses\NewExpense;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BillingWriterLockSites;
use Tests\TestCase;

/**
 * Every transaction takes its locks in the order `LockResource` declares.
 *
 * Review kept finding locking gaps one at a time, and each fix was correct in
 * isolation and told nobody anything about the next one: a claim released with
 * no lock, a freeze that read outside the lock it depended on, check-then-act
 * guards re-verified at issue time only after a reviewer asked. Fifty
 * `lockForUpdate()` call sites across twenty files, and nothing anywhere said
 * what order they were meant to be taken in - so "is this one right?" was a
 * question only a careful reader of the other forty-nine could answer.
 *
 * This is that answer, made checkable. `Locks::forUpdate()` records what each
 * transaction acquires, in order; `DisallowRawLockForUpdateRule` makes sure
 * there is no other way to take a lock; and this drives the concurrent writers
 * and refuses any sequence that walks backwards through the registry.
 *
 * ## What "in order" means here, exactly
 *
 * A resource may be locked again at any point once this transaction has locked
 * it - generation loops periods inside one transaction and re-enters the same
 * phase per period, which is not a new claim on anything. A resource locked for
 * the *first* time must not rank below one already locked.
 *
 * That is weaker than it sounds and the weakness is worth stating: the registry
 * is granular to the table, so "already locked" means some row of that table,
 * not this row. A transaction that locks invoice A, then a time entry, then
 * invoice B is monotonic here and is not, in fact, ordered. Making it stricter
 * would mean ranking rows, which no static order can do. The doc says so too.
 *
 * ## What this proves, and what it does not
 *
 * Ordering discipline, and only that. The suite runs on one connection, so no
 * two transactions here ever contend; nothing in this file is evidence that a
 * concurrent caller is safe. What it does buy is the thing the one-at-a-time
 * fixes never could: a new lock in the wrong place fails here rather than in
 * production, and the place where today's code still disagrees with itself is
 * written down instead of rediscovered.
 */
final class LockOrderConformanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ordinary inversions are an exact set, currently empty. Cross-period
     * maintenance is exempt only through a successful exclusive first lock;
     * its two command sites and the share-first writer sites are pinned below.
     *
     * @var list<string>
     */
    private const KNOWN_INVERSIONS = [];

    /** Exclusive maintenance is the only transaction-order exemption. */
    private const MAINTENANCE_EXEMPTIONS = [RehearseGenerationCommand::class, ReplayInvoicesCommand::class];

    /** Writers that lock time entries or tasks take the maintenance share lock first. */
    private const BILLING_WRITER_SITES = [
        'App\\Services\\AgentApi\\TimeEntryMutationService::approve',
        'App\\Services\\AgentApi\\TimeEntryMutationService::serialized',
        'App\\Services\\Billing\\AllocationService::group',
        'App\\Services\\Billing\\InterimOverageGenerator::timeFingerprint',
        'App\\Services\\Billing\\InvoiceFromTimeService::prepareLines',
        'App\\Services\\Billing\\InvoiceFromTimeService::regenerateDraftSelection',
        'App\\Services\\Billing\\InvoiceLifecycleService::releaseAllocations',
        'App\\Services\\Billing\\InvoiceLineComposer::addBillableMilestoneTasks',
        'App\\Services\\Files\\AttachmentAction::lockedRecord',
        'App\\Services\\WorkspaceTaskMutationAction::update',
    ];

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::query()->create(['name' => 'Locks', 'slug' => 'locks']);
        $this->workspace->memberships()->create(['user_id' => $this->user->id, 'role' => 'admin']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Locks Client', 'slug' => 'locks-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Locks Project',
        ]);
    }

    protected function tearDown(): void
    {
        LockOrderRecorder::stop();

        parent::tearDown();
    }

    /**
     * The concurrent writers, driven end to end, taking their locks in order.
     *
     * One test rather than one per workflow, because the claim is about the set:
     * an order is only consistent if everything obeys it, and a per-workflow
     * test would pass on each half of a disagreeing pair.
     */
    public function test_every_recorded_transaction_acquires_its_locks_in_registry_order(): void
    {
        LockOrderRecorder::start();

        $this->driveTheConcurrentWriters();

        $sequences = LockOrderRecorder::sequences();

        $inventory = BillingWriterLockSites::all();
        $methods = array_values(array_unique(array_values($inventory)));
        sort($methods);
        $this->assertSame(self::BILLING_WRITER_SITES, $methods, 'A time-entry or task lock site changed: update its share-first proof.');
        $reached = [];
        foreach (LockOrderRecorder::acquisitions() as $locks) {
            foreach ($locks as $lock) {
                if (in_array($lock->resource, [LockResource::ClientTimeEntry, LockResource::ClientTask], true)) {
                    $key = $lock->file.':'.$lock->line;
                    $this->assertArrayHasKey($key, $inventory, 'A recorded writer site is missing from the source inventory.');
                    $reached[$key] = true;
                }
            }
        }
        $this->assertSame([], array_diff_key($inventory, $reached), 'Every writer site must be reached, not merely named.');

        $this->assertGreaterThanOrEqual(
            10,
            count($sequences),
            'The workflows below stopped taking locks, so this test is no longer proving anything about their order.',
        );

        // The access tables are registered for the access boundary alone, so
        // each has to be reached through a real transaction here, or its
        // position in the registry is declared rather than recorded.
        $recorded = array_unique(array_map(static fn (LockResource $resource): string => $resource->name, array_merge(...$sequences)));
        foreach ([LockResource::ClientProjectMembership, LockResource::ClientCompanyMembership, LockResource::ClientPortalProjectAccess] as $accessResource) {
            $this->assertContains($accessResource->name, $recorded, $accessResource->name.' is registered but no driven transaction locked it.');
        }

        $found = [];

        $exemptions = LockOrderRecorder::maintenanceExemptions();
        $acquisitions = LockOrderRecorder::acquisitions();
        $exemptedCommands = [];
        foreach ($sequences as $index => $sequence) {
            if ($exemptions[$index] !== null) {
                $this->assertContains($exemptions[$index], self::MAINTENANCE_EXEMPTIONS);
                $this->assertSame(LockResource::BillingMaintenance, $sequence[0]);
                $this->assertSame(LockMode::Exclusive, $acquisitions[$index][0]->mode);
                $exemptedCommands[] = $exemptions[$index];

                continue;
            }
            if (in_array(LockResource::ClientTimeEntry, $sequence, true) || in_array(LockResource::ClientTask, $sequence, true)) {
                $this->assertSame(LockResource::BillingMaintenance, $sequence[0], 'Writers that lock time entries or tasks take the maintenance share lock first.');
                $this->assertSame(LockMode::Share, $acquisitions[$index][0]->mode);
            }
            foreach ($this->inversionsIn($sequence) as $inversion) {
                $found[$inversion] = true;
            }
        }

        $this->assertSame(self::MAINTENANCE_EXEMPTIONS, $exemptedCommands);
        $found = array_keys($found);
        sort($found);
        $known = self::KNOWN_INVERSIONS;
        sort($known);

        // An exact set, both directions. A new inversion fails, and so does an
        // inversion that has stopped happening - because a listed one that no
        // longer occurs is standing permission for it to come back.
        $this->assertSame($known, $found, sprintf(
            "The recorded lock order no longer matches the registry.\n\nNew: %s\nGone: %s\n\n".
            'A new inversion means two transactions can hold what the other is waiting for. Take the locks in '
            .'the order %s declares, or - if the new order is the right one - move the case and say why in '
            .'docs/client-management/concurrency.md.',
            implode(', ', array_diff($found, $known)) ?: '(none)',
            implode(', ', array_diff($known, $found)) ?: '(none)',
            LockResource::class,
        ));
    }

    /**
     * The recorder groups by transaction, not by test.
     *
     * The conformance claim is per transaction, so a recorder that ran two
     * transactions' locks together would report an inversion at every boundary
     * and one that split a transaction would report none at all. Both failures
     * are invisible in the assertion above - it would simply be measuring
     * something else - so the grouping is pinned on its own, against two
     * transactions of deliberately different length.
     */
    public function test_the_recorder_groups_acquisitions_by_transaction(): void
    {
        LockOrderRecorder::start();

        $agreement = $this->agreement();
        app(AgreementWorkflow::class)->activate($agreement);
        app(AgreementWorkflow::class)->sign($agreement->refresh(), $this->user, 'Synthetic Signer', 'Owner');

        $sequences = array_map(
            static fn (array $sequence): array => array_map(static fn (LockResource $r): string => $r->name, $sequence),
            LockOrderRecorder::sequences(),
        );

        $this->assertSame(
            [['ClientAgreement', 'ClientCompany'], ['ClientAgreement']],
            $sequences,
            'Two transactions of two locks and one - not one sequence of three, and not three of one.',
        );
    }

    /**
     * A lock on a table nobody registered is refused rather than unranked.
     *
     * The refusal is what makes the registry complete: an unregistered table
     * would be recorded nowhere, compared against nothing, and pass every check
     * above while being exactly the lock nobody has thought about.
     */
    public function test_an_unregistered_table_cannot_be_locked(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No lock-order registry entry for table "synthetic_unregistered_resources"');

        LockResource::forTable('synthetic_unregistered_resources');
    }

    /**
     * Drive every workflow that writes under a lock.
     *
     * Ordered so that each one leaves the fixture in a state the next can use,
     * which is also the order an operator would reach them in.
     */
    private function driveTheConcurrentWriters(): void
    {
        $this->driveEngagementAndInvoicing();
        $this->driveInterimOverageWithFragments();
        $this->driveOneLongGenerationTransaction();
        $this->drivePeriodPreview();
        $this->driveMaintenanceWriterSites();

        // Fragment recombination on its own, which locks a lineage group.
        $split = $this->approvedEntry('2026-10-05', 180);
        app(TimeEntrySplitter::class)->splitEntry($split, 120);
        app(AllocationService::class)->recombineUnlinkedFragments($this->workspace, $this->company);

        $this->driveTheExpenseLifecycle();
        $this->driveTheAccessMutations();
    }

    /**
     * Staff and portal access changes through the access boundary.
     *
     * Each takes the workspace access lock first, then any projects whose
     * revision it advances or whose public id it records, then the staff
     * memberships it re-checks authority against, and only then the
     * assignment or portal rows it writes. The
     * person removed holds a project assignment, so removal locks a project,
     * memberships and assignments in one transaction.
     */
    private function driveTheAccessMutations(): void
    {
        $access = app(WorkspaceAccess::class);
        $owner = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $context = AccessMutationContext::user($owner);

        $person = User::factory()->create();
        $access->grantStaff($context, $this->workspace, $person, StaffRole::Member);
        ClientProjectMembership::query()->create([
            'workspace_id' => $this->workspace->id, 'client_project_id' => $this->project->id,
            'user_id' => $person->id, 'role' => 'viewer',
        ]);
        // One project's assignment through the shared project workflow, which
        // takes the same workspace access lock before the project.
        app(WorkspaceProjectMutationAction::class)->updateAccess($owner, $this->workspace, $this->project, $person->public_id, 'manager');
        $access->changeStaffRole($context, $this->workspace, $person, StaffRole::Admin);
        $access->changeStaffRole($context, $this->workspace, $person, StaffRole::Member);
        $access->removeStaff($context, $this->workspace, $person);

        $client = User::factory()->create();
        $access->grantPortal($context, $this->company, $client, PortalAccessScope::Projects, [$this->project->public_id]);
        $access->changePortalScope($context, $this->company, $client, PortalAccessScope::Company);
        $access->changePortalScope($context, $this->company, $client, PortalAccessScope::Projects, [$this->project->public_id]);
        $access->revokePortal($context, $this->company, $client);
    }

    /**
     * The expense approval moves, which lock one row each.
     *
     * Included even though a sequence of one cannot invert, and worth saying
     * why rather than leaving it to look like padding. Two things it does buy:
     * the moves are *recorded*, so an approval that stopped taking its lock -
     * checking a status and writing on the strength of a read nothing held -
     * shows up here as a missing sequence rather than as nothing at all; and
     * `LockResource::ClientExpense` is exercised through a real transaction, so
     * the case is reachable rather than merely declared. It also means the
     * expense invoicing hook in #75's third slice arrives into a test that is
     * already driving expenses, which is when the position of that case in the
     * registry stops being derived from design and starts being recorded.
     */
    private function driveTheExpenseLifecycle(): void
    {
        $expenses = new WorkspaceExpenses($this->workspace);
        $expense = $expenses->record(
            $this->company,
            $this->project,
            new NewExpense(CarbonImmutable::parse('2026-10-06'), 12_500, 'USD', 'Synthetic travel expense'),
            $this->user,
        );

        $expenses->approve($expense, $this->user);
        $expenses->unapprove($expense->refresh());
        $expenses->discard($expense->refresh());
    }

    /**
     * Proposal, agreement, cadence generation, issue, payment, reconciliation,
     * schedule and void - the ordinary life of one company's billing.
     */
    private function driveEngagementAndInvoicing(): void
    {
        $proposal = ClientProposal::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Synthetic proposal',
            'status' => 'draft',
            'currency' => 'USD',
        ]);
        app(ProposalWorkflow::class)->send($proposal);

        $agreement = $this->agreement();
        app(AgreementWorkflow::class)->activate($agreement);

        $this->approvedEntry('2026-07-14', 600);
        $this->milestone($this->project, '2026-07-20');
        $invoice = app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31'),
            $agreement->refresh(),
        )->refresh();

        // Issue: the invoice, then the company whose credit pool it spends.
        $lifecycle = app(InvoiceLifecycleService::class);
        $lifecycle->issue($invoice, $this->workspace);

        // Money: the payment row first, then the invoice it is taken against,
        // then the reconciliation hung off the payment.
        $payment = $lifecycle->applyPayment($invoice->refresh(), [
            'amount' => 1000, 'currency' => 'USD', 'method' => 'ach',
            'status' => 'succeeded', 'idempotency_key' => 'synthetic-lock-order',
        ], $this->workspace);
        $lifecycle->setPaymentStatus($payment, 'succeeded', $this->workspace);
        $lifecycle->setRefundedAmount($payment->refresh(), 100, $this->workspace);
        // A date correction takes the same pair for the same reason: it starts
        // from the payment row and reaches the invoice through it.
        $lifecycle->setPaymentReceivedOn(
            $payment->refresh(),
            CarbonImmutable::now($this->workspace->timezone)->subDay()->toDateString(),
            $this->workspace,
        );
        app(PaymentReconciliationService::class)->upsert(
            $this->workspace,
            $payment->refresh(),
            $this->user,
            [
                'external_system_slug' => 'synthetic',
                'external_transaction_uuid' => (string) str()->uuid(),
                'allocated_amount' => 500,
                'currency' => 'USD',
            ],
        );

        // A billing schedule, which issues through the same lifecycle.
        $schedule = ClientBillingSchedule::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $agreement->id,
            'cadence' => 'monthly',
            'next_run_on' => '2026-09-01',
            'due_days' => 14,
            'currency' => 'USD',
            'line_template' => [[
                'type' => 'adjustment', 'description' => 'Synthetic scheduled line',
                'quantity' => '1.0000', 'unit_amount' => 5000, 'tax_amount' => 0, 'sort_order' => 0,
            ]],
        ]);
        app(BillingScheduleService::class)->generateDue($schedule, CarbonImmutable::parse('2026-09-15'));

        // Void, which releases the allocations a draft holds. A draft of its
        // own, because the invoice above has been paid against and voiding a
        // paid invoice is refused - correctly, and not what this is about.
        $voidable = $lifecycle->createDraft($this->workspace, $this->company, [
            'invoice_number' => 'LOCKS-VOIDABLE', 'currency' => 'USD',
        ], [[
            'type' => 'adjustment', 'description' => 'Synthetic voidable line',
            'quantity' => '1.0000', 'unit_amount' => 5000, 'tax_amount' => 0, 'sort_order' => 0,
        ]]);
        $lifecycle->void($voidable->refresh(), $this->workspace, 'Synthetic lock-order run');
    }

    /**
     * The interim generator, which recombines fragments before it has an
     * invoice - and so has to reach the number counter before the recombination
     * rather than at the create.
     *
     * Under a second company, because an agreement may not overlap another
     * active one and this cadence covers the same dates as the one above. The
     * split entry is what makes the recombination take a lock at all: with no
     * fragments to merge it locks nothing, and this path would look ordered
     * whether or not it is - which is what it looked like before #222.
     */
    private function driveInterimOverageWithFragments(): void
    {
        [$company, $project] = $this->secondaryCompany('interim');
        $agreement = $this->agreement('quarterly', $company);
        app(AgreementWorkflow::class)->activate($agreement);

        $split = $this->approvedEntry('2026-01-10', 900, $company, $project);
        app(TimeEntrySplitter::class)->splitEntry($split, 600);

        $draft = app(InterimOverageGenerator::class)->generateInterimOverageInvoice(
            $company,
            Carbon::parse('2026-01-01'),
            $agreement->refresh(),
        );
        $this->assertNotNull($draft);
        $this->assertSame('interim_overage', $draft->invoice_kind);
        $this->assertSame('draft', $draft->status);
        app(InvoiceLifecycleService::class)->issue($draft, $this->workspace);
        DB::transaction(function () use ($draft, $agreement): void {
            $claims = app(InterimOverageGenerator::class)->lockCycleClaims($draft->fresh(), $agreement->fresh());
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $claims[2]);
        });
    }

    private function driveMaintenanceWriterSites(): void
    {
        $time = app(TimeEntryMutationService::class);
        $entry = $this->approvedEntry('2026-10-05', 60);
        $entry->forceFill(['status' => 'draft'])->save();
        $time->update($this->workspace, $entry, $this->user, ['description' => 'Synthetic updated work', 'expected_version' => AgentApiVersion::for($entry)]);
        $entry->refresh()->forceFill(['status' => 'draft'])->save();
        $time->approve($this->workspace, $this->user, [['id' => $entry->public_id, 'expected_version' => AgentApiVersion::for($entry)]]);

        $selected = app(InvoiceFromTimeService::class);
        $draft = $selected->create($this->workspace, $this->company, ['invoice_number' => 'SYNTHETIC-MAINTENANCE', 'currency' => 'USD'], [$entry->public_id]);
        $selected->regenerateDraftSelection($draft, $this->workspace, $entry->id);
        app(InvoiceLifecycleService::class)->void($draft->fresh(), $this->workspace, 'Synthetic maintenance proof');

        $task = $this->milestone($this->project, '2026-10-06');
        app(WorkspaceTaskMutationAction::class)->update($this->user, $this->workspace, $task->public_id, ['title' => 'Synthetic updated milestone']);
        Storage::fake('svc-blobs');
        app(AttachmentAction::class)->store($this->workspace, 'task', $task->public_id, UploadedFile::fake()->createWithContent('synthetic.txt', 'Synthetic maintenance proof'), $this->user, AgentApiVersion::for($task->fresh()));
    }

    /**
     * Several periods generated inside one transaction, which is the shape
     * `svc:billing:replay` produces: it wraps the whole run in a transaction it
     * will roll back, so every period's locks are held together to the end.
     *
     * The first period claims a milestone and finds no fragments to recombine,
     * because its own allocation is what creates them; the second recombines
     * what the first left. Each generation is correctly ordered on its own, and
     * the pair is not: the task is still held when the time entry is asked for.
     * That is the one inversion a per-call review cannot see, and the reason
     * the registry is checked per transaction rather than per call.
     */
    private function driveOneLongGenerationTransaction(): void
    {
        [$company, $project] = $this->secondaryCompany('replay');
        $agreement = $this->agreement('monthly', $company);
        $agreement->forceFill(['starts_on' => '2026-07-01'])->save();
        app(AgreementWorkflow::class)->activate($agreement);

        // No fragments to start with. The first period's own allocation makes
        // them - work beyond the retainer is split across capacity pools - so
        // the first generation locks a task and no time entry, and the second
        // locks the fragments the first created.
        $this->approvedEntry('2026-07-14', 600, $company, $project);
        $this->milestone($project, '2026-07-20');
        $this->approvedEntry('2026-08-14', 600, $company, $project);

        $clock = Carbon::getTestNow();
        Carbon::setTestNow('2026-09-01');
        try {
            $this->assertSame(0, Artisan::call('svc:billing:rehearse-generation', ['--workspace' => $this->workspace->public_id, '--company' => $company->public_id]), Artisan::output());
            app(ClientInvoicingService::class)->generateAllInvoices($company);
            $this->assertSame(0, Artisan::call('svc:billing:replay', ['--workspace' => $this->workspace->public_id, '--company' => $company->public_id]), Artisan::output());
        } finally {
            Carbon::setTestNow($clock);
        }
    }

    /**
     * invoices.preview_period: its own transaction, opened for the rollback
     * and taking the period guard's locks before the cadence engine's. The
     * engine's milestone composition locks a task, so this sequence is held
     * to the share-first rule as well as to the order.
     */
    private function drivePeriodPreview(): void
    {
        [$company, $project] = $this->secondaryCompany('preview');
        $agreement = $this->agreement('monthly', $company);
        app(AgreementWorkflow::class)->activate($agreement);
        $this->approvedEntry('2026-09-14', 600, $company, $project);

        $clock = Carbon::getTestNow();
        Carbon::setTestNow('2026-10-08');
        $before = count(LockOrderRecorder::sequences());
        try {
            $preview = app(AgentInvoiceOperations::class)->preview($this->user, $this->workspace, $agreement->public_id, ['period_start' => '2026-10-01']);
            $this->assertNotSame([], $preview['lines']);
        } finally {
            Carbon::setTestNow($clock);
        }
        $sequences = array_slice(LockOrderRecorder::sequences(), $before);
        $this->assertCount(1, $sequences, 'The preview is one transaction.');
        $this->assertSame([LockResource::BillingMaintenance, LockResource::ClientAgreement], array_slice(array_values(array_unique($sequences[0], SORT_REGULAR)), 0, 2));
        $this->assertContains(LockResource::ClientTask, $sequences[0], 'The engine must reach a billing writer lock inside the preview.');
    }

    /** @return array{ClientCompany, ClientProject} */
    private function secondaryCompany(string $slug): array
    {
        $company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Locks '.$slug.' client',
            'slug' => 'locks-'.$slug.'-client',
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Locks '.$slug.' project',
        ]);

        return [$company, $project];
    }

    private function milestone(ClientProject $project, string $completedOn): ClientTask
    {
        return ClientTask::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $project->id,
            'title' => 'Synthetic deliverable',
            'status' => 'completed',
            'completed_at' => $completedOn,
            'milestone_price_amount' => 25000,
        ]);
    }

    private function agreement(string $cadence = 'monthly', ?ClientCompany $company = null): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => ($company ?? $this->company)->id,
            'title' => 'Synthetic '.$cadence.' agreement',
            'status' => 'draft',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 120,
            'retainer_amount' => 30000,
            'catch_up_threshold_minutes' => 0,
            'hourly_rate_amount' => 12000,
            'billing_cadence' => $cadence,
            'bill_overage_interim' => $cadence !== 'monthly',
            'rollover_months' => 0,
        ]);
    }

    private function approvedEntry(
        string $workedOn,
        int $minutes,
        ?ClientCompany $company = null,
        ?ClientProject $project = null,
    ): ClientTimeEntry {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => ($company ?? $this->company)->id,
            'client_project_id' => ($project ?? $this->project)->id,
            'user_id' => $this->user->id,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'description' => 'Synthetic locked work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'billing_rate_amount' => 12000,
            'billing_rate_source' => 'agreement',
            'currency' => 'USD',
        ]);
    }

    /**
     * Every first acquisition in this transaction that ranks below one already
     * held, named as the pair it inverts.
     *
     * Named by pair rather than by position so the failure says which two
     * resources disagree - the position is an implementation detail of the
     * enum and would change under an unrelated insertion.
     *
     * @param  list<LockResource>  $sequence
     * @return list<string>
     */
    private function inversionsIn(array $sequence): array
    {
        $inversions = [];
        /** @var array<int, LockResource> $held */
        $held = [];

        foreach ($sequence as $resource) {
            if (isset($held[$resource->rank()])) {
                continue;
            }

            foreach ($held as $earlier) {
                if ($earlier->rank() > $resource->rank()) {
                    $inversions[] = sprintf('%s before %s', $earlier->name, $resource->name);
                }
            }

            $held[$resource->rank()] = $resource;
        }

        return array_values(array_unique($inversions));
    }
}
