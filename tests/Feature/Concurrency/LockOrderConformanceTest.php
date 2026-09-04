<?php

namespace Tests\Feature\Concurrency;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\AllocationService;
use App\Services\Billing\BillingScheduleService;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InterimOverageGenerator;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\TimeEntrySplitter;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\Engagement\ProposalWorkflow;
use App\Services\Finance\PaymentReconciliationService;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
 * production, and the two places where today's code disagrees with itself are
 * written down instead of rediscovered.
 */
final class LockOrderConformanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The two places today's code takes a pair in the minority order.
     *
     * Both are real, both are reachable, and neither is fixed here: this issue
     * documents and enforces the order, and changing an acquisition order is a
     * behavioural change that belongs in its own commit with its own reasoning.
     * They are pinned as an exact set so they cannot multiply, and so that
     * fixing one fails this test rather than silently loosening it.
     *
     * - `ClientTimeEntry` before `Workspace`/`WorkspaceInvoiceCounter`:
     *   `InterimOverageGenerator` selects the time it is about to bill and
     *   *then* creates the invoice, which allocates a number and so locks the
     *   workspace and its counter. Every cadence path does the reverse -
     *   invoice and number first, time second. Two callers running those two
     *   paths at once can hold what the other is waiting for.
     * - `ClientTask` before `ClientTimeEntry`: milestone composition claims
     *   tasks before drawing time on one replay path and after it everywhere
     *   else.
     *
     * @var list<string>
     */
    private const KNOWN_INVERSIONS = [
        'ClientTask before ClientTimeEntry',
        'ClientTimeEntry before Workspace',
        'ClientTimeEntry before WorkspaceInvoiceCounter',
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

        $this->assertGreaterThanOrEqual(
            10,
            count($sequences),
            'The workflows below stopped taking locks, so this test is no longer proving anything about their order.',
        );

        $found = [];

        foreach ($sequences as $sequence) {
            foreach ($this->inversionsIn($sequence) as $inversion) {
                $found[$inversion] = true;
            }
        }

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
        $this->expectExceptionMessage('No lock-order registry entry for table "client_invoice_lines"');

        LockResource::forTable('client_invoice_lines');
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

        // Fragment recombination on its own, which locks a lineage group.
        $split = $this->approvedEntry('2026-10-05', 180);
        app(TimeEntrySplitter::class)->splitEntry($split, 120);
        app(AllocationService::class)->recombineUnlinkedFragments($this->workspace, $this->company);
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
     * invoice - and therefore before it allocates a number.
     *
     * Under a second company, because an agreement may not overlap another
     * active one and this cadence covers the same dates as the one above. The
     * split entry is what makes the recombination take a lock at all: with no
     * fragments to merge it locks nothing, and this path would look ordered.
     */
    private function driveInterimOverageWithFragments(): void
    {
        [$company, $project] = $this->secondaryCompany('interim');
        $agreement = $this->agreement('quarterly', $company);
        app(AgreementWorkflow::class)->activate($agreement);

        $split = $this->approvedEntry('2026-01-10', 900, $company, $project);
        app(TimeEntrySplitter::class)->splitEntry($split, 600);

        app(InterimOverageGenerator::class)->generateInterimOverageInvoice(
            $company,
            Carbon::parse('2026-01-01'),
            $agreement->refresh(),
        );
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
        app(AgreementWorkflow::class)->activate($agreement);

        // No fragments to start with. The first period's own allocation makes
        // them - work beyond the retainer is split across capacity pools - so
        // the first generation locks a task and no time entry, and the second
        // locks the fragments the first created.
        $this->approvedEntry('2026-07-14', 600, $company, $project);
        $this->milestone($project, '2026-07-20');
        $this->approvedEntry('2026-08-14', 600, $company, $project);

        DB::transaction(function () use ($company, $agreement): void {
            foreach ([['2026-07-01', '2026-07-31'], ['2026-08-01', '2026-08-31']] as [$start, $end]) {
                app(ClientInvoicingService::class)->generateInvoice(
                    $company,
                    Carbon::parse($start),
                    Carbon::parse($end),
                    $agreement->fresh(),
                );
            }
        });
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
