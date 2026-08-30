<?php

namespace Tests\Feature\Billing;

use App\Console\Commands\Billing\ReplayInvoicesCommand;
use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Balances\BillingCycle;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\MoneyService;
use App\Services\Billing\ReplayContractCorrectionClassifier;
use App\Services\Billing\ReplayHistoryBasis;
use App\Services\Billing\ReplayRecurringItemIncidenceRepository;
use App\Services\Billing\RetainerCalculator;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Tests\TestCase;

/**
 * The replay harness has to be trustworthy in two opposite directions: it must
 * notice when the engine no longer reproduces history, and it must never write
 * anything while finding out. A harness that silently passes is useless; one
 * that mutates production data to run is dangerous.
 */
final class ReplayInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Replay', 'slug' => 'replay']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Replay Client', 'slug' => 'replay-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Replay Project',
        ]);
        $this->user = User::factory()->create();
    }

    public function test_it_requires_a_workspace(): void
    {
        $this->artisan('svc:billing:replay')->assertFailed();
        $this->artisan('svc:billing:replay', ['--workspace' => 'nope'])->assertFailed();
    }

    public function test_agreement_rate_source_eligibility_is_available_without_queries(): void
    {
        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => '2026-01-10',
            'minutes' => 60,
            'description' => 'Synthetic eligibility proof',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);

        $this->assertTrue($entry->isAgreementRateBillable());

        $entry->forceFill([
            'subcontractor_billing_mode' => 'retainer',
            'subcontractor_cost_amount' => 5000,
        ])->syncOriginal();
        $this->assertTrue($entry->isAgreementRateBillable());

        foreach (['flat_hourly', 'direct'] as $mode) {
            $entry->forceFill(['subcontractor_billing_mode' => $mode])->syncOriginal();
            $this->assertFalse($entry->isAgreementRateBillable(), $mode);
        }

        $entry->forceFill([
            'subcontractor_billing_mode' => null,
            'subcontractor_cost_amount' => 5000,
        ])->syncOriginal();
        $this->assertFalse($entry->isAgreementRateBillable(), 'Unclassified subcontractor cost fails closed.');

        $entry->forceFill([
            'subcontractor_cost_amount' => null,
            'status' => 'draft',
        ])->syncOriginal();
        $this->assertFalse($entry->isAgreementRateBillable(), 'Unapproved work is not agreement-rate billable.');

        $entry->forceFill([
            'status' => 'approved',
            'is_billable' => false,
        ])->syncOriginal();
        $this->assertFalse($entry->isAgreementRateBillable(), 'Non-billable work is not agreement-rate billable.');
    }

    public function test_snapshot_source_eligibility_requires_company_project_and_service_period_scope(): void
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'title' => 'Scoped snapshot agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'hourly_rate_amount' => 20000,
            'billing_cadence' => 'monthly',
        ]);
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $agreement->id,
            'invoice_number' => 'SCOPED-SNAPSHOT',
            'currency' => 'USD',
            'status' => 'draft',
            'invoice_kind' => 'cadence_period',
            'cycle_start' => '2026-02-01',
            'cycle_end' => '2026-02-28',
            'service_period_start' => '2026-01-01',
            'service_period_end' => '2026-01-31',
            'subtotal_amount' => 170000,
            'tax_amount' => 0,
            'total_amount' => 170000,
        ]);
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $invoice->id,
            'client_agreement_id' => $agreement->id,
            'type' => 'additional_hours',
            'description' => 'Synthetic scoped work',
            'quantity' => '1.0000',
            'hours' => '1.0000',
            'line_date' => '2026-01-15',
            'unit_amount' => 20000,
            'tax_amount' => 0,
            'total_amount' => 20000,
            'sort_order' => 1,
        ]);
        $retainer = ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $invoice->id,
            'client_agreement_id' => $agreement->id,
            'type' => 'retainer',
            'description' => 'Monthly Retainer (10:00 hours) - Feb 1, 2026 through Feb 28, 2026',
            'quantity' => '1.0000',
            'hours' => '10.0000',
            'line_date' => '2026-02-01',
            'unit_amount' => 150000,
            'tax_amount' => 0,
            'total_amount' => 150000,
            'sort_order' => 2,
        ]);
        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => '2026-01-15',
            'minutes' => 60,
            'description' => 'Synthetic scoped source',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
        $line->timeEntries()->attach($entry->id, ['workspace_id' => $this->workspace->id]);

        $snapshot = new ReflectionMethod(ReplayInvoicesCommand::class, 'snapshot');
        $snapshotLine = function () use ($snapshot): array {
            /** @var array<string, array<string, mixed>> $rows */
            $rows = $snapshot->invoke(
                app(ReplayInvoicesCommand::class),
                $this->workspace,
                collect([$this->company]),
            );

            /** @var array<string, mixed> $capturedLine */
            $capturedLine = array_values($rows)[0]['lines'][0];

            return $capturedLine;
        };

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $validSnapshotLine = $snapshotLine();
        $this->assertSame(60, $validSnapshotLine['source_minutes']);
        $this->assertSame(60, $validSnapshotLine['source_agreement_rate_minutes']);
        $this->assertCount(6, $queries, implode("\n", $queries));

        $capturedRetainer = function () use ($snapshot): array {
            /** @var array<string, array<string, mixed>> $rows */
            $rows = $snapshot->invoke(
                app(ReplayInvoicesCommand::class),
                $this->workspace,
                collect([$this->company]),
            );
            /** @var list<array<string, mixed>> $lines */
            $lines = array_values($rows)[0]['lines'];
            /** @var array<string, mixed> $captured */
            $captured = collect($lines)->firstWhere('type', 'retainer');

            return $captured;
        };
        $this->assertTrue($capturedRetainer()['canonical_cadence_description']);
        foreach ([
            'Quarterly Retainer (10:00 hours) - Feb 1, 2026 through Feb 28, 2026',
            'Monthly Retainer (9:00 hours) - Feb 1, 2026 through Feb 28, 2026',
            'Monthly Retainer (10:00 hours) - Feb 1, 2026 through Mar 1, 2026',
        ] as $wrongDescription) {
            $retainer->update(['description' => $wrongDescription]);
            $this->assertFalse($capturedRetainer()['canonical_cadence_description']);
        }

        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Other source company',
            'slug' => 'other-source-company',
        ]);
        $entry->update(['client_company_id' => $otherCompany->id]);
        $this->assertSame(0, $snapshotLine()['source_agreement_rate_minutes']);

        $otherProject = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Outside agreement scope',
        ]);
        $entry->update([
            'client_company_id' => $this->company->id,
            'client_project_id' => $otherProject->id,
        ]);
        $this->assertSame(0, $snapshotLine()['source_agreement_rate_minutes']);

        $entry->update([
            'client_project_id' => $this->project->id,
            'worked_on' => '2026-02-01',
        ]);
        $this->assertSame(0, $snapshotLine()['source_agreement_rate_minutes']);
        $this->assertSame(60, $snapshotLine()['source_minutes'], 'Malformed scope is retained in the total source aggregate so the proof fails closed.');
    }

    /**
     * Invoices the current engine produced are, by definition, reproducible by
     * the current engine. This is the control: if it fails, the harness is
     * comparing the wrong things.
     */
    public function test_invoices_the_engine_produced_replay_to_the_cent(): void
    {
        $this->generatedHistory();
        ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'No invoice history',
            'slug' => 'no-invoice-history',
        ]);

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])
            ->expectsOutputToContain('money identical')
            ->assertSuccessful();
    }

    public function test_history_before_the_recorded_agreement_start_uses_its_service_period_as_the_replay_basis(): void
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Historical retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'hourly_rate_amount' => 20000,
            'billing_cadence' => 'monthly',
            'catch_up_threshold_minutes' => 0,
            'rollover_months' => 0,
        ]);
        foreach (['2025-12-14' => 1514, '2026-01-14' => 900, '2026-02-14' => 900] as $workedOn => $minutes) {
            ClientTimeEntry::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'client_project_id' => $this->project->id,
                'user_id' => $this->user->id,
                // The replay seed makes December part of the balance chain;
                // the agreement itself still starts in January.
                'worked_on' => $workedOn,
                'minutes' => $minutes,
                'description' => 'Historical work',
                'is_billable' => true,
                'is_deferred' => false,
                'status' => 'approved',
                'currency' => 'USD',
            ]);
        }

        Carbon::setTestNow(Carbon::parse('2026-04-15'));
        $historyBasis = app(ReplayHistoryBasis::class);
        $historyBasis->seed($agreement, Carbon::parse('2025-12-01'));
        try {
            app(ClientInvoicingService::class)->generateAllInvoices($this->company);
        } finally {
            $historyBasis->reset();
            Carbon::setTestNow();
        }

        $openingHistory = ClientInvoice::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('client_agreement_id', $agreement->id)
            ->whereDate('service_period_start', '2025-12-01')
            ->firstOrFail();
        $this->assertSame(
            1,
            $openingHistory->lines()->where('type', 'additional_hours')->count(),
            (string) json_encode($openingHistory->lines()->get(['type', 'quantity', 'hours', 'total_amount'])->toArray()),
        );
        $historicalHourly = $openingHistory->lines()->where('type', 'additional_hours')->firstOrFail();
        $this->assertSame(
            314,
            (int) round((float) $historicalHourly->hours * 60),
            'The replay-adjusted agreement must grant December its 600-minute retainer before pricing overage.',
        );
        $sourceRoundedTotal = MoneyService::invoiceTotals([[
            'quantity' => (string) $historicalHourly->quantity,
            'unit_amount' => (int) $historicalHourly->unit_amount,
            'tax_amount' => 0,
        ]])['subtotal_amount'];
        $this->assertSame($sourceRoundedTotal + 1, (int) $historicalHourly->total_amount);
        $historicalHourly->update(['total_amount' => $sourceRoundedTotal]);
        $openingHistory->recalculateTotals();

        // The predecessor labelled each invoice's sold cycle as the same
        // period whose work it reconciled. The current engine sells the next
        // cycle while reconciling that work. This is the live-history shape:
        // its first cycle predates the agreement even though its service period
        // is exactly one the current engine can regenerate.
        ClientInvoice::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('client_agreement_id', $agreement->id)
            ->orderBy('service_period_start')
            // The imported chains changed conventions in place: a predecessor
            // prefix uses period-equals-cycle and the remaining invoices already
            // use the current next-cycle label. Replay must prove and bridge that
            // one-way boundary rather than require history to be uniform forever.
            ->limit(2)
            ->get()
            ->each(static fn (ClientInvoice $invoice) => $invoice->update([
                'cycle_start' => $invoice->service_period_start,
                'cycle_end' => $invoice->service_period_end,
            ]));
        $before = $this->fingerprint();

        $report = tempnam(sys_get_temp_dir(), 'replay-history-basis-');
        $this->assertNotFalse($report);
        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])
                ->expectsOutputToContain('replay-only historical agreement seed')
                ->assertSuccessful();

            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
            $moneyDifferences = array_values(array_filter(
                (array) ($detail['comparisons'] ?? []),
                static fn (array $comparison): bool => ($comparison['verdict'] ?? null) === 'money_differs',
            ));
            $this->assertSame([], array_values(array_filter(
                $moneyDifferences,
                static fn (array $comparison): bool => ($comparison['explained_by'] ?? []) === [],
            )), 'The replay-only ledger seed must leave no unexplained money difference.');
            $this->assertCount(1, array_values(array_filter(
                $moneyDifferences,
                static fn (array $comparison): bool => in_array(
                    'hourly_lines_use_exact_minutes',
                    array_column((array) ($comparison['explained_by'] ?? []), 'key'),
                    true,
                ),
            )));
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        $this->assertSame('2026-01-01', $agreement->fresh()->starts_on?->toDateString());
        $this->assertSame($before, $this->fingerprint(), 'The replay-only seed must roll back with every other replay write');

        $historicalQuantity = (string) $historicalHourly->quantity;
        DB::table('client_invoice_lines')->where('id', $historicalHourly->id)->update(['quantity' => '9.9999']);
        $this->artisan('svc:billing:replay', [
            '--workspace' => $this->workspace->public_id,
        ])->assertFailed();
        DB::table('client_invoice_lines')->where('id', $historicalHourly->id)->update(['quantity' => $historicalQuantity]);

        $retainerLine = ClientInvoiceLine::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('client_invoice_id', $openingHistory->id)
            ->where('type', 'retainer')
            ->firstOrFail();
        DB::table('client_invoice_lines')->where('id', $retainerLine->id)->update(['quantity' => '2.0000']);

        $this->artisan('svc:billing:replay', [
            '--workspace' => $this->workspace->public_id,
        ])->assertFailed();
    }

    public function test_history_seed_cannot_follow_a_foreign_agreement_id_out_of_the_workspace(): void
    {
        $otherWorkspace = Workspace::query()->create(['name' => 'Other replay', 'slug' => 'other-replay']);
        $foreignAgreement = ClientAgreement::query()->create([
            'workspace_id' => $otherWorkspace->id,
            // Independent foreign keys permit this corrupt chain. Company
            // scope alone therefore cannot stand in for workspace scope.
            'client_company_id' => $this->company->id,
            'title' => 'Foreign retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'billing_cadence' => 'monthly',
        ]);
        ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $foreignAgreement->id,
            'invoice_number' => 'FOREIGN-AGREEMENT-ID',
            'currency' => 'USD',
            'status' => 'draft',
            'invoice_kind' => 'cadence_period',
            'cycle_start' => '2025-12-01',
            'cycle_end' => '2025-12-31',
            'service_period_start' => '2025-11-01',
            'service_period_end' => '2025-11-30',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);

        $method = new ReflectionMethod(ReplayInvoicesCommand::class, 'prepareReplayOnlyHistoryBasis');
        $seeded = $method->invoke(app(ReplayInvoicesCommand::class), $this->workspace, collect([$this->company]));

        $this->assertSame([], $seeded);
        $this->assertSame('2026-01-01', $foreignAgreement->fresh()->starts_on?->toDateString());
    }

    public function test_history_seed_rejects_an_invoice_owned_by_another_selected_company(): void
    {
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Other selected client',
            'slug' => 'other-selected-client',
        ]);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Company-owned history',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'billing_cadence' => 'monthly',
        ]);
        ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            // Both companies are selected, so company filtering alone cannot
            // prove this invoice belongs to the agreement it names.
            'client_company_id' => $otherCompany->id,
            'client_agreement_id' => $agreement->id,
            'invoice_number' => 'CROSS-COMPANY-SEED',
            'currency' => 'USD',
            'status' => 'issued',
            'invoice_kind' => 'cadence_period',
            'cycle_start' => '2025-12-01',
            'cycle_end' => '2025-12-31',
            'service_period_start' => '2025-12-01',
            'service_period_end' => '2025-12-31',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);

        $basis = app(ReplayHistoryBasis::class);
        $basis->reset();
        $method = new ReflectionMethod(ReplayInvoicesCommand::class, 'prepareReplayOnlyHistoryBasis');

        $this->assertSame([], $method->invoke(
            app(ReplayInvoicesCommand::class),
            $this->workspace,
            collect([$this->company, $otherCompany]),
        ));
        $this->assertSame(
            '2026-01-01',
            $basis->startFor($agreement, Carbon::parse('2026-01-01'))->toDateString(),
        );
    }

    public function test_history_seed_rejects_a_chain_that_does_not_open_with_the_legacy_cycle_convention(): void
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Ambiguous historical retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'billing_cadence' => 'monthly',
        ]);
        ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $agreement->id,
            'invoice_number' => 'AMBIGUOUS-HISTORY',
            'currency' => 'USD',
            'status' => 'draft',
            'invoice_kind' => 'cadence_period',
            'cycle_start' => '2025-12-01',
            'cycle_end' => '2025-12-31',
            'service_period_start' => '2025-11-01',
            'service_period_end' => '2025-11-30',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);

        $basis = app(ReplayHistoryBasis::class);
        $basis->reset();
        $method = new ReflectionMethod(ReplayInvoicesCommand::class, 'prepareReplayOnlyHistoryBasis');
        $seeded = $method->invoke(app(ReplayInvoicesCommand::class), $this->workspace, collect([$this->company]));

        $this->assertSame([], $seeded);
        $this->assertSame(
            '2026-01-01',
            $basis->startFor($agreement, Carbon::parse('2026-01-01'))->toDateString(),
        );
    }

    public function test_history_seed_rejects_a_chain_that_switches_back_to_the_legacy_convention(): void
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Regressing historical convention',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'billing_cadence' => 'monthly',
        ]);
        foreach ([
            ['LEGACY-OPEN', '2025-12-01', '2025-12-01'],
            ['CURRENT-MIDDLE', '2026-02-01', '2026-01-01'],
            ['LEGACY-AGAIN', '2026-02-01', '2026-02-01'],
        ] as [$number, $cycleStart, $serviceStart]) {
            $cycle = Carbon::parse($cycleStart);
            $service = Carbon::parse($serviceStart);
            ClientInvoice::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'client_agreement_id' => $agreement->id,
                'invoice_number' => $number,
                'currency' => 'USD',
                'status' => 'draft',
                'invoice_kind' => 'cadence_period',
                'cycle_start' => $cycle,
                'cycle_end' => $cycle->copy()->endOfMonth(),
                'service_period_start' => $service,
                'service_period_end' => $service->copy()->endOfMonth(),
                'subtotal_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
            ]);
        }

        $basis = app(ReplayHistoryBasis::class);
        $basis->reset();
        $method = new ReflectionMethod(ReplayInvoicesCommand::class, 'prepareReplayOnlyHistoryBasis');

        $this->assertSame(
            [],
            $method->invoke(app(ReplayInvoicesCommand::class), $this->workspace, collect([$this->company])),
        );
        $this->assertSame(
            '2026-01-01',
            $basis->startFor($agreement, Carbon::parse('2026-01-01'))->toDateString(),
        );
    }

    public function test_history_seed_deduplicates_attempts_with_the_same_service_period(): void
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Duplicate historical attempts',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'billing_cadence' => 'monthly',
        ]);
        foreach ([
            ['SETTLED-ATTEMPT', 'issued', '2025-12-01'],
            // Newer, but still a draft: snapshot selection keeps the settled
            // attempt, and seed validation must make the same choice.
            ['ABANDONED-ATTEMPT', 'draft', '2026-01-01'],
        ] as [$number, $status, $cycleStart]) {
            ClientInvoice::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'client_agreement_id' => $agreement->id,
                'invoice_number' => $number,
                'currency' => 'USD',
                'status' => $status,
                'invoice_kind' => 'cadence_period',
                'cycle_start' => $cycleStart,
                'cycle_end' => Carbon::parse($cycleStart)->endOfMonth(),
                'service_period_start' => '2025-12-01',
                'service_period_end' => '2025-12-31',
                'subtotal_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
            ]);
        }

        $method = new ReflectionMethod(ReplayInvoicesCommand::class, 'prepareReplayOnlyHistoryBasis');
        $seeded = $method->invoke(app(ReplayInvoicesCommand::class), $this->workspace, collect([$this->company]));

        $this->assertSame(['2025-12-01'], $seeded);
    }

    public function test_a_void_latest_attempt_cannot_resurrect_an_older_replay_seed(): void
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Cancelled historical cycle',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'billing_cadence' => 'monthly',
        ]);
        foreach (['issued', 'void'] as $status) {
            ClientInvoice::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'client_agreement_id' => $agreement->id,
                'invoice_number' => 'CANCELLED-SEED-'.strtoupper($status),
                'currency' => 'USD',
                'status' => $status,
                'invoice_kind' => 'cadence_period',
                'cycle_start' => '2025-12-01',
                'cycle_end' => '2025-12-31',
                'service_period_start' => '2025-12-01',
                'service_period_end' => '2025-12-31',
                'subtotal_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
            ]);
        }

        $basis = app(ReplayHistoryBasis::class);
        $basis->reset();
        $method = new ReflectionMethod(ReplayInvoicesCommand::class, 'prepareReplayOnlyHistoryBasis');

        $this->assertSame(
            [],
            $method->invoke(app(ReplayInvoicesCommand::class), $this->workspace, collect([$this->company])),
        );
        $this->assertSame(
            '2026-01-01',
            $basis->startFor($agreement, Carbon::parse('2026-01-01'))->toDateString(),
        );
    }

    public function test_history_seed_uses_effective_period_retainer_terms(): void
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Native period terms',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            // Legacy monthly columns deliberately disagree with the native
            // period overrides the billing engine actually uses.
            'retainer_minutes' => 60,
            'retainer_amount' => 10000,
            'period_retainer_minutes' => 600,
            'period_retainer_amount' => 150000,
            'billing_cadence' => 'monthly',
        ]);
        ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $agreement->id,
            'invoice_number' => 'PERIOD-TERMS-SEED',
            'currency' => 'USD',
            'status' => 'issued',
            'invoice_kind' => 'cadence_period',
            'cycle_start' => '2025-12-01',
            'cycle_end' => '2025-12-31',
            'service_period_start' => '2025-12-01',
            'service_period_end' => '2025-12-31',
            'subtotal_amount' => 150000,
            'tax_amount' => 0,
            'total_amount' => 150000,
        ]);

        $command = app(ReplayInvoicesCommand::class);
        $method = new ReflectionMethod(ReplayInvoicesCommand::class, 'prepareReplayOnlyHistoryBasis');
        $this->assertSame(['2025-12-01'], $method->invoke($command, $this->workspace, collect([$this->company])));
        $seeds = new \ReflectionProperty(ReplayInvoicesCommand::class, 'historySeeds');
        $seed = $seeds->getValue($command)[$agreement->id];

        $this->assertSame(600, $seed->retainerMinutes);
        $this->assertSame(150000, $seed->retainerAmount);
    }

    public function test_history_seed_loads_all_candidate_chains_in_two_queries(): void
    {
        foreach (range(1, 3) as $ordinal) {
            $agreement = ClientAgreement::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'title' => 'Bounded seed '.$ordinal,
                'status' => 'active',
                'currency' => 'USD',
                'starts_on' => '2026-01-01',
                'retainer_minutes' => 600,
                'retainer_amount' => 150000,
                'billing_cadence' => 'monthly',
            ]);
            ClientInvoice::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'client_agreement_id' => $agreement->id,
                'invoice_number' => 'BOUNDED-'.$ordinal,
                'currency' => 'USD',
                'status' => 'draft',
                'invoice_kind' => 'cadence_period',
                'cycle_start' => '2025-12-01',
                'cycle_end' => '2025-12-31',
                'service_period_start' => '2025-12-01',
                'service_period_end' => '2025-12-31',
                'subtotal_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
            ]);
        }

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $method = new ReflectionMethod(ReplayInvoicesCommand::class, 'prepareReplayOnlyHistoryBasis');
        $seeded = $method->invoke(app(ReplayInvoicesCommand::class), $this->workspace, collect([$this->company]));

        $this->assertCount(3, $seeded);
        $this->assertCount(2, $queries, implode("\n", $queries));
    }

    public function test_seeded_as_of_ignores_a_later_ad_hoc_service_period(): void
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Seed anchor contract',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'billing_cadence' => 'monthly',
        ]);
        ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $agreement->id,
            'invoice_number' => 'SEED-ANCHOR',
            'currency' => 'USD',
            'status' => 'draft',
            'invoice_kind' => 'cadence_period',
            'cycle_start' => '2025-12-01',
            'cycle_end' => '2025-12-31',
            'service_period_start' => '2025-12-01',
            'service_period_end' => '2025-12-31',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);

        $command = app(ReplayInvoicesCommand::class);
        $command->setInput(new ArrayInput([], $command->getDefinition()));
        $prepare = new ReflectionMethod(ReplayInvoicesCommand::class, 'prepareReplayOnlyHistoryBasis');
        $this->assertCount(1, $prepare->invoke($command, $this->workspace, collect([$this->company])));
        ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $agreement->id,
            'invoice_number' => 'ADHOC-LATER-SERVICE',
            'currency' => 'USD',
            'status' => 'draft',
            'invoice_kind' => 'ad_hoc',
            'cycle_start' => '2025-12-01',
            'cycle_end' => '2025-12-31',
            'service_period_start' => '2027-01-01',
            'service_period_end' => '2027-01-31',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);

        $asOf = new ReflectionMethod(ReplayInvoicesCommand::class, 'asOf');
        $this->assertSame(
            '2025-12-31',
            $asOf->invoke($command, $this->workspace, $this->company)->toDateString(),
        );
    }

    public function test_an_omitted_opening_recurring_item_incidence_is_proved_from_the_item_contract(): void
    {
        $item = null;
        $this->generatedHistory(function (ClientAgreement $agreement) use (&$item): void {
            $item = ClientAgreementRecurringItem::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_agreement_id' => $agreement->id,
                'description' => 'Synthetic annual service',
                'cadence' => 'annual',
                'anchor_month' => 1,
                // The configured anchor precedes the effective date. The
                // biller deliberately falls back to the effective date for the
                // opening incidence, and the replay proof must accept the exact
                // line the biller itself produces.
                'anchor_day' => 1,
                'effective_on' => '2024-01-10',
                'quantity' => '1.000',
                'amount' => 4200,
                'currency' => 'USD',
                'is_active' => true,
            ]);
        });
        $this->assertInstanceOf(ClientAgreementRecurringItem::class, $item);

        $line = ClientInvoiceLine::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('client_agreement_recurring_item_id', $item->id)
            ->whereDate('line_date', '2024-01-10')
            ->firstOrFail();
        $invoice = $line->invoice()->firstOrFail();
        $line->delete();
        $invoice->recalculateTotals();

        $report = tempnam(sys_get_temp_dir(), 'replay-recurring-opening-');
        $this->assertNotFalse($report);
        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->assertSuccessful();

            /** @var array{comparisons:list<array<string,mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
            $comparison = array_column($detail['comparisons'], null, 'invoice_number')[(string) $invoice->invoice_number] ?? null;
            $this->assertIsArray($comparison);
            $this->assertSame('money_differs', $comparison['verdict']);
            $this->assertSame(
                ['recurring_item_bills_on_configured_start'],
                array_column((array) ($comparison['explained_by'] ?? []), 'key'),
            );
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }
    }

    public function test_an_accepted_proposal_item_uses_the_agreement_start_as_its_opening_incidence(): void
    {
        $item = null;
        $this->generatedHistory(function (ClientAgreement $agreement) use (&$item): void {
            $item = ClientAgreementRecurringItem::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_agreement_id' => $agreement->id,
                'description' => 'Synthetic proposal service',
                'cadence' => 'annual',
                'anchor_month' => 1,
                'anchor_day' => 1,
                // Proposal acceptance intentionally leaves this null; the
                // production biller falls back to the agreement start.
                'effective_on' => null,
                'quantity' => '1.000',
                'amount' => 4200,
                'currency' => 'USD',
                'is_active' => true,
            ]);
        });
        $this->assertInstanceOf(ClientAgreementRecurringItem::class, $item);

        $line = ClientInvoiceLine::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('client_agreement_recurring_item_id', $item->id)
            ->whereDate('line_date', '2024-01-01')
            ->firstOrFail();
        $invoice = $line->invoice()->firstOrFail();
        $line->delete();
        $invoice->recalculateTotals();

        $this->artisan('svc:billing:replay', [
            '--workspace' => $this->workspace->public_id,
        ])->assertSuccessful();
    }

    public function test_a_seeded_replay_proves_an_opening_recurring_item_against_the_real_sold_cycle(): void
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Seeded recurring retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 0,
        ]);
        $item = ClientAgreementRecurringItem::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_agreement_id' => $agreement->id,
            'description' => 'Synthetic opening service',
            'cadence' => 'annual',
            'anchor_month' => 1,
            'anchor_day' => 10,
            'effective_on' => '2026-01-10',
            'quantity' => '1.000',
            'amount' => 4200,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $basis = app(ReplayHistoryBasis::class);
        $basis->seed($agreement, Carbon::parse('2025-12-01'));
        Carbon::setTestNow(Carbon::parse('2026-02-15'));
        try {
            app(ClientInvoicingService::class)->generateAllInvoices($this->company);
        } finally {
            $basis->reset();
            Carbon::setTestNow();
        }

        $line = ClientInvoiceLine::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('client_agreement_recurring_item_id', $item->id)
            ->whereDate('line_date', '2026-01-10')
            ->firstOrFail();
        $opening = $line->invoice()->firstOrFail();
        $this->assertSame('2025-12-01', $opening->service_period_start?->toDateString());
        $this->assertSame('2026-01-01', $opening->cycle_start?->toDateString());
        $line->delete();
        $opening->recalculateTotals();

        ClientInvoice::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('client_agreement_id', $agreement->id)
            ->get()
            ->each(static fn (ClientInvoice $invoice) => $invoice->update([
                'cycle_start' => $invoice->service_period_start,
                'cycle_end' => $invoice->service_period_end,
            ]));

        $report = tempnam(sys_get_temp_dir(), 'replay-seeded-recurring-');
        $this->assertNotFalse($report);
        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->assertSuccessful();

            /** @var array{comparisons:list<array<string,mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
            $comparison = array_column($detail['comparisons'], null, 'invoice_number')[(string) $opening->invoice_number] ?? null;
            $this->assertIsArray($comparison);
            $this->assertContains(
                'recurring_item_bills_on_configured_start',
                array_column((array) ($comparison['explained_by'] ?? []), 'key'),
            );
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }
    }

    public function test_seeded_comparison_rejects_a_generated_shifted_sold_cycle(): void
    {
        $command = new ReplayInvoicesCommand;
        $historyBasis = new \ReflectionProperty(ReplayInvoicesCommand::class, 'historyBasisAgreementIds');
        $historyBasis->setValue($command, [999 => true]);
        $snapshot = [
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'cycle_start' => '2026-02-01',
            'cycle_end' => '2026-02-28',
            'service_period_start' => '2026-01-01',
            'service_period_end' => '2026-01-31',
            'lines' => [],
        ];
        $shifted = $snapshot;
        $shifted['cycle_start'] = '2026-03-01';
        $shifted['cycle_end'] = '2026-03-31';

        $examine = new ReflectionMethod(ReplayInvoicesCommand::class, 'examine');
        $comparison = $examine->invoke(
            $command,
            $this->workspace,
            $snapshot,
            $shifted,
            $this->company->id.'|999|cadence_period|2026-01-01..2026-01-31@2026-01-01..2026-01-31',
        );

        $this->assertContains('cycle', $comparison['changed_fields']);
        $this->assertTrue($comparison['metadata_differs']);
    }

    public function test_capacity_attribution_ignores_only_an_unchanged_retainer_contract(): void
    {
        $line = static fn (string $type, int $total, int $unit, float $hours, string $identity): array => [
            'type' => $type,
            'total_amount' => $total,
            'unit_amount' => $unit,
            'tax_amount' => 0,
            'quantity' => $type === 'prior_month_retainer' ? '0.0000' : number_format($hours, 4, '.', ''),
            'line_date' => '2026-04-01',
            'recurring_item_id' => '',
            'project_id' => '',
            'agreement_id' => '7',
            'claimed_by' => '',
            'description_hash' => $identity.'-description',
            'identity_hash' => $identity,
            'hours' => $hours,
            'source_minutes' => $type === 'retainer' ? 0 : (int) round($hours * 60),
            'source_agreement_rate_minutes' => $type === 'retainer' ? 0 : (int) round($hours * 60),
        ];
        $retainer = $line('retainer', 150000, 150000, 1, 'retainer');
        $before = [
            'currency' => 'USD', 'service_period_end' => '2026-04-01',
            'subtotal_amount' => 250000, 'tax_amount' => 0, 'total_amount' => 250000,
            'lines' => [$retainer, $line('additional_hours', 100000, 20000, 5, 'hourly')],
        ];
        $retainer['description_hash'] = 'new-display-wording';
        $retainer['identity_hash'] = 'new-display-identity';
        $after = [
            'currency' => 'USD', 'service_period_end' => '2026-04-01',
            'subtotal_amount' => 210000, 'tax_amount' => 0, 'total_amount' => 210000,
            'lines' => [
                $retainer,
                $line('additional_hours', 60000, 20000, 3, 'hourly'),
                $line('prior_month_retainer', 0, 0, 2, 'capacity'),
            ],
        ];

        $comparison = (new ReflectionMethod(ReplayInvoicesCommand::class, 'examine'))->invoke(
            new ReplayInvoicesCommand,
            $this->workspace,
            $before,
            $after,
        );

        $this->assertContains('retainer', $comparison['changed_types'], 'Display changes remain visible in the report.');
        $this->assertNotContains('retainer', $comparison['attribution_changed_types']);
        $this->assertTrue($comparison['capacity_reallocated_at_same_rate']);

        $after['lines'][0]['total_amount']--;
        $changedContract = (new ReflectionMethod(ReplayInvoicesCommand::class, 'examine'))->invoke(
            new ReplayInvoicesCommand,
            $this->workspace,
            $before,
            $after,
        );

        $this->assertContains('retainer', $changedContract['attribution_changed_types']);
        $this->assertFalse($changedContract['capacity_reallocated_at_same_rate']);
    }

    public function test_a_complete_configured_cadence_missing_from_ad_hoc_only_history_is_explained(): void
    {
        $agreement = $this->cadenceGapAgreement();
        $agreement->update(['ends_on' => '2026-10-15']);
        ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => '2026-08-15',
            'minutes' => 1200,
            'description' => 'Synthetic final-cycle work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);

        foreach ([
            ['ADHOC-GAP-1', '2026-07-01', '2024-01-01', '2024-01-31'],
            ['ADHOC-GAP-2', '2027-01-01', '2024-02-01', '2024-02-29'],
        ] as [$number, $cycleStart, $periodStart, $periodEnd]) {
            $this->adHocHistory($number, $cycleStart, $periodStart, $periodEnd);
        }

        $report = tempnam(sys_get_temp_dir(), 'replay-cadence-gap-');
        $this->assertNotFalse($report);
        try {
            $exit = Artisan::call('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ]);

            /** @var array{comparisons:list<array<string,mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
            $explained = array_values(array_filter(
                $detail['comparisons'],
                static fn (array $comparison): bool => $comparison['verdict'] === 'unexpected'
                    && in_array(
                        'configured_cadence_absent_from_history',
                        array_column((array) ($comparison['explained_by'] ?? []), 'key'),
                        true,
                    ),
            ));

            $this->assertSame(0, $exit, (string) json_encode(array_map(
                static fn (array $comparison): array => [
                    'verdict' => $comparison['verdict'] ?? null,
                    'money_delta' => $comparison['money_delta'] ?? null,
                    'contract_gap' => $comparison['contract_cadence_history_gap'] ?? null,
                    'explained' => array_column((array) ($comparison['explained_by'] ?? []), 'key'),
                    'notes' => $comparison['notes'] ?? [],
                ],
                $detail['comparisons'],
            )));
            $this->assertCount(3, $explained);
            $this->assertSame(
                0,
                ClientInvoice::query()
                    ->where('workspace_id', $this->workspace->id)
                    ->where('client_agreement_id', $agreement->id)
                    ->where('invoice_kind', 'cadence_period')
                    ->count(),
                'The replay transaction must roll back the three generated cadence invoices.',
            );
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }
    }

    public function test_a_mid_month_monthly_start_uses_the_generators_opening_cycle_in_the_cadence_proof(): void
    {
        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Synthetic mid-month monthly retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-15',
            'retainer_minutes' => 120,
            'retainer_amount' => 25000,
            'period_retainer_minutes' => 120,
            'period_retainer_amount' => 25000,
            'hourly_rate_amount' => 15000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 0,
        ]);
        $this->adHocHistory('ADHOC-MID-MONTH-1', '2026-03-01', '2024-01-01', '2024-01-31');
        $this->adHocHistory('ADHOC-MID-MONTH-2', '2026-04-01', '2024-02-01', '2024-02-29');

        $report = tempnam(sys_get_temp_dir(), 'replay-mid-month-cadence-gap-');
        $this->assertNotFalse($report);
        try {
            $exit = Artisan::call('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--as-of' => '2026-03-15',
                '--report' => $report,
            ]);

            /** @var array{comparisons:list<array<string,mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
            $explained = array_values(array_filter(
                $detail['comparisons'],
                static fn (array $comparison): bool => $comparison['verdict'] === 'unexpected'
                    && in_array(
                        'configured_cadence_absent_from_history',
                        array_column((array) ($comparison['explained_by'] ?? []), 'key'),
                        true,
                    ),
            ));

            $this->assertSame(0, $exit, (string) json_encode($detail['comparisons']));
            $this->assertCount(4, $explained);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }
    }

    public function test_void_ad_hoc_attempts_do_not_establish_an_omitted_cadence_exception(): void
    {
        $this->cadenceGapAgreement();
        $this->adHocHistory('VOID-GAP-1', '2026-07-01', '2024-01-01', '2024-01-31');
        $this->adHocHistory('VOID-GAP-2', '2027-01-01', '2024-02-01', '2024-02-29');
        ClientInvoice::query()
            ->where('workspace_id', $this->workspace->id)
            ->whereIn('invoice_number', ['VOID-GAP-1', 'VOID-GAP-2'])
            ->update(['status' => 'void']);

        $this->artisan('svc:billing:replay', [
            '--workspace' => $this->workspace->public_id,
            '--as-of' => '2026-12-31',
        ])->assertFailed();
    }

    public function test_a_later_omitted_recurring_incidence_is_not_waived_as_the_opening_charge(): void
    {
        $item = null;
        $this->generatedHistory(function (ClientAgreement $agreement) use (&$item): void {
            $item = ClientAgreementRecurringItem::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_agreement_id' => $agreement->id,
                'description' => 'Synthetic monthly service',
                'cadence' => 'monthly',
                'anchor_day' => 10,
                'effective_on' => '2024-01-10',
                'quantity' => '1.000',
                'amount' => 4200,
                'currency' => 'USD',
                'is_active' => true,
            ]);
        });
        $this->assertInstanceOf(ClientAgreementRecurringItem::class, $item);

        $line = ClientInvoiceLine::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('client_agreement_recurring_item_id', $item->id)
            ->whereDate('line_date', '2024-02-10')
            ->firstOrFail();
        $invoice = $line->invoice()->firstOrFail();
        $line->delete();
        $invoice->recalculateTotals();

        $this->artisan('svc:billing:replay', [
            '--workspace' => $this->workspace->public_id,
        ])->assertFailed();
    }

    public function test_one_engine_only_cycle_is_not_waived_as_a_missing_historical_cadence(): void
    {
        $this->cadenceGapAgreement();
        $this->adHocHistory('ADHOC-ISOLATED-GAP', '2026-01-01', '2024-01-01', '2024-01-31');

        $this->artisan('svc:billing:replay', [
            '--workspace' => $this->workspace->public_id,
        ])->assertFailed();
    }

    public function test_a_contiguous_cadence_prefix_does_not_prove_generation_reached_the_replay_boundary(): void
    {
        $agreement = $this->cadenceGapAgreement();
        $companyId = (string) $this->company->id;
        $agreementId = (string) $agreement->id;
        $retainerLine = static fn (string $cycleStart): array => [
            'type' => 'retainer',
            'unit_amount' => 150000,
            'quantity' => '1',
            'hours' => 12.0,
            'total_amount' => 150000,
            'tax_amount' => 0,
            'agreement_id' => $agreementId,
            'recurring_item_id' => '',
            'project_id' => '',
            'claimed_by' => '',
            'canonical_cadence_description' => true,
            'source_minutes' => 0,
            'source_agreement_rate_minutes' => 0,
            'line_date' => $cycleStart,
        ];
        $expected = [
            $companyId.'|none|ad_hoc|2026-07-01..2026-12-31@2024-01-01..2024-01-31' => [
                'invoice_kind' => 'ad_hoc',
                'status' => 'draft',
            ],
        ];
        $actual = [];
        foreach ([
            ['2026-01-01', '2026-06-30', '2025-07-01', '2025-12-31'],
            ['2026-07-01', '2026-12-31', '2026-01-01', '2026-06-30'],
        ] as [$cycleStart, $cycleEnd, $periodStart, $periodEnd]) {
            $key = implode('|', [
                $companyId,
                $agreementId,
                'cadence_period',
                $cycleStart.'..'.$cycleEnd.'@'.$periodStart.'..'.$periodEnd,
            ]);
            $actual[$key] = [
                'invoice_kind' => 'cadence_period',
                'currency' => 'USD',
                'subtotal_amount' => 150000,
                'tax_amount' => 0,
                'total_amount' => 150000,
                'lines' => [$retainerLine($cycleStart)],
            ];
        }

        $classifier = new ReplayContractCorrectionClassifier;
        $anchors = [$this->company->id => Carbon::parse('2026-12-31')->endOfDay()];
        $this->assertSame([], $classifier->contractCadenceHistoryGapKeys(
            $this->workspace,
            $expected,
            $actual,
            $anchors,
        ));

        $finalKey = implode('|', [
            $companyId,
            $agreementId,
            'cadence_period',
            '2027-01-01..2027-06-30@2026-07-01..2026-12-31',
        ]);
        $actual[$finalKey] = [
            'invoice_kind' => 'cadence_period',
            'currency' => 'USD',
            'subtotal_amount' => 150000,
            'tax_amount' => 0,
            'total_amount' => 150000,
            'lines' => [$retainerLine('2027-01-01')],
        ];
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->assertCount(3, $classifier->contractCadenceHistoryGapKeys(
            $this->workspace,
            $expected,
            $actual,
            $anchors,
        ));
        $this->assertCount(2, $queries, implode("\n", $queries));

        $wrongRetainerDescription = $actual;
        $wrongRetainerDescription[$finalKey]['lines'][0]['canonical_cadence_description'] = false;
        $this->assertSame([], $classifier->contractCadenceHistoryGapKeys(
            $this->workspace,
            $expected,
            $wrongRetainerDescription,
            $anchors,
        ), 'A cadence chain with noncanonical client-facing retainer text cannot be waived.');

        $mispriced = $actual;
        $mispriced[$finalKey]['lines'][0]['unit_amount'] = 149999;
        $mispriced[$finalKey]['lines'][0]['total_amount'] = 149999;
        $mispriced[$finalKey]['subtotal_amount'] = 149999;
        $mispriced[$finalKey]['total_amount'] = 149999;
        $this->assertSame([], $classifier->contractCadenceHistoryGapKeys(
            $this->workspace,
            $expected,
            $mispriced,
            $anchors,
        ), 'A self-consistent invoice still needs the exact retainer price configured on the agreement.');

        $extraCharge = $actual;
        $extraCharge[$finalKey]['lines'][] = [
            'type' => 'additional_hours',
            'unit_amount' => 15000,
            'quantity' => '1',
            'hours' => 1.0,
            'source_minutes' => 0,
            'source_agreement_rate_minutes' => 0,
            'total_amount' => 15000,
            'tax_amount' => 0,
            'agreement_id' => $agreementId,
            'line_date' => '2026-12-31',
        ];
        $extraCharge[$finalKey]['subtotal_amount'] = 165000;
        $extraCharge[$finalKey]['total_amount'] = 165000;
        $this->assertSame([], $classifier->contractCadenceHistoryGapKeys(
            $this->workspace,
            $expected,
            $extraCharge,
            $anchors,
        ), 'A correct retainer line cannot hide an additional charge with no source work allocation.');

        $eligibleOverage = $actual;
        $eligibleOverage[$finalKey]['lines'][] = [
            'type' => 'additional_hours',
            'unit_amount' => 15000,
            'quantity' => '1',
            'hours' => 1.0,
            'source_minutes' => 60,
            'source_agreement_rate_minutes' => 60,
            'total_amount' => 15000,
            'tax_amount' => 0,
            'agreement_id' => $agreementId,
            'line_date' => '2026-12-31',
        ];
        $eligibleOverage[$finalKey]['subtotal_amount'] = 165000;
        $eligibleOverage[$finalKey]['total_amount'] = 165000;
        $this->assertCount(3, $classifier->contractCadenceHistoryGapKeys(
            $this->workspace,
            $expected,
            $eligibleOverage,
            $anchors,
        ));

        foreach ([
            'project_id' => '99',
            'recurring_item_id' => '99',
            'claimed_by' => 'synthetic-claim',
        ] as $field => $value) {
            $misownedOverage = $eligibleOverage;
            $misownedOverage[$finalKey]['lines'][1][$field] = $value;
            $this->assertSame([], $classifier->contractCadenceHistoryGapKeys(
                $this->workspace,
                $expected,
                $misownedOverage,
                $anchors,
            ), "Cadence work with {$field} attached cannot be waived.");
        }

        $ineligibleOverage = $eligibleOverage;
        $ineligibleOverage[$finalKey]['lines'][1]['source_agreement_rate_minutes'] = 0;
        $this->assertSame([], $classifier->contractCadenceHistoryGapKeys(
            $this->workspace,
            $expected,
            $ineligibleOverage,
            $anchors,
        ), 'Agreement-rate cadence cannot be proved from flat-hourly or direct source work.');

        $monthEndFutureAgreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Outside the month-end selection window',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-10-01',
            'retainer_minutes' => 60,
            'retainer_amount' => 10000,
            'billing_cadence' => 'monthly',
        ]);
        $monthEndAnchors = [$this->company->id => Carbon::parse('2026-08-31')->endOfDay()];
        $this->assertCount(3, $classifier->contractCadenceHistoryGapKeys(
            $this->workspace,
            $expected,
            $actual,
            $monthEndAnchors,
        ), 'October 1 is not within the one-month selection window from August 31.');

        $monthEndFutureAgreement->update(['starts_on' => '2026-09-30']);
        $this->assertSame([], $classifier->contractCadenceHistoryGapKeys(
            $this->workspace,
            $expected,
            $actual,
            $monthEndAnchors,
        ), 'September 30 is the inclusive month-end selection boundary.');
        $monthEndFutureAgreement->delete();

        $duplicateRetainer = $actual;
        $duplicateRetainer[$finalKey]['lines'][] = $retainerLine('2027-01-01');
        $duplicateRetainer[$finalKey]['subtotal_amount'] = 300000;
        $duplicateRetainer[$finalKey]['total_amount'] = 300000;
        $this->assertSame([], $classifier->contractCadenceHistoryGapKeys(
            $this->workspace,
            $expected,
            $duplicateRetainer,
            $anchors,
        ), 'A second retainer line cannot be waived even when both lines use the configured price.');

        foreach ([
            'project_id' => '99',
            'recurring_item_id' => '99',
            'claimed_by' => 'synthetic-claim',
            'source_minutes' => 1,
            'source_agreement_rate_minutes' => 1,
        ] as $field => $value) {
            $misattachedRetainer = $actual;
            $misattachedRetainer[$finalKey]['lines'][0][$field] = $value;
            $this->assertSame([], $classifier->contractCadenceHistoryGapKeys(
                $this->workspace,
                $expected,
                $misattachedRetainer,
                $anchors,
            ), "A cadence retainer with {$field} attached cannot be waived.");
        }

        $unsupportedCapacity = $actual;
        $unsupportedCapacity[$finalKey]['lines'][] = [
            'type' => 'prior_month_retainer',
            'unit_amount' => 0,
            'quantity' => '0',
            'hours' => 1.0,
            'source_minutes' => 0,
            'total_amount' => 0,
            'tax_amount' => 0,
            'agreement_id' => $agreementId,
            'line_date' => '2026-12-31',
        ];
        $this->assertSame([], $classifier->contractCadenceHistoryGapKeys(
            $this->workspace,
            $expected,
            $unsupportedCapacity,
            $anchors,
        ), 'A zero-value capacity line still needs a matching source-work allocation.');

        $agreement->update(['ends_on' => '2026-10-15']);
        $cadenceLinesProof = new ReflectionMethod(
            ReplayContractCorrectionClassifier::class,
            'hasOnlyConfiguredCadenceLines',
        );
        $this->assertFalse($cadenceLinesProof->invoke(
            $classifier,
            $agreement,
            Carbon::parse('2027-01-01'),
            Carbon::parse('2027-06-30'),
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-12-31'),
            [$retainerLine('2027-01-01')],
        ), 'The line proof itself must reject a retainer after entitlement ended.');
        $this->assertSame([], $classifier->contractCadenceHistoryGapKeys(
            $this->workspace,
            $expected,
            $actual,
            $anchors,
        ), 'A post-termination cycle with no retainer due cannot contain a retainer line.');

        $terminatedActual = $actual;
        $monthStarts = array_map(
            static fn (int $month): Carbon => Carbon::create(2026, $month, 1)->startOfDay(),
            range(7, 12),
        );
        $calculator = new RetainerCalculator;
        $terminatedHours = array_sum(array_map(
            static fn (Carbon $monthStart): float => $calculator->retainerHoursForMonth(
                $agreement,
                $monthStart,
                $monthStart->copy()->endOfMonth()->startOfDay(),
            ),
            $monthStarts,
        ));
        $terminatedCycle = new BillingCycle(
            start: Carbon::parse('2026-07-01'),
            end: Carbon::parse('2026-12-31'),
            isProrated: false,
            monthCount: 6,
            monthStarts: $monthStarts,
        );
        $terminatedFee = (int) round($calculator->cycleRetainerFee($agreement, $terminatedCycle, [
            'retainer_multiplier' => $terminatedHours / $agreement->monthly_retainer_hours,
        ]) * 100);
        $terminatedRetainer = $retainerLine('2026-07-01');
        $terminatedRetainer['unit_amount'] = $terminatedFee;
        $terminatedRetainer['total_amount'] = $terminatedFee;
        $terminatedRetainer['hours'] = $terminatedHours;
        $terminatedActual[array_keys($terminatedActual)[1]]['subtotal_amount'] = $terminatedFee;
        $terminatedActual[array_keys($terminatedActual)[1]]['total_amount'] = $terminatedFee;
        $terminatedActual[array_keys($terminatedActual)[1]]['lines'] = [$terminatedRetainer];
        $terminatedActual[$finalKey] = [
            'invoice_kind' => 'cadence_period',
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'lines' => [],
        ];
        $this->assertCount(3, $classifier->contractCadenceHistoryGapKeys(
            $this->workspace,
            $expected,
            $terminatedActual,
            $anchors,
        ), 'A complete terminated chain ends at the successor of the cycle containing termination, not the replay anchor.');
        $agreement->update(['ends_on' => null]);

        ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Second eligible cadence',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 60,
            'retainer_amount' => 10000,
            'billing_cadence' => 'monthly',
        ]);
        $this->assertSame([], $classifier->contractCadenceHistoryGapKeys(
            $this->workspace,
            $expected,
            $actual,
            $anchors,
        ), 'Every eligible recurring agreement must be represented before an omitted cadence is explained.');

        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other cadence proof',
            'slug' => 'other-cadence-proof',
        ]);
        $this->assertSame([], $classifier->contractCadenceHistoryGapKeys(
            $otherWorkspace,
            $expected,
            $actual,
            $anchors,
        ), 'The cadence proof must not resolve a company by numeric id outside the selected workspace.');
    }

    public function test_recurring_item_proof_cannot_resolve_a_company_from_another_workspace(): void
    {
        $foreignWorkspace = Workspace::query()->create(['name' => 'Foreign proof', 'slug' => 'foreign-proof']);
        $foreignCompany = ClientCompany::query()->create([
            'workspace_id' => $foreignWorkspace->id,
            'name' => 'Foreign proof client',
            'slug' => 'foreign-proof-client',
        ]);
        $foreignAgreement = ClientAgreement::query()->create([
            'workspace_id' => $foreignWorkspace->id,
            'client_company_id' => $foreignCompany->id,
            'title' => 'Foreign proof agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'billing_cadence' => 'monthly',
        ]);
        $foreignItem = ClientAgreementRecurringItem::query()->create([
            'workspace_id' => $foreignWorkspace->id,
            'client_agreement_id' => $foreignAgreement->id,
            'description' => 'Foreign opening service',
            'cadence' => 'annual',
            'anchor_month' => 1,
            'anchor_day' => 1,
            'effective_on' => '2026-01-01',
            'quantity' => '1.000',
            'amount' => 4200,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $key = implode('|', [
            $foreignCompany->id,
            $foreignAgreement->id,
            'cadence_period',
            '2026-01-01..2026-01-31@2025-12-01..2025-12-31',
        ]);
        $before = [
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'lines' => [],
        ];
        $after = [
            'currency' => 'USD',
            'subtotal_amount' => 4200,
            'tax_amount' => 0,
            'total_amount' => 4200,
            'cycle_start' => '2026-01-01',
            'cycle_end' => '2026-01-31',
            'lines' => [[
                'type' => 'recurring_item',
                'unit_amount' => 4200,
                'quantity' => '1',
                'tax_amount' => 0,
                'total_amount' => 4200,
                'project_id' => '',
                'agreement_id' => (string) $foreignAgreement->id,
                'line_date' => '2026-01-01',
                'recurring_item_id' => (string) $foreignItem->id,
                'claimed_by' => '',
                'description_hash' => 'foreign-proof',
                'source_minutes' => 0,
                'source_agreement_rate_minutes' => 0,
            ]],
        ];

        $incidences = app(ReplayRecurringItemIncidenceRepository::class)
            ->forSnapshots($this->workspace, [$key => $after], 'replay-test-digest');
        $this->assertSame([], $incidences);
        $this->assertFalse((new ReplayContractCorrectionClassifier)->openingRecurringItemIncidence(
            $key,
            $before,
            $after,
            $incidences[$key] ?? [],
        ));
    }

    public function test_recurring_item_proof_cannot_follow_a_corrupt_item_workspace_chain(): void
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Local proof agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'billing_cadence' => 'monthly',
        ]);
        $foreignWorkspace = Workspace::query()->create(['name' => 'Foreign item scope', 'slug' => 'foreign-item-scope']);
        $foreignItem = ClientAgreementRecurringItem::query()->create([
            'workspace_id' => $foreignWorkspace->id,
            // The schema currently permits this mismatched pair of independent
            // foreign keys. Replay must still fail closed at the query seam.
            'client_agreement_id' => $agreement->id,
            'description' => 'Foreign chained service',
            'cadence' => 'annual',
            'anchor_month' => 1,
            'anchor_day' => 1,
            'effective_on' => '2026-01-01',
            'quantity' => '1.000',
            'amount' => 4200,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $key = implode('|', [
            $this->company->id,
            $agreement->id,
            'cadence_period',
            '2026-01-01..2026-01-31@2025-12-01..2025-12-31',
        ]);
        $before = [
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'lines' => [],
        ];
        $after = [
            'currency' => 'USD',
            'subtotal_amount' => 4200,
            'tax_amount' => 0,
            'total_amount' => 4200,
            'cycle_start' => '2026-01-01',
            'cycle_end' => '2026-01-31',
            'lines' => [[
                'type' => 'recurring_item',
                'unit_amount' => 4200,
                'quantity' => '1',
                'tax_amount' => 0,
                'total_amount' => 4200,
                'project_id' => '',
                'agreement_id' => (string) $agreement->id,
                'line_date' => '2026-01-01',
                'recurring_item_id' => (string) $foreignItem->id,
                'claimed_by' => '',
                'description_hash' => 'foreign-chain',
                'source_minutes' => 0,
                'source_agreement_rate_minutes' => 0,
            ]],
        ];

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'client_agreement_recurring_items')) {
                $queries[] = $query->sql;
            }
        });
        $incidences = app(ReplayRecurringItemIncidenceRepository::class)
            ->forSnapshots($this->workspace, [$key => $after], 'replay-test-digest');
        $this->assertFalse((new ReplayContractCorrectionClassifier)->openingRecurringItemIncidence(
            $key,
            $before,
            $after,
            $incidences[$key] ?? [],
        ));
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('workspace_id', $queries[0]);

        $localItem = ClientAgreementRecurringItem::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_agreement_id' => $agreement->id,
            'description' => 'Local chained service',
            'cadence' => 'annual',
            'anchor_month' => 1,
            'anchor_day' => 1,
            'effective_on' => '2026-01-01',
            'quantity' => '1.000',
            'amount' => 4200,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $after['lines'][0]['recurring_item_id'] = (string) $localItem->id;
        $queries = [];
        $incidences = app(ReplayRecurringItemIncidenceRepository::class)
            ->forSnapshots($this->workspace, [$key => $after], 'replay-test-digest');
        $after['lines'][0]['description_hash'] = $incidences[$key][0]->descriptionHash;
        $this->assertTrue((new ReplayContractCorrectionClassifier)->openingRecurringItemIncidence(
            $key,
            $before,
            $after,
            $incidences[$key] ?? [],
        ));
        $this->assertCount(1, $queries);
        foreach ($queries as $query) {
            $this->assertStringContainsString('workspace_id', $query);
        }

        $after['lines'][0]['source_minutes'] = 15;
        $after['lines'][0]['source_agreement_rate_minutes'] = 15;
        $this->assertFalse((new ReplayContractCorrectionClassifier)->openingRecurringItemIncidence(
            $key,
            $before,
            $after,
            $incidences[$key] ?? [],
        ));
    }

    public function test_recurring_item_proof_contexts_use_two_queries_for_many_agreements(): void
    {
        $snapshots = [];
        foreach (range(1, 3) as $ordinal) {
            $agreement = ClientAgreement::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'title' => 'Bounded recurring proof '.$ordinal,
                'status' => 'active',
                'currency' => 'USD',
                'starts_on' => '2026-01-01',
                'retainer_minutes' => 600,
                'retainer_amount' => 150000,
                'billing_cadence' => 'monthly',
            ]);
            ClientAgreementRecurringItem::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_agreement_id' => $agreement->id,
                'description' => 'Synthetic bounded service '.$ordinal,
                'cadence' => 'one_time',
                'anchor_month' => 1,
                'anchor_day' => 1,
                'effective_on' => '2026-01-01',
                'quantity' => '1.000',
                'amount' => 4200,
                'currency' => 'USD',
                'is_active' => true,
            ]);
            $key = implode('|', [
                $this->company->id,
                $agreement->id,
                'cadence_period',
                '2026-01-01..2026-01-31@2025-12-01..2025-12-31',
            ]);
            $snapshots[$key] = [
                'cycle_start' => '2026-01-01',
                'cycle_end' => '2026-01-31',
            ];
        }

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $incidences = app(ReplayRecurringItemIncidenceRepository::class)
            ->forSnapshots($this->workspace, $snapshots, 'replay-test-digest');

        $this->assertCount(2, $queries, implode("\n", $queries));
        $this->assertCount(3, $incidences);
        foreach ($incidences as $key => $proofs) {
            $this->assertCount(1, $proofs);
            $proof = $proofs[0];
            $before = [
                'currency' => 'USD',
                'subtotal_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'lines' => [],
            ];
            $after = [
                ...$snapshots[$key],
                'currency' => 'USD',
                'subtotal_amount' => $proof->totalAmount,
                'tax_amount' => 0,
                'total_amount' => $proof->totalAmount,
                'lines' => [[
                    'type' => 'recurring_item',
                    'unit_amount' => $proof->unitAmount,
                    'quantity' => $proof->quantity,
                    'tax_amount' => $proof->taxAmount,
                    'total_amount' => $proof->totalAmount,
                    'project_id' => '',
                    'agreement_id' => (string) $proof->agreementId,
                    'line_date' => $proof->lineDate,
                    'recurring_item_id' => (string) $proof->itemId,
                    'claimed_by' => '',
                    'description_hash' => $proof->descriptionHash,
                    'source_minutes' => 0,
                    'source_agreement_rate_minutes' => 0,
                ]],
            ];
            $this->assertTrue((new ReplayContractCorrectionClassifier)->openingRecurringItemIncidence(
                $key,
                $before,
                $after,
                $proofs,
            ));
        }
        $this->assertCount(2, $queries, 'Classification must remain database-free.');
    }

    /**
     * The harness must fail when history and the engine disagree. Editing a
     * stored total stands in for the engine having drifted.
     */
    public function test_it_fails_when_a_stored_invoice_no_longer_reproduces(): void
    {
        $invoice = $this->generatedHistory();

        // History says this invoice was worth 1.00 more than the engine now says.
        $invoice->forceFill(['total_amount' => (int) $invoice->total_amount + 100])->save();

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])
            ->assertFailed();
    }

    /**
     * The command claims money is exact to the cent. Comparing totals per type
     * cannot deliver that: a line repriced with a compensating quantity reaches
     * the same total, the same per-type total and the same line count, so every
     * aggregate agrees while the client was charged for something else.
     */
    public function test_a_repriced_line_is_not_reported_as_an_exact_reproduction(): void
    {
        $invoice = $this->generatedHistory();

        /** @var ClientInvoiceLine $line */
        $line = $invoice->lines()->orderByDesc('total_amount')->firstOrFail();
        $unit = (int) $line->unit_amount;
        $this->assertSame(0, $unit % 2, 'This fixture needs an even unit amount to halve.');

        // Half the price, twice as many. Total unchanged, so nothing an
        // aggregate looks at moves.
        $line->forceFill([
            'unit_amount' => intdiv($unit, 2),
            'quantity' => (float) $line->quantity * 2,
        ])->save();

        // tempnam() creates the file it names, so use that path rather than a
        // suffixed sibling - otherwise every run leaves the original behind.
        $report = tempnam(sys_get_temp_dir(), 'svc-replay-');

        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->assertFailed();

            /** @var array{comparisons: list<array<string, mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        $verdicts = array_column($detail['comparisons'], 'verdict', 'invoice_number');

        // Every aggregate agrees on this invoice, so the old comparison called
        // it an exact reproduction. The individual lines are what disagree.
        $this->assertArrayHasKey((string) $invoice->invoice_number, $verdicts);
        // A repriced line is a money difference, not an arrangement: the client
        // was charged for something they were not charged for. It gates.
        $this->assertSame('money_differs', $verdicts[(string) $invoice->invoice_number]);
    }

    /**
     * The deliberate corrections say which line types a period should carry.
     * They say nothing about what a line of that type costs, so a repriced line
     * must not be waived by the correction that explains why its type moved.
     */
    public function test_a_repriced_line_is_not_waived_by_a_correction_that_covers_its_type(): void
    {
        // additional_hours is capacity-dependent, so this is the invoice a
        // correction could otherwise excuse - and it is charged for, so a
        // question about its money means something.
        [$overage] = $this->chargedOverageHistory();
        $invoice = $overage->invoice()->firstOrFail();

        // Only the unit price moves; the invoice total is untouched, so nothing
        // above the line level has anything to notice.
        $overage->forceFill(['unit_amount' => (int) $overage->unit_amount + 5000])->save();

        $row = $this->comparisonFor((string) $invoice->invoice_number);

        $this->assertNotNull($row);
        $this->assertSame('money_differs', $row['verdict']);
        $this->assertSame(0, $row['money_delta']);
        $this->assertNull($row['explained_by'] ?? null);
    }

    /**
     * A line the engine no longer produces is a composition change, and
     * composition is what the four deliberate corrections exist to explain. It
     * must not be mistaken for a repricing, which is never explainable.
     */
    public function test_a_line_the_engine_no_longer_produces_is_not_read_as_a_repricing(): void
    {
        $this->generatedHistory();

        /** @var ClientInvoiceLine $line */
        $line = ClientInvoiceLine::query()->where('workspace_id', $this->workspace->id)->where('type', 'prior_month_retainer')->firstOrFail();

        // History carried this charge twice; the engine produces it once. Every
        // amount is identical, so nothing about what the client pays moved.
        $duplicate = $line->replicate();
        $duplicate->public_id = (string) Str::uuid();
        $duplicate->sort_order = (int) $line->sort_order + 1;
        $duplicate->save();

        $invoice = $line->invoice()->firstOrFail();
        $report = tempnam(sys_get_temp_dir(), 'svc-replay-');

        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->run();

            /** @var array{comparisons: list<array<string, mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        $rows = array_column($detail['comparisons'], null, 'invoice_number');
        $row = $rows[(string) $invoice->invoice_number] ?? null;

        $this->assertNotNull($row);
        // Comparing prices as a multiset would call one-of-these-instead-of-two
        // a money difference, and no correction could ever explain it.
        $this->assertSame('composition_differs', $row['verdict']);
    }

    /**
     * The composer writes amounts into its own wording, so a repriced line
     * arrives with a different description. If the description identified the
     * charge, the repricing would read as one line removed and another added -
     * composition, which a correction is allowed to waive.
     */
    public function test_a_repricing_hidden_by_its_own_description_still_gates(): void
    {
        [, $retainer] = $this->chargedOverageHistory();
        $invoice = $retainer->invoice()->firstOrFail();
        $this->assertMatchesRegularExpression('/\d+:\d{2}/', (string) $retainer->description, 'This fixture needs an amount-bearing description.');

        // History priced this charge differently, and its wording quotes the
        // hours it was priced from - exactly what the composer would have
        // written for them.
        $retainer->forceFill([
            'unit_amount' => (int) $retainer->unit_amount + 1000,
            'description' => (string) preg_replace('/\d+:\d{2}/', '99:00', (string) $retainer->description),
        ])->save();

        $row = $this->comparisonFor((string) $invoice->invoice_number);

        $this->assertNotNull($row);
        $this->assertSame('money_differs', $row['verdict']);
        $this->assertNull($row['explained_by'] ?? null);
    }

    /**
     * Charges are paired by what their wording says they are for, with only the
     * amounts a composer writes into that wording removed. Removing every digit
     * instead would make "Phase 1" and "Phase 2" one charge - and two charges
     * that exchange prices leave every total, count and distinct amount
     * unchanged, so pairing is the only thing that can see the swap.
     */
    public function test_charge_wording_keeps_the_numbers_that_are_not_amounts(): void
    {
        $withoutAmounts = new ReflectionMethod(ReplayInvoicesCommand::class, 'withoutAmounts');

        $phaseOne = (string) $withoutAmounts->invoke(null, 'Milestone: Phase 1', 'milestone');
        $phaseTwo = (string) $withoutAmounts->invoke(null, 'Milestone: Phase 2', 'milestone');
        $this->assertNotSame($phaseOne, $phaseTwo);

        // A milestone title is user text appended whole, so a figure in it
        // names the charge rather than pricing it. Nothing outside the
        // descriptions the billing services generate is normalised - including
        // a title that happens to end in a parenthetical of its own.
        $this->assertNotSame(
            (string) $withoutAmounts->invoke(null, 'Milestone: Package $100', 'milestone'),
            (string) $withoutAmounts->invoke(null, 'Milestone: Package $200', 'milestone'),
        );
        $this->assertNotSame(
            (string) $withoutAmounts->invoke(null, 'Milestone: Package ($100)', 'milestone'),
            (string) $withoutAmounts->invoke(null, 'Milestone: Package ($200)', 'milestone'),
        );

        // A title can read exactly like a generated retainer line. The type is
        // what says whether the billing services wrote it.
        $this->assertNotSame(
            (string) $withoutAmounts->invoke(null, 'Milestone: Monthly Retainer (10 hours) - Feb 1, 2024 through Feb 29, 2024', 'milestone'),
            (string) $withoutAmounts->invoke(null, 'Milestone: Monthly Retainer (20 hours) - Feb 1, 2024 through Feb 29, 2024', 'milestone'),
        );

        // A worker's name is user text too, and it sits before the generated
        // suffix rather than after it. Only the last group is the amount.
        $this->assertNotSame(
            (string) $withoutAmounts->invoke(null, 'Subcontractor: Alex (Senior) (1:00 @ 60.00 USD/hr)', 'subcontractor'),
            (string) $withoutAmounts->invoke(null, 'Subcontractor: Alex (Junior) (1:00 @ 60.00 USD/hr)', 'subcontractor'),
        );
        $this->assertSame(
            (string) $withoutAmounts->invoke(null, 'Subcontractor: Alex (Senior) (1:00 @ 60.00 USD/hr)', 'subcontractor'),
            (string) $withoutAmounts->invoke(null, 'Subcontractor: Alex (Senior) (2:00 @ 90.00 USD/hr)', 'subcontractor'),
        );

        // And a generated line keeps everything that names its cycle while
        // losing the hours it was priced from.
        $this->assertSame(
            (string) $withoutAmounts->invoke(null, 'Monthly Retainer (10 hours) - Feb 1, 2024 through Feb 29, 2024', 'retainer'),
            (string) $withoutAmounts->invoke(null, 'Monthly Retainer (99 hours) - Feb 1, 2024 through Feb 29, 2024', 'retainer'),
        );
        $this->assertNotSame(
            (string) $withoutAmounts->invoke(null, 'Monthly Retainer (10 hours) - Feb 1, 2024 through Feb 29, 2024', 'retainer'),
            (string) $withoutAmounts->invoke(null, 'Monthly Retainer (10 hours) - Mar 1, 2024 through Mar 31, 2024', 'retainer'),
        );

        // The amounts, though, have to go: the composer writes them from the
        // very price and quantity the comparison exists to detect.
        $this->assertSame(
            (string) $withoutAmounts->invoke(null, 'Deferred work items billed on agreement termination (12:30 @ 150.00 USD/hr)', 'additional_hours'),
            (string) $withoutAmounts->invoke(null, 'Deferred work items billed on agreement termination (99:00 @ 9.00 USD/hr)', 'additional_hours'),
        );
        $this->assertSame(
            (string) $withoutAmounts->invoke(null, 'Work items applied to retainer (15:00 applied to February 2024 pool)', 'prior_month_retainer'),
            (string) $withoutAmounts->invoke(null, 'Work items applied to retainer (2:30 applied to February 2024 pool)', 'prior_month_retainer'),
        );
    }

    /**
     * Two charges exchanging prices leaves the invoice total, the line count
     * and the set of distinct amounts all unchanged. Nothing that looks at the
     * invoice as a whole can see it; only pairing each charge to its own
     * counterpart can.
     */
    public function test_two_charges_that_swap_prices_are_not_a_match(): void
    {
        [$overage, $retainer] = $this->chargedOverageHistory();
        $invoice = $overage->invoice()->firstOrFail();
        $this->assertNotSame((int) $overage->total_amount, (int) $retainer->total_amount);

        // The two charges exchanged prices. Every total, every count and every
        // amount the invoice states is unchanged.
        $carry = ['unit_amount' => (int) $overage->unit_amount, 'quantity' => (string) $overage->quantity, 'total_amount' => (int) $overage->total_amount];
        $overage->forceFill(['unit_amount' => (int) $retainer->unit_amount, 'quantity' => (string) $retainer->quantity, 'total_amount' => (int) $retainer->total_amount])->save();
        $retainer->forceFill($carry)->save();

        $this->assertSame('money_differs', $this->verdictFor((string) $invoice->invoice_number));
    }

    /**
     * A charge whose wording changes along with its price has no counterpart to
     * be paired with, so pairing cannot see the repricing. What gives it away
     * is that the invoice now states an amount it did not state before.
     */
    public function test_a_repricing_that_takes_its_wording_with_it_still_gates(): void
    {
        $this->generatedHistory();

        /** @var ClientInvoiceLine $line */
        $line = ClientInvoiceLine::query()->where('workspace_id', $this->workspace->id)->orderByDesc('total_amount')->firstOrFail();

        // Nothing recognisable survives to pair on, and the invoice total is
        // untouched, so only the amounts themselves say anything happened.
        $line->forceFill([
            'description' => 'An entirely different charge',
            'unit_amount' => (int) $line->unit_amount + 700,
        ])->save();

        $this->assertSame('money_differs', $this->verdictFor($line->invoice()->firstOrFail()->invoice_number));
    }

    /**
     * A line the engine no longer produces, priced at an amount that appears
     * nowhere else, is still a line removed. Comparing the amounts an invoice
     * states must not read that as a repricing, or the corrections that exist
     * to remove exactly such a line could never explain one.
     */
    public function test_removing_a_uniquely_priced_line_stays_explainable(): void
    {
        $this->generatedHistory();

        /** @var ClientInvoiceLine $line */
        $line = ClientInvoiceLine::query()->where('workspace_id', $this->workspace->id)->where('type', 'prior_month_retainer')->firstOrFail();
        $invoiceNumber = (string) $line->invoice()->firstOrFail()->invoice_number;

        // History carried one more charge than the engine produces, at a price
        // no other line on the invoice states.
        $extra = $line->replicate();
        $extra->public_id = (string) Str::uuid();
        $extra->sort_order = (int) $line->sort_order + 1;
        $extra->unit_amount = 1234;
        $extra->quantity = '1.0000';
        $extra->total_amount = 0;
        $extra->description = 'A charge the engine no longer makes';
        $extra->save();

        // Reported, not gated: the money the client owes is unchanged and a
        // correction is allowed to account for the line being gone.
        $this->assertSame('composition_differs', $this->verdictFor($invoiceNumber));
    }

    /**
     * A charge repriced onto an amount the invoice already states changes only
     * how many times that amount appears. No total moves, no line count moves,
     * and if the wording moved with the price there is nothing to pair either -
     * one count falling while another rises is the whole signal.
     */
    public function test_a_repricing_onto_an_existing_amount_still_gates(): void
    {
        [$overage, $retainer] = $this->chargedOverageHistory();
        $invoice = $overage->invoice()->firstOrFail();

        // History priced this charge exactly like the other one and worded it
        // like nothing the engine writes, so pairing has no counterpart and
        // only the count of that amount says anything happened.
        $overage->forceFill([
            'description' => 'A charge worded nothing like the engine words it',
            'unit_amount' => (int) $retainer->unit_amount,
            'quantity' => (string) $retainer->quantity,
            'total_amount' => (int) $overage->total_amount,
        ])->save();

        $this->assertSame('money_differs', $this->verdictFor((string) $invoice->invoice_number));
    }

    /**
     * A correction that removes one charge and adds another moves money in
     * aggregate without repricing anything. The money must still be reported,
     * but the correction has to remain able to account for it - otherwise the
     * corrections this port makes on purpose can never explain their own work.
     */
    public function test_a_charge_replaced_by_another_stays_explainable(): void
    {
        [$overage] = $this->chargedOverageHistory();
        $invoice = $overage->invoice()->firstOrFail();

        // Different wording and different amounts: nothing to pair against, so
        // this is one charge gone and another arrived rather than a repricing.
        $overage->forceFill([
            'description' => 'A charge the engine replaced with a different one',
            'unit_amount' => 2500,
            'quantity' => '1.0000',
            'total_amount' => 2500,
        ])->save();

        $row = $this->comparisonFor((string) $invoice->invoice_number);

        $this->assertNotNull($row);
        $this->assertFalse($row['line_repriced'], 'Nothing was repriced; a charge was replaced.');
        // Refusing attribution on any line-money movement would make
        // composition permanently unexplainable, which is the opposite of what
        // the deliberate corrections are for.
        $this->assertNotNull($row['explained_by'] ?? null);
    }

    /**
     * One worker on two projects gets one line per project, worded identically.
     * Exchanging their prices moves no total, no count, and no amount the
     * invoice states - only which project each is filed under separates them,
     * so the pairing has to see the project before it falls back to wording.
     */
    public function test_two_concurrent_charges_that_swap_prices_are_not_a_match(): void
    {
        $here = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Here',
        ]);
        $there = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'There',
        ]);

        // One worker, two projects, two different rates. The composer writes a
        // line per project, worded identically because the wording names the
        // worker rather than the project.
        foreach ([[$here, 6000], [$there, 9000]] as [$project, $rate]) {
            ClientTimeEntry::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'client_project_id' => $project->id,
                'user_id' => $this->user->id,
                'worked_on' => '2024-02-14',
                'minutes' => 60,
                'description' => 'Subcontracted work',
                'is_billable' => true,
                'is_deferred' => false,
                'status' => 'approved',
                'currency' => 'USD',
                'subcontractor_billing_mode' => 'flat_hourly',
                'subcontractor_cost_amount' => $rate,
                'subcontractor_cost_currency' => 'USD',
            ]);
        }

        $this->generatedHistory();

        $subcontracted = ClientInvoiceLine::query()->where('workspace_id', $this->workspace->id)->where('type', 'subcontractor')->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $subcontracted->count(), 'The fixture must produce two concurrent subcontractor lines.');

        /** @var ClientInvoiceLine $a */
        $a = $subcontracted->firstOrFail();
        /** @var ClientInvoiceLine $b */
        $b = $subcontracted->last();
        // Their wording differs only by the rate it quotes, so once the amounts
        // are taken out they name the same charge - which is the whole point:
        // only the project tells these two apart.
        $withoutAmounts = new ReflectionMethod(ReplayInvoicesCommand::class, 'withoutAmounts');
        $this->assertSame(
            (string) $withoutAmounts->invoke(null, (string) $a->description, (string) $a->type),
            (string) $withoutAmounts->invoke(null, (string) $b->description, (string) $b->type),
        );
        $this->assertNotSame((int) $a->unit_amount, (int) $b->unit_amount);

        // History charged each project at the other's rate, wording and all.
        // Every total, every count and every amount the invoice states is
        // unchanged; only which project paid which rate moved.
        $carry = ['unit_amount' => (int) $a->unit_amount, 'total_amount' => (int) $a->total_amount, 'description' => (string) $a->description];
        $a->forceFill(['unit_amount' => (int) $b->unit_amount, 'total_amount' => (int) $b->total_amount, 'description' => (string) $b->description])->save();
        $b->forceFill($carry)->save();

        $this->assertSame('money_differs', $this->verdictFor($a->invoice()->firstOrFail()->invoice_number));
    }

    /**
     * A charge that moves project and changes price at the same time. Filing
     * the move under attribution must not take the repricing with it.
     */
    /**
     * Two concurrent charges that both move and exchange prices. Nothing links
     * which became which, so pairing cannot decide - and where it cannot
     * decide it must not certify, or a repricing passes as a pair of moves.
     */
    public function test_two_concurrent_charges_that_both_move_and_swap_are_not_certified(): void
    {
        $here = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Here',
        ]);
        $there = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'There',
        ]);

        foreach ([[$here, 6000], [$there, 9000]] as [$project, $rate]) {
            ClientTimeEntry::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'client_project_id' => $project->id,
                'user_id' => $this->user->id,
                'worked_on' => '2024-02-14',
                'minutes' => 60,
                'description' => 'Subcontracted work',
                'is_billable' => true,
                'is_deferred' => false,
                'status' => 'approved',
                'currency' => 'USD',
                'subcontractor_billing_mode' => 'flat_hourly',
                'subcontractor_cost_amount' => $rate,
                'subcontractor_cost_currency' => 'USD',
            ]);
        }

        $this->generatedHistory();

        $subcontracted = ClientInvoiceLine::query()->where('workspace_id', $this->workspace->id)->where('type', 'subcontractor')->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $subcontracted->count(), 'The fixture must produce two concurrent subcontractor lines.');

        $elsewhere = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Elsewhere',
        ]);
        $beyond = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Beyond',
        ]);

        /** @var ClientInvoiceLine $a */
        $a = $subcontracted->firstOrFail();
        /** @var ClientInvoiceLine $b */
        $b = $subcontracted->last();

        // Both filed somewhere else than the engine files them, and their
        // prices exchanged. The first pass can pair neither, and under the
        // second they are one identity carrying the same two prices.
        $carry = ['unit_amount' => (int) $a->unit_amount, 'total_amount' => (int) $a->total_amount, 'description' => (string) $a->description];
        $a->forceFill(['client_project_id' => $elsewhere->id, 'unit_amount' => (int) $b->unit_amount,
            'total_amount' => (int) $b->total_amount, 'description' => (string) $b->description])->save();
        $b->forceFill(['client_project_id' => $beyond->id] + $carry)->save();

        $this->assertSame('money_differs', $this->verdictFor($a->invoice()->firstOrFail()->invoice_number));
    }

    /**
     * Which recurring item a line belongs to is filing, like its project or its
     * date. A charge that moves between items while its price changes must
     * still be recognised as the same charge repriced - moving between items is
     * exactly what the recurring-item correction is about, so letting the move
     * hide the repricing would hand it the one thing it must never waive.
     */
    public function test_a_charge_that_moves_between_recurring_items_is_not_filed_as_a_move(): void
    {
        $invoice = $this->generatedHistory();
        $agreement = ClientAgreement::query()->where('workspace_id', $this->workspace->id)->firstOrFail();

        $item = ClientAgreementRecurringItem::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_agreement_id' => $agreement->id,
            'description' => 'Somewhere to move to',
            'cadence' => 'monthly',
            'quantity' => '1.0000',
            'amount' => 1000,
            'currency' => 'USD',
            'is_active' => false,
        ]);

        /** @var ClientInvoiceLine $line */
        $line = ClientInvoiceLine::query()->where('workspace_id', $this->workspace->id)->where('client_invoice_id', $invoice->id)
            ->orderByDesc('total_amount')->firstOrFail();
        $unit = (int) $line->unit_amount;
        $this->assertSame(0, $unit % 2, 'This fixture needs an even unit amount to halve.');

        // Filed under a recurring item the engine does not use, at half the
        // price and twice the quantity, so the invoice total never moves.
        $line->forceFill([
            'client_agreement_recurring_item_id' => $item->id,
            'unit_amount' => intdiv($unit, 2),
            'quantity' => (string) ((float) $line->quantity * 2),
        ])->save();

        $report = tempnam(sys_get_temp_dir(), 'svc-replay-');

        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->run();

            /** @var array{comparisons: list<array<string, mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        $row = array_column($detail['comparisons'], null, 'invoice_number')[(string) $invoice->invoice_number] ?? null;

        $this->assertNotNull($row);
        $this->assertTrue($row['line_repriced'], 'The move must not absorb the repricing.');
        $this->assertNull($row['explained_by'] ?? null);
    }

    /**
     * A charge misfiled onto a key the other side already uses. Dropping every
     * line that shares a key would take the other side's genuine line out with
     * it, and both would leave the comparison together.
     */
    public function test_a_charge_misfiled_onto_an_occupied_key_is_still_paired(): void
    {
        [$a, $b] = $this->twoConcurrentSubcontractorCharges();

        // History filed this charge under the other project - where a charge
        // already sits - and repriced it to match.
        $a->forceFill([
            'client_project_id' => $b->client_project_id,
            'unit_amount' => (int) $b->unit_amount,
            'total_amount' => (int) $b->total_amount,
            'description' => (string) $b->description,
        ])->save();

        $row = $this->comparisonFor((string) $a->invoice()->firstOrFail()->invoice_number);

        $this->assertNotNull($row);
        $this->assertTrue($row['line_repriced'], 'The collision must not take the genuine line out of the pairing.');
    }

    /**
     * A line stores its own agreement, restored per source row, so it need not
     * match the invoice's. Reattributing a charge to another agreement changes
     * nothing else about it and must not read as an exact reproduction.
     */
    public function test_a_line_reattributed_to_another_agreement_is_not_a_match(): void
    {
        $invoice = $this->generatedHistory();

        $other = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Another agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2024-01-01',
            'retainer_minutes' => 0,
            'retainer_amount' => 0,
            'hourly_rate_amount' => 20000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 0,
        ]);

        ClientInvoiceLine::query()->where('workspace_id', $this->workspace->id)->where('client_invoice_id', $invoice->id)
            ->orderByDesc('total_amount')->firstOrFail()
            ->forceFill(['client_agreement_id' => $other->id])->save();

        $this->assertSame('composition_differs', $this->verdictFor((string) $invoice->invoice_number));
    }

    /**
     * A charge that moves onto an occupied key while keeping its own price. The
     * destination key gains a price without anything being repriced, so
     * comparing the two price sets whole calls it a repricing; matching the
     * occurrences first leaves only the move.
     */
    public function test_a_charge_that_moves_without_repricing_stays_composition(): void
    {
        [$a, $b] = $this->twoConcurrentSubcontractorCharges();

        // Filed under the other project, at its own price. Every amount the
        // invoice states is unchanged; only where one charge sits moved.
        $a->forceFill(['client_project_id' => $b->client_project_id])->save();

        $row = $this->comparisonFor((string) $a->invoice()->firstOrFail()->invoice_number);

        $this->assertNotNull($row);
        $this->assertFalse($row['line_repriced'], 'Nothing was repriced; a charge moved.');
        $this->assertSame('composition_differs', $row['verdict']);
    }

    /**
     * Two charges collapsed onto one key and both repriced. The key is shared,
     * and each side is left holding a price the other does not state - which is
     * the first pass's whole question, asked of two charges at once.
     */
    public function test_two_charges_filed_onto_one_key_and_repriced_are_caught(): void
    {
        [$a, $b] = $this->twoConcurrentSubcontractorCharges();

        // Both filed where the second sits, and both repriced to figures the
        // engine never produces.
        $a->forceFill(['client_project_id' => $b->client_project_id, 'unit_amount' => 1111, 'total_amount' => 1111])->save();
        $b->forceFill(['unit_amount' => 2222, 'total_amount' => 2222])->save();

        $row = $this->comparisonFor((string) $a->invoice()->firstOrFail()->invoice_number);

        $this->assertNotNull($row);
        $this->assertTrue($row['line_repriced']);
    }

    /**
     * Two charges under one filing at one price, one of which the engine
     * prices differently. The price is still stated on both sides and both
     * charges still share their filing, so every pairing pass matches them off
     * against each other - only counting the occurrences shows that one of the
     * two left that price.
     */
    public function test_one_of_two_identically_priced_charges_being_repriced_is_caught(): void
    {
        $project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'One project',
        ]);

        // One worker, one project, two rates - so the composer writes two lines
        // that differ only in the rate their wording quotes, which is exactly
        // what identity normalises away.
        foreach ([6000, 9000] as $rate) {
            ClientTimeEntry::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'client_project_id' => $project->id,
                'user_id' => $this->user->id,
                'worked_on' => '2024-02-14',
                'minutes' => 60,
                'description' => 'Subcontracted work',
                'is_billable' => true,
                'is_deferred' => false,
                'status' => 'approved',
                'currency' => 'USD',
                'subcontractor_billing_mode' => 'flat_hourly',
                'subcontractor_cost_amount' => $rate,
                'subcontractor_cost_currency' => 'USD',
            ]);
        }

        $this->generatedHistory();

        $lines = ClientInvoiceLine::query()->where('workspace_id', $this->workspace->id)->where('type', 'subcontractor')->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $lines->count(), 'The fixture must produce two charges under one filing.');

        /** @var ClientInvoiceLine $a */
        $a = $lines->firstOrFail();
        /** @var ClientInvoiceLine $b */
        $b = $lines->last();

        // History charged both at the second's price.
        $a->forceFill([
            'unit_amount' => (int) $b->unit_amount,
            'total_amount' => (int) $b->total_amount,
            'description' => (string) $b->description,
        ])->save();

        $row = $this->comparisonFor((string) $a->invoice()->firstOrFail()->invoice_number);

        $this->assertNotNull($row);
        $this->assertTrue($row['line_repriced']);
    }

    /**
     * A recurring item keeps its id when its description is rewritten. Nothing
     * about the wording links the two versions, but the item does - and without
     * asking it, an item renamed and repriced in one edit pairs with nothing
     * and the repricing reads as a line removed and another added.
     */
    public function test_a_renamed_recurring_item_is_still_paired_by_its_id(): void
    {
        // The engine bills this item, so both sides carry its id.
        $this->generatedHistory(function (ClientAgreement $agreement): void {
            ClientAgreementRecurringItem::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_agreement_id' => $agreement->id,
                'description' => 'Support plan',
                'cadence' => 'monthly',
                'quantity' => '1.0000',
                'amount' => 5000,
                'currency' => 'USD',
                'effective_on' => '2024-01-01',
                'is_active' => true,
            ]);
        });

        $line = ClientInvoiceLine::query()->where('workspace_id', $this->workspace->id)
            ->whereNotNull('client_agreement_recurring_item_id')->orderBy('id')->first();
        $this->assertInstanceOf(ClientInvoiceLine::class, $line, 'The fixture must produce a recurring-item line.');

        $invoice = $line->invoice()->firstOrFail();
        $unit = (int) $line->unit_amount;
        $this->assertGreaterThan(0, $unit);

        // History worded the charge differently and priced it differently, with
        // a compensating quantity so the invoice total never moves. The item id
        // is the only thing the two versions share.
        $line->forceFill([
            'description' => 'Support plan, renamed since',
            'unit_amount' => intdiv($unit, 2),
            'quantity' => (string) ((float) $line->quantity * 2),
        ])->save();

        $row = $this->comparisonFor((string) $invoice->invoice_number);

        $this->assertNotNull($row);
        $this->assertTrue($row['line_repriced'], 'The item id links the two versions when the wording cannot.');
    }

    /**
     * Subtotal and tax are money on the invoice header. Moving them by
     * offsetting amounts leaves the total, the currency and every line
     * untouched - and bills the client differently for the same figure.
     */
    public function test_an_offsetting_subtotal_and_tax_are_a_money_difference(): void
    {
        $invoice = $this->generatedHistory();
        $this->assertGreaterThan(0, (int) $invoice->subtotal_amount);

        // The same total, split differently between the charge and its tax.
        $invoice->forceFill([
            'subtotal_amount' => (int) $invoice->subtotal_amount - 500,
            'tax_amount' => (int) $invoice->tax_amount + 500,
        ])->save();

        $this->assertSame('money_differs', $this->verdictFor((string) $invoice->invoice_number));
    }

    /**
     * Two charges the engine no longer produces, whose totals cancel. Every
     * count falls and none rises, the invoice total does not move, and the
     * client was nonetheless shown two charges they are no longer shown.
     */
    public function test_two_dropped_charges_that_cancel_are_still_a_money_difference(): void
    {
        $invoice = $this->generatedHistory();

        /** @var ClientInvoiceLine $line */
        $line = ClientInvoiceLine::query()->where('workspace_id', $this->workspace->id)
            ->where('client_invoice_id', $invoice->id)->orderByDesc('total_amount')->firstOrFail();

        // Both of one type, so that type's net total is exactly where it was -
        // which is all the per-type comparison would have looked at.
        foreach ([['An adjustment the engine no longer makes', 'adjustment', 10000], ['Another the engine no longer makes', 'adjustment', -10000]] as [$description, $type, $amount]) {
            $extra = $line->replicate();
            $extra->public_id = (string) Str::uuid();
            $extra->description = $description;
            $extra->type = $type;
            $extra->quantity = '1.0000';
            $extra->unit_amount = abs($amount);
            $extra->total_amount = $amount;
            $extra->sort_order = (int) $line->sort_order + 10;
            $extra->save();
        }

        $row = $this->comparisonFor((string) $invoice->invoice_number);

        // The invoice total is untouched, so nothing above the lines notices.
        $this->assertNotNull($row);
        $this->assertSame('money_differs', $row['verdict']);
        // And attribution has to hear that this type was involved, or a
        // correction covering the rest of the invoice waives these away.
        $this->assertContains('adjustment', $row['changed_types']);
    }

    /**
     * A charge that becomes a line charging nothing. Only a change between two
     * amounts that charge nobody is representation - money left here, and the
     * pairing has to still see the two as one charge to say so.
     */
    public function test_a_charge_falling_to_nothing_is_a_repricing(): void
    {
        [$overage] = $this->chargedOverageHistory();
        $invoice = $overage->invoice()->firstOrFail();
        $this->assertNotSame(0, (int) $overage->total_amount);

        // History charged for this; the engine no longer does, at the same
        // wording and the same filing.
        $overage->forceFill(['unit_amount' => 0, 'total_amount' => 0])->save();

        $row = $this->comparisonFor((string) $invoice->invoice_number);

        $this->assertNotNull($row);
        $this->assertTrue($row['line_repriced'], 'A charge falling to nothing is the same charge at a different price.');
        $this->assertNull($row['explained_by'] ?? null);
    }

    /**
     * A charge reclassified between capacity types, at a different amount. That
     * is one of the four things this port does on purpose, and it is a charge
     * removed and another added rather than one charge repriced - so the money
     * is reported and the correction is still allowed to account for it.
     */
    public function test_a_reclassified_charge_stays_explainable(): void
    {
        [$overage] = $this->chargedOverageHistory();
        $invoice = $overage->invoice()->firstOrFail();
        $this->assertSame('additional_hours', (string) $overage->type);

        // History billed this as prior-month work, and for a different amount.
        $overage->forceFill([
            'type' => 'prior_month_billable',
            'unit_amount' => (int) $overage->unit_amount + 2500,
            'total_amount' => (int) $overage->total_amount + 2500,
        ])->save();

        $row = $this->comparisonFor((string) $invoice->invoice_number);

        $this->assertNotNull($row);
        $this->assertSame('money_differs', $row['verdict']);
        $this->assertFalse($row['line_repriced'], 'A charge of another kind is not the same charge repriced.');
    }

    /**
     * A charge repriced while another arrives at the price it left. That price
     * never moves in count, so nothing looks like a substitution and the new
     * one reads as a plain addition - which it is, and equally is not. Where a
     * pairing cannot tell, it must not certify.
     */
    public function test_an_addition_that_could_be_masking_a_repricing_is_not_certified(): void
    {
        [$kept, $dropped] = $this->twoChargesUnderOneFiling();
        $invoice = $kept->invoice()->firstOrFail();

        // History held only one of the two charges the engine produces, so the
        // engine's extra one arrives beside a price that never moved.
        $dropped->delete();

        $row = $this->comparisonFor((string) $invoice->invoice_number);

        $this->assertNotNull($row);
        $this->assertTrue($row['line_repriced'], 'A pairing that cannot decide must not certify.');
    }

    /**
     * A milestone's title and price are both editable, so rewriting them
     * together leaves nothing in the wording linking the two versions. The
     * claim the task holds on its invoice line does link them, and this command
     * releases and recreates exactly that claim.
     */
    public function test_a_retitled_milestone_is_still_paired_by_its_claim(): void
    {
        $task = null;
        $this->generatedHistory(function (ClientAgreement $agreement) use (&$task): void {
            $project = ClientProject::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'name' => 'Deliverables',
            ]);

            $task = ClientTask::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_project_id' => $project->id,
                'title' => 'Phase one',
                'status' => 'completed',
                'completed_at' => '2024-02-20',
                'milestone_price_amount' => 250000,
            ]);
        });

        $this->assertInstanceOf(ClientTask::class, $task);
        $line = ClientInvoiceLine::query()->where('workspace_id', $this->workspace->id)
            ->where('type', 'milestone')->orderBy('id')->first();
        $this->assertInstanceOf(ClientInvoiceLine::class, $line, 'The fixture must bill a milestone.');
        $invoice = $line->invoice()->firstOrFail();

        // The engine bills from the task's current title and price, so changing
        // both leaves the two versions with nothing in common but the claim.
        $task->forceFill(['title' => 'Phase one, retitled', 'milestone_price_amount' => 275000])->save();

        $row = $this->comparisonFor((string) $invoice->invoice_number);

        $this->assertNotNull($row);
        $this->assertTrue($row['line_repriced'], 'The claim links the two versions when the wording cannot.');
    }

    /**
     * Two deliverables alike in every field the invoice shows. If the claim is
     * not compared, a milestone billed against the wrong one reproduces
     * exactly - the charge is right and the thing it paid for is not.
     */
    public function test_a_milestone_claimed_by_a_different_task_is_not_a_match(): void
    {
        $tasks = [];
        $this->generatedHistory(function (ClientAgreement $agreement) use (&$tasks): void {
            $project = ClientProject::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'name' => 'Deliverables',
            ]);

            // One deliverable the engine bills, and one it does not - so the
            // invoice carries a single milestone line and nothing but its
            // claim can say which deliverable it paid for.
            $tasks[] = ClientTask::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_project_id' => $project->id,
                'title' => 'Phase one',
                'status' => 'completed',
                'completed_at' => '2024-02-20',
                'milestone_price_amount' => 250000,
            ]);

            $tasks[] = ClientTask::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_project_id' => $project->id,
                'title' => 'Phase one',
            ]);
        });

        $line = ClientInvoiceLine::query()->where('workspace_id', $this->workspace->id)
            ->where('type', 'milestone')->orderBy('id')->first();
        $this->assertInstanceOf(ClientInvoiceLine::class, $line, 'The fixture must bill a milestone.');
        $invoice = $line->invoice()->firstOrFail();

        // History billed this charge against the other deliverable.
        $claimant = ClientTask::query()->where('workspace_id', $this->workspace->id)
            ->where('client_invoice_line_id', $line->id)->firstOrFail();
        $other = ClientTask::query()->where('workspace_id', $this->workspace->id)
            ->whereKeyNot($claimant->getKey())->firstOrFail();

        // Same leading characters, so a comparison key that shortens the claim
        // cannot tell these two apart.
        $prefix = substr((string) $claimant->public_id, 0, 8);
        $other->forceFill(['public_id' => $prefix.substr((string) Str::uuid(), 8)])->save();

        $claimant->forceFill(['client_invoice_line_id' => null])->save();
        $other->forceFill(['client_invoice_line_id' => $line->id])->save();

        $this->assertSame($prefix, substr((string) $other->public_id, 0, 8));
        $this->assertNotSame((string) $claimant->public_id, (string) $other->public_id);
        $this->assertNotSame('match', $this->verdictFor((string) $invoice->invoice_number));
    }

    public function test_a_charge_that_moves_and_reprices_is_not_filed_as_a_move(): void
    {
        $this->generatedHistory();

        /** @var ClientInvoiceLine $line */
        $line = ClientInvoiceLine::query()->where('workspace_id', $this->workspace->id)->orderByDesc('total_amount')->firstOrFail();
        $project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Somewhere else',
        ]);

        $unit = (int) $line->unit_amount;
        $this->assertSame(0, $unit % 2, 'This fixture needs an even unit amount to halve.');

        // Half the price at twice the quantity, so the invoice total is
        // untouched, and filed under a different project at the same time.
        $line->forceFill([
            'client_project_id' => $project->id,
            'unit_amount' => intdiv($unit, 2),
            'quantity' => (string) ((float) $line->quantity * 2),
        ])->save();

        $report = tempnam(sys_get_temp_dir(), 'svc-replay-');

        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->run();

            /** @var array{comparisons: list<array<string, mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        $number = (string) $line->invoice()->firstOrFail()->invoice_number;
        $row = array_column($detail['comparisons'], null, 'invoice_number')[$number] ?? null;

        $this->assertNotNull($row);
        $this->assertSame('money_differs', $row['verdict']);
        // Repriced, not merely moved. The move puts it beyond the pairing that
        // knows where a charge is filed, so only pairing on what the charge is
        // can still recognise it as the same charge at a different price.
        $this->assertTrue($row['line_repriced']);
    }

    /**
     * The safety property. The command deletes and regenerates every invoice to
     * do its work, so the only thing standing between it and production data is
     * the unconditional rollback.
     */
    public function test_it_leaves_the_database_exactly_as_it_found_it(): void
    {
        $invoice = $this->generatedHistory();
        $invoice->forceFill(['total_amount' => (int) $invoice->total_amount + 100])->save();

        $before = $this->fingerprint();

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])->assertFailed();

        $this->assertSame($before, $this->fingerprint(), 'The replay must not change a single row');
    }

    /**
     * An operator-typed invoice has no generator that would reproduce it, so
     * counting it as a divergence would make every real run fail.
     */
    public function test_ad_hoc_invoices_are_set_aside_rather_than_failed(): void
    {
        $this->generatedHistory();

        ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'SVC-ADHOC',
            'currency' => 'USD',
            'status' => 'issued',
            'invoice_kind' => 'ad_hoc',
            'service_period_start' => '2023-01-01',
            'service_period_end' => '2023-01-31',
            'subtotal_amount' => 50000,
            'tax_amount' => 0,
            'total_amount' => 50000,
        ]);
        ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => (int) ClientInvoice::query()->where('workspace_id', $this->workspace->id)->where('invoice_number', 'SVC-ADHOC')->value('id'),
            'type' => 'additional_hours', 'description' => 'One-off', 'quantity' => '1',
            'unit_amount' => 50000, 'tax_amount' => 0, 'total_amount' => 50000, 'sort_order' => 0,
        ]);

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])
            ->expectsOutputToContain('ad-hoc')
            ->assertSuccessful();
    }

    /**
     * The harness must not invent the divergences it reports.
     *
     * `OverpaymentCreditService` derives credit from payment rows measured
     * against `total_amount`. Blanking the totals for regeneration while
     * leaving the payments made every settled invoice in history look overpaid
     * by its full amount, and the regenerated invoices then drew on a credit
     * pool that had never existed.
     */
    public function test_a_paid_invoice_does_not_become_credit_for_the_invoice_replacing_it(): void
    {
        $invoice = $this->generatedHistory();
        $invoice->forceFill([
            'status' => 'paid',
            'paid_amount' => $invoice->total_amount,
            'balance_amount' => 0,
        ])->save();

        DB::table('client_invoice_payments')->insert([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $invoice->id,
            'public_id' => (string) Str::uuid(),
            'amount' => (int) $invoice->total_amount,
            'currency' => 'USD',
            'status' => 'succeeded',
            'received_on' => '2024-02-05',
            'method' => 'bank_transfer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])
            ->doesntExpectOutputToContain('credit')
            ->assertSuccessful();
    }

    /**
     * An ad-hoc invoice is excluded from the comparison and never regenerated,
     * so releasing its claims handed already-billed work to the cadence
     * generator and reported the second charge as a divergence.
     */
    public function test_an_ad_hoc_invoices_milestone_claim_survives_the_replay(): void
    {
        $this->generatedHistory();

        $adHoc = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'SVC-ADHOC-2',
            'currency' => 'USD',
            'status' => 'issued',
            'invoice_kind' => 'ad_hoc',
            'service_period_start' => '2024-01-01',
            'service_period_end' => '2024-01-31',
            'subtotal_amount' => 50000,
            'tax_amount' => 0,
            'total_amount' => 50000,
        ]);
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $adHoc->id,
            'type' => 'milestone', 'description' => 'Billed ad hoc', 'quantity' => '1',
            'unit_amount' => 50000, 'tax_amount' => 0, 'total_amount' => 50000, 'sort_order' => 0,
        ]);

        $this->artisan('svc:billing:replay', ['--workspace' => $this->workspace->public_id])->assertSuccessful();

        $this->assertDatabaseHas('client_invoice_lines', ['id' => $line->id]);
        $this->assertSame(50000, (int) $adHoc->refresh()->total_amount, 'An ad-hoc invoice is left alone entirely');
    }

    /**
     * A whole-database fingerprint, so the safety assertion cannot pass by
     * checking only the rows that happened to be remembered.
     */
    /**
     * Two charges the engine files under one key.
     *
     * One worker, one project, two rates: the composer writes a line each, and
     * their wording differs only in the rate it quotes - which identity
     * normalises away, leaving the same filing for both.
     *
     * @return array{0: ClientInvoiceLine, 1: ClientInvoiceLine}
     */
    private function twoChargesUnderOneFiling(): array
    {
        $project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'One project',
        ]);

        foreach ([6000, 9000] as $rate) {
            ClientTimeEntry::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'client_project_id' => $project->id,
                'user_id' => $this->user->id,
                'worked_on' => '2024-02-14',
                'minutes' => 60,
                'description' => 'Subcontracted work',
                'is_billable' => true,
                'is_deferred' => false,
                'status' => 'approved',
                'currency' => 'USD',
                'subcontractor_billing_mode' => 'flat_hourly',
                'subcontractor_cost_amount' => $rate,
                'subcontractor_cost_currency' => 'USD',
            ]);
        }

        $this->generatedHistory();

        $lines = ClientInvoiceLine::query()->where('workspace_id', $this->workspace->id)
            ->where('type', 'subcontractor')->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $lines->count(), 'The fixture must produce two charges under one filing.');

        /** @var ClientInvoiceLine $first */
        $first = $lines->firstOrFail();
        /** @var ClientInvoiceLine $last */
        $last = $lines->last();

        return [$first, $last];
    }

    /**
     * History whose overage is actually charged for.
     *
     * The default fixture absorbs its overage into the rollover pool, which
     * produces capacity lines at a total of zero - real lines, but ones that
     * charge nobody, so no question about money can be asked of them.
     *
     * @return array{0: ClientInvoiceLine, 1: ClientInvoiceLine} the charged
     *                                                           overage line and the retainer line on the same invoice
     */
    private function chargedOverageHistory(): array
    {
        $this->generatedHistory(function (ClientAgreement $agreement): void {
            $agreement->forceFill(['rollover_months' => 0])->save();

            ClientTimeEntry::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'client_project_id' => $this->project->id,
                'user_id' => $this->user->id,
                'worked_on' => '2024-03-14',
                'minutes' => 3000,
                'description' => 'Far more work than the retainer covers',
                'is_billable' => true,
                'is_deferred' => false,
                'status' => 'approved',
                'currency' => 'USD',
            ]);
        });

        $overage = ClientInvoiceLine::query()->where('workspace_id', $this->workspace->id)
            ->where('type', 'additional_hours')->where('total_amount', '!=', 0)->orderBy('id')->first();
        // Asserted, not skipped: this is a deterministic local fixture, and
        // five tests are meaningless without it. A skip would take them out of
        // the run and report nothing.
        $this->assertInstanceOf(ClientInvoiceLine::class, $overage, 'The fixture must charge for its overage.');

        $retainer = ClientInvoiceLine::query()->where('workspace_id', $this->workspace->id)
            ->where('client_invoice_id', $overage->client_invoice_id)
            ->where('type', 'retainer')->orderBy('id')->first();
        $this->assertInstanceOf(ClientInvoiceLine::class, $retainer, 'The fixture must put a retainer beside the overage.');

        return [$overage, $retainer];
    }

    /**
     * One worker on two projects, billed once per project.
     *
     * The composer words these two identically apart from the rate each quotes,
     * so nothing but the project tells them apart - which is what makes them
     * the fixture for every question about pairing concurrent charges.
     *
     * @return array{0: ClientInvoiceLine, 1: ClientInvoiceLine}
     */
    private function twoConcurrentSubcontractorCharges(): array
    {
        foreach ([['Here', 6000], ['There', 9000]] as [$name, $rate]) {
            $project = ClientProject::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'name' => $name,
            ]);

            ClientTimeEntry::query()->create([
                'workspace_id' => $this->workspace->id,
                'client_company_id' => $this->company->id,
                'client_project_id' => $project->id,
                'user_id' => $this->user->id,
                'worked_on' => '2024-02-14',
                'minutes' => 60,
                'description' => 'Subcontracted work',
                'is_billable' => true,
                'is_deferred' => false,
                'status' => 'approved',
                'currency' => 'USD',
                'subcontractor_billing_mode' => 'flat_hourly',
                'subcontractor_cost_amount' => $rate,
                'subcontractor_cost_currency' => 'USD',
            ]);
        }

        $this->generatedHistory();

        $lines = ClientInvoiceLine::query()->where('workspace_id', $this->workspace->id)->where('type', 'subcontractor')->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $lines->count(), 'The fixture must produce two concurrent subcontractor lines.');

        /** @var ClientInvoiceLine $first */
        $first = $lines->firstOrFail();
        /** @var ClientInvoiceLine $last */
        $last = $lines->last();

        return [$first, $last];
    }

    /**
     * The whole comparison row the replay records for one invoice.
     *
     * @return array<string, mixed>|null
     */
    private function comparisonFor(string $invoiceNumber): ?array
    {
        $report = tempnam(sys_get_temp_dir(), 'svc-replay-');

        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->run();

            /** @var array{comparisons: list<array<string, mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        return array_column($detail['comparisons'], null, 'invoice_number')[$invoiceNumber] ?? null;
    }

    /** The verdict the replay records for one invoice, via its report file. */
    private function verdictFor(string $invoiceNumber): ?string
    {
        $report = tempnam(sys_get_temp_dir(), 'svc-replay-');

        try {
            $this->artisan('svc:billing:replay', [
                '--workspace' => $this->workspace->public_id,
                '--report' => $report,
            ])->run();

            /** @var array{comparisons: list<array<string, mixed>>} $detail */
            $detail = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
        }

        $verdicts = array_column($detail['comparisons'], 'verdict', 'invoice_number');

        return $verdicts[$invoiceNumber] ?? null;
    }

    private function fingerprint(): string
    {
        $parts = [];

        foreach (['client_agreements', 'client_invoices', 'client_invoice_lines', 'client_invoice_line_time_entries', 'client_time_entries', 'client_tasks', 'workspace_invoice_counters'] as $table) {
            // Sorted here rather than in SQL because not every one of these
            // tables has an `id`: workspace_invoice_counters is keyed on
            // workspace_id alone. `orderBy('id')` looked fine on SQLite, which
            // reinterprets an unresolvable double-quoted identifier as a string
            // literal - so it ordered by the constant 'id', silently doing
            // nothing. MySQL raises 1054 instead. Sorting the encoded rows needs
            // no key at all and is stable on both.
            $rows = DB::table($table)->get()
                ->map(static fn (object $row): string => (string) json_encode($row))
                ->sort()
                ->values()
                ->all();
            $parts[] = $table.':'.md5((string) json_encode($rows));
        }

        return implode('|', $parts);
    }

    /**
     * @param  (callable(ClientAgreement): void)|null  $beforeGenerating
     *                                                                    Run once the agreement exists and before any invoice is produced, for
     *                                                                    the cases that need the engine itself to bill something extra.
     */
    private function generatedHistory(?callable $beforeGenerating = null): ClientInvoice
    {
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2024-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 20000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 2,
        ]);

        ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => '2024-02-14',
            'minutes' => 900,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);

        // The whole history, as the original system would have produced it -
        // one hand-made invoice would leave every later cycle looking like a
        // divergence the moment the replay walked past it.
        if ($beforeGenerating !== null) {
            $beforeGenerating($agreement);
        }

        Carbon::setTestNow(Carbon::parse('2024-06-15'));
        try {
            app(ClientInvoicingService::class)->generateAllInvoices($this->company);
        } finally {
            Carbon::setTestNow();
        }

        return ClientInvoice::query()->where('workspace_id', $this->workspace->id)->orderByDesc('id')->firstOrFail();
    }

    private function cadenceGapAgreement(): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Synthetic semiannual retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 120,
            'retainer_amount' => 25000,
            'hourly_rate_amount' => 15000,
            'billing_cadence' => 'semi_annual',
            'rollover_months' => 0,
        ]);
    }

    private function adHocHistory(string $number, string $cycleStart, string $periodStart, string $periodEnd): void
    {
        ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => $number,
            'currency' => 'USD',
            'status' => 'draft',
            'invoice_kind' => 'ad_hoc',
            'cycle_start' => $cycleStart,
            'cycle_end' => Carbon::parse($cycleStart)->addMonths(6)->subDay()->toDateString(),
            'service_period_start' => $periodStart,
            'service_period_end' => $periodEnd,
            'subtotal_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);
    }
}
