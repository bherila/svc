<?php

namespace Tests\Feature\Billing;

use App\Http\Controllers\ClientDirectoryController;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentCapacityLedgerReadService;
use App\Services\AgentApi\AgentReadService;
use App\Services\Billing\CapacityLedgerInputs;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\DeferredBillingAllocator;
use App\Services\Billing\DraftInvoiceTimeRegenerator;
use App\Services\Billing\InterimOverageGenerator;
use App\Services\Billing\InvoiceLedgerBuilder;
use App\Services\Billing\InvoiceLineComposer;
use App\Services\Billing\RolloverCalculator;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\Billing\DeferredWorkDisposition;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * The reader inventory is explicit: each observation runs the real reader or
 * its public generation/regeneration graph. A settlement must be indistinguishable
 * from work that never existed, for capacity and the next cadence invoice.
 */
final class DeferredSettlementCapacityTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientAgreement $agreement;

    private User $user;

    private ClientTimeEntry $ordinary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-04-02 12:00:00'));
        $this->user = User::factory()->create();
        $this->workspace = Workspace::query()->create(['name' => 'Settlement test workspace', 'slug' => 'settlement-test']);
        $this->workspace->memberships()->create(['user_id' => $this->user->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic settlement client', 'slug' => 'settlement-client']);
        $this->project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Settlement test project']);
        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'title' => 'Synthetic retainer', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 100000, 'hourly_rate_amount' => 10000,
            'catch_up_threshold_minutes' => 60, 'rollover_months' => 1, 'billing_cadence' => 'monthly',
        ]);
        $this->ordinary = $this->entry(120, false);
    }

    /** @return iterable<string, array{string}> */
    public static function readers(): iterable
    {
        yield 'InvoiceLedgerBuilder monthly' => ['monthly-ledger'];
        yield 'InvoiceLedgerBuilder native period' => ['period-ledger'];
        yield 'monthlyBalances, ClientInvoicingService and InvoiceLineComposer' => ['monthly-invoice'];
        yield 'ClientInvoicingService quarterly and InvoiceLineComposer' => ['quarterly-invoice'];
        yield 'CapacityLedgerInputs and RolloverCalculator' => ['rollover'];
        yield 'AgentReadService and AgentCapacityLedgerReadService' => ['agent'];
        yield 'ClientDirectoryController usedMinutes' => ['directory'];
        yield 'InterimOverageGenerator' => ['interim'];
        yield 'DraftInvoiceTimeRegenerator' => ['regenerator'];
    }

    #[DataProvider('readers')]
    public function test_every_capacity_reader_ignores_work_settled_outside_the_retainer(string $reader): void
    {
        if (in_array($reader, ['quarterly-invoice', 'interim'], true)) {
            $this->agreement->update(['billing_cadence' => 'quarterly', 'bill_overage_interim' => true, 'period_retainer_minutes' => 1800]);
        } elseif ($reader === 'period-ledger') {
            $this->agreement->update(['period_retainer_minutes' => 600]);
        }

        $before = json_encode($this->observe($reader), JSON_THROW_ON_ERROR);
        $settled = $this->entry(2400, true);
        $line = $this->allocate($settled, 'deferred_buydown');
        $after = json_encode($this->observe($reader), JSON_THROW_ON_ERROR);

        $this->assertSame($before, $after, $reader.' must not manufacture capacity usage or a second charge');
        $this->assertSame(0.0, (float) $line->invoice->hours_billed_at_rate, 'Buy-down is independent of catch-up/overage hours');
        $this->assertSame([$settled->id], $line->timeEntries()->pluck('client_time_entries.id')->all(), 'Cadence readers retain the settlement reservation');
    }

    public function test_waiting_applied_and_termination_entries_keep_their_existing_capacity_behavior(): void
    {
        $waiting = $this->entry(60, true);
        $applied = $this->entry(60, true);
        $terminal = $this->entry(60, true);
        $settled = $this->entry(60, true);
        $this->allocate($applied, 'prior_month_retainer');
        $this->allocate($terminal, 'additional_hours');
        $this->allocate($settled, 'deferred_buydown');

        $selected = ClientTimeEntry::query()->where('workspace_id', $this->workspace->id)->deferredOnlyOnceAllocated()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->ordinary->id, $applied->id, $terminal->id], $selected);
        $remaining = app(DeferredBillingAllocator::class)->collectForTermination($this->company, Carbon::parse('2026-04-30'), $this->agreement);
        $this->assertSame([$waiting->id], $remaining->modelKeys(), 'Termination collects only the unsettled remainder');

        $entry = ClientTimeEntry::query()->where('workspace_id', $this->workspace->id)->withCapacityPlacement($this->workspace->id)->findOrFail($terminal->id);
        $this->assertTrue($entry->countsTowardsRetainerCapacity());
        $this->assertFalse($entry->drawsAsDeferred(), 'Termination keeps its ordinary-work disposition');
    }

    /** @return iterable<string, array{string}> */
    public static function foreignAllocationHops(): iterable
    {
        yield 'line and invoice' => ['line'];
        yield 'pivot' => ['pivot'];
        yield 'invoice alone' => ['invoice'];
    }

    #[DataProvider('foreignAllocationHops')]
    public function test_foreign_allocations_cannot_decide_the_local_deferred_disposition(string $hop): void
    {
        $foreign = Workspace::query()->create(['name' => 'Foreign settlement test', 'slug' => 'foreign-settlement']);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $foreign->id, 'name' => 'Foreign synthetic client', 'slug' => 'foreign-settlement-client']);
        $entry = $this->entry(60, true);
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $hop === 'pivot' ? $this->workspace->id : $foreign->id,
            'client_company_id' => $hop === 'pivot' ? $this->company->id : $foreignCompany->id,
            'invoice_number' => 'TEST-FOREIGN', 'currency' => 'USD', 'status' => 'issued', 'invoice_kind' => 'ad_hoc',
        ]);
        $this->writingLegacyCrossTenantRows(function () use ($hop, $foreign, $entry, $invoice): void {
            $line = ClientInvoiceLine::query()->create([
                'workspace_id' => $hop === 'line' ? $foreign->id : $this->workspace->id,
                'client_invoice_id' => $invoice->id, 'type' => 'prior_month_retainer',
                'description' => 'Synthetic legacy allocation', 'quantity' => 0, 'unit_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
                'line_date' => '2026-01-20', 'sort_order' => 0,
            ]);
            $line->timeEntries()->attach($entry->id, ['workspace_id' => $hop === 'pivot' ? $foreign->id : $this->workspace->id]);
        });

        $query = ClientTimeEntry::query()->where('workspace_id', $this->workspace->id)->whereKey($entry->id);
        $this->assertFalse((clone $query)->deferredOnlyOnceAllocated()->exists());
        $loaded = $query->withCapacityPlacement($this->workspace->id)->firstOrFail();
        $this->assertSame(DeferredWorkDisposition::Waiting, $loaded->deferredDisposition());
        $this->assertFalse($loaded->countsTowardsRetainerCapacity());
    }

    public function test_a_cadence_rebuild_cannot_release_an_independent_settlement(): void
    {
        $entry = $this->entry(60, true);
        $line = $this->allocate($entry, 'deferred_buydown');
        $line->invoice->update(['status' => 'draft']);

        app(InvoiceLineComposer::class)->resetSystemGeneratedLines($line->invoice);

        $this->assertTrue($line->refresh()->exists);
        $this->assertSame([$entry->id], $line->timeEntries()->pluck('client_time_entries.id')->all());
    }

    private function observe(string $reader): mixed
    {
        $entries = fn () => ClientTimeEntry::query()->where('workspace_id', $this->workspace->id)->withCapacityPlacement($this->workspace->id)->get();

        return match ($reader) {
            'monthly-ledger', 'period-ledger' => app(InvoiceLedgerBuilder::class)->buildAgreementLedgerThrough($this->company, $this->agreement, Carbon::parse('2026-04-30')),
            'monthly-invoice', 'quarterly-invoice' => $this->invoiceSnapshot($this->generate()),
            'rollover' => app(RolloverCalculator::class)->calculateMultipleMonths([[
                'year_month' => '2026-01', 'retainer_hours' => 10.0, 'reset_rollover' => false,
                ...CapacityLedgerInputs::monthRow(app(CapacityLedgerInputs::class)->byMonth($this->company, $this->agreement, $entries(), Carbon::parse('2026-01-31')), '2026-01'),
            ]], 1),
            'agent' => [
                app(AgentCapacityLedgerReadService::class)->get($this->user, $this->workspace, $this->agreement->public_id, 4),
                app(AgentReadService::class)->summary($this->user, $this->workspace, fn (string $scope): bool => $scope === AgentApiScopes::TIME_READ),
            ],
            'directory' => (new ReflectionMethod(ClientDirectoryController::class, 'usedMinutes'))->invoke(app(ClientDirectoryController::class), $this->workspace, [
                $this->company->id => ['start' => '2026-01-01', 'end' => '2026-01-31', 'projects' => [$this->project->id]],
            ]),
            'interim' => $this->invoiceSnapshot(app(InterimOverageGenerator::class)->generateInterimOverageInvoice($this->company, Carbon::parse('2026-01-01'), $this->agreement)),
            'regenerator' => $this->regenerateSnapshot(),
        };
    }

    private function generate(): ClientInvoice
    {
        return app(ClientInvoicingService::class)->generateInvoice($this->company, Carbon::parse('2026-01-01'), Carbon::parse($this->agreement->billing_cadence === 'quarterly' ? '2026-03-31' : '2026-01-31'), $this->agreement);
    }

    /** @return array<string, mixed> */
    private function regenerateSnapshot(): array
    {
        $invoice = $this->generate();
        app(DraftInvoiceTimeRegenerator::class)->regenerate($invoice, $this->workspace, $this->ordinary->id);

        return $this->invoiceSnapshot($invoice->refresh()) ?? [];
    }

    /** @return array<string, mixed>|null */
    private function invoiceSnapshot(?ClientInvoice $invoice): ?array
    {
        if ($invoice === null) {
            return null;
        }

        return [
            ...$invoice->only(['invoice_number', 'hours_worked', 'hours_billed_at_rate', 'subtotal_amount', 'total_amount', 'unused_hours_balance', 'negative_hours_balance', 'starting_unused_hours', 'starting_negative_hours', 'hours_statement']),
            'lines' => $invoice->lines()->orderBy('sort_order')->get()->map(fn (ClientInvoiceLine $line): array => $line->only(['type', 'description', 'hours', 'quantity', 'unit_amount', 'tax_amount', 'total_amount', 'line_date']))->all(),
        ];
    }

    private function entry(int $minutes, bool $deferred): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_project_id' => $this->project->id,
            'user_id' => $this->user->id, 'worked_on' => '2026-01-10', 'minutes' => $minutes,
            'description' => 'Synthetic settlement test work', 'status' => 'approved', 'is_billable' => true, 'is_deferred' => $deferred,
            'billing_rate_amount' => 10000, 'currency' => 'USD',
        ]);
    }

    private function allocate(ClientTimeEntry $entry, string $type): ClientInvoiceLine
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'invoice_number' => 'TEST-SETTLEMENT-'.$entry->id, 'currency' => 'USD', 'status' => 'issued',
            'invoice_kind' => 'ad_hoc', 'hours_billed_at_rate' => 0,
        ]);
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id, 'client_invoice_id' => $invoice->id, 'client_agreement_id' => $this->agreement->id,
            'type' => $type, 'description' => 'Synthetic allocated work', 'hours' => $entry->minutes / 60,
            'quantity' => $entry->minutes / 60, 'unit_amount' => 10000, 'tax_amount' => 0,
            'total_amount' => (int) ($entry->minutes / 60 * 10000), 'line_date' => '2026-01-20', 'sort_order' => 0,
        ]);
        $line->timeEntries()->attach($entry->id, ['workspace_id' => $this->workspace->id]);

        return $line;
    }
}
