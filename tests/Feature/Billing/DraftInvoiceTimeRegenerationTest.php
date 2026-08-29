<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\TimeEntryMutationService;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceFromTimeService;
use App\Support\AgentApi\AgentApiVersion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class DraftInvoiceTimeRegenerationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
        $this->workspace = Workspace::query()->create(['name' => 'Draft regeneration', 'slug' => 'draft-regeneration']);
        $this->workspace->memberships()->create(['user_id' => $this->manager->id, 'role' => 'admin']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Draft Client',
            'slug' => 'draft-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Draft Project',
            'status' => 'active',
        ]);
    }

    public function test_editing_approved_time_rebuilds_its_cadence_draft_in_the_same_invoice(): void
    {
        $agreement = $this->agreement();
        $entry = $this->approvedEntry(['minutes' => 60]);
        $invoice = $this->generateJuly($agreement);
        $invoice->lines()->create([
            'workspace_id' => $this->workspace->id,
            'type' => 'adjustment',
            'description' => 'Operator adjustment',
            'quantity' => '1.0000',
            'unit_amount' => 500,
            'tax_amount' => 0,
            'total_amount' => 500,
            'sort_order' => 99,
        ]);
        $invoice->recalculateTotals();

        $updated = app(TimeEntryMutationService::class)->update(
            $this->workspace,
            $entry,
            $this->manager,
            ['expected_version' => AgentApiVersion::for($entry), 'minutes' => 90],
        );

        $invoice->refresh();
        $linkedLine = $invoice->lines()->whereHas('timeEntries', fn ($query) => $query->whereKey($updated->id))->sole();
        $this->assertSame($invoice->id, $linkedLine->client_invoice_id);
        $this->assertSame('approved', $updated->status);
        $this->assertSame(90, $updated->minutes);
        $this->assertSame(18000, $linkedLine->total_amount);
        $this->assertSame(18500, $invoice->total_amount);
        $this->assertDatabaseHas('client_invoice_lines', [
            'client_invoice_id' => $invoice->id,
            'type' => 'adjustment',
            'description' => 'Operator adjustment',
            'total_amount' => 500,
        ]);
        $this->assertSame(1, ClientInvoice::query()->count());
    }

    public function test_deleting_approved_time_rebuilds_the_cadence_draft_without_it(): void
    {
        $agreement = $this->agreement();
        $entry = $this->approvedEntry(['minutes' => 60]);
        $invoice = $this->generateJuly($agreement);

        app(TimeEntryMutationService::class)->delete(
            $this->workspace,
            $entry,
            $this->manager,
            AgentApiVersion::for($entry),
        );

        $this->assertSoftDeleted($entry);
        $this->assertDatabaseMissing('client_invoice_line_time_entries', [
            'workspace_id' => $this->workspace->id,
            'client_time_entry_id' => $entry->id,
        ]);
        $this->assertSame(0, $invoice->refresh()->total_amount);
        $this->assertSame(1, ClientInvoice::query()->count());
    }

    public function test_moving_time_between_periods_rebuilds_both_existing_cadence_drafts(): void
    {
        $agreement = $this->agreement();
        $moved = $this->approvedEntry(['worked_on' => '2026-07-14', 'minutes' => 60]);
        $this->approvedEntry(['worked_on' => '2026-08-14', 'minutes' => 60, 'description' => 'August work']);
        $july = $this->generateJuly($agreement);
        $august = app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-31'),
            $agreement,
        )->refresh();

        app(TimeEntryMutationService::class)->update(
            $this->workspace,
            $moved,
            $this->manager,
            [
                'expected_version' => AgentApiVersion::for($moved),
                'worked_on' => '2026-08-10',
            ],
        );

        $this->assertSame(0, $july->refresh()->total_amount);
        $this->assertSame(24000, $august->refresh()->total_amount);
        $this->assertTrue($moved->fresh()?->invoiceLines()
            ->where('client_invoice_id', $august->id)
            ->exists());
        $this->assertSame(2, ClientInvoice::query()->count());
    }

    public function test_editing_selected_time_reprices_an_ad_hoc_draft_and_preserves_manual_lines(): void
    {
        $entry = $this->approvedEntry(['minutes' => 60]);
        $invoice = app(InvoiceFromTimeService::class)->create(
            $this->workspace,
            $this->company,
            ['invoice_number' => 'SVC-AD-HOC', 'currency' => 'USD'],
            [$entry->public_id],
            [[
                'type' => 'adjustment',
                'description' => 'Manual line',
                'quantity' => '1.0000',
                'unit_amount' => 700,
                'tax_amount' => 0,
                'sort_order' => 0,
            ]],
        );

        app(TimeEntryMutationService::class)->update(
            $this->workspace,
            $entry,
            $this->manager,
            [
                'expected_version' => AgentApiVersion::for($entry),
                'minutes' => 30,
                'description' => 'Corrected selected work',
            ],
        );

        $invoice->refresh();
        $timeLine = $invoice->lines()->whereHas('timeEntries', fn ($query) => $query->whereKey($entry->id))->sole();
        $this->assertSame('Corrected selected work', $timeLine->description);
        $this->assertSame(6000, $timeLine->total_amount);
        $this->assertSame(6700, $invoice->total_amount);
        $this->assertSame(1, $invoice->lines()->where('description', 'Manual line')->count());
    }

    public function test_editing_time_claimed_by_an_interim_draft_regenerates_that_draft(): void
    {
        $agreement = $this->quarterlyAgreement();
        $this->approvedEntry(['worked_on' => '2026-07-14', 'minutes' => 600]);
        $invoice = app(ClientInvoicingService::class)->generateInterimOverageInvoice(
            $this->company,
            Carbon::parse('2026-07-01'),
            $agreement,
        );
        $this->assertInstanceOf(ClientInvoice::class, $invoice);
        $originalTotal = (int) $invoice->total_amount;
        $billedEntry = ClientTimeEntry::query()
            ->where('workspace_id', $this->workspace->id)
            ->whereHas('invoiceLines', fn ($lines) => $lines->where('client_invoice_id', $invoice->id))
            ->firstOrFail();

        app(TimeEntryMutationService::class)->update(
            $this->workspace,
            $billedEntry,
            $this->manager,
            [
                'expected_version' => AgentApiVersion::for($billedEntry),
                'minutes' => max(1, $billedEntry->minutes - 60),
            ],
        );

        $this->assertSame($invoice->id, $invoice->refresh()->id);
        $this->assertLessThan($originalTotal, (int) $invoice->total_amount);
        $this->assertSame(1, ClientInvoice::query()->count());
    }

    public function test_approved_time_without_a_draft_invoice_remains_immutable(): void
    {
        $entry = $this->approvedEntry();

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $entry,
                $this->manager,
                ['expected_version' => AgentApiVersion::for($entry), 'minutes' => 90],
            );
            $this->fail('Unallocated approved time must remain immutable.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame('Only draft time entries can be changed.', $exception->getMessage());
        }

        $this->assertSame(60, $entry->fresh()?->minutes);
    }

    public function test_another_workspaces_invoice_link_neither_freezes_nor_regenerates_this_entry(): void
    {
        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->manager->id,
            'worked_on' => '2026-07-14',
            'minutes' => 60,
            'description' => 'Local draft work',
            'status' => 'draft',
        ]);
        $otherWorkspace = Workspace::query()->create(['name' => 'Other draft regeneration', 'slug' => 'other-draft-regeneration']);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Other Draft Client',
            'slug' => 'other-draft-client',
        ]);
        $foreignInvoice = ClientInvoice::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $otherCompany->id,
            'invoice_number' => 'FOREIGN-DRAFT',
            'status' => 'draft',
            'invoice_kind' => 'ad_hoc',
            'currency' => 'USD',
            'subtotal_amount' => 900,
            'tax_amount' => 0,
            'total_amount' => 900,
        ]);
        $foreignLine = $foreignInvoice->lines()->create([
            'workspace_id' => $otherWorkspace->id,
            'type' => 'time',
            'description' => 'Foreign synthetic line',
            'quantity' => '1.0000',
            'unit_amount' => 900,
            'tax_amount' => 0,
            'total_amount' => 900,
            'sort_order' => 0,
        ]);
        DB::table('client_invoice_line_time_entries')->insert([
            'workspace_id' => $otherWorkspace->id,
            'client_invoice_line_id' => $foreignLine->id,
            'client_time_entry_id' => $entry->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(TimeEntryMutationService::class)->update(
            $this->workspace,
            $entry,
            $this->manager,
            ['expected_version' => AgentApiVersion::for($entry), 'minutes' => 75],
        );

        $this->assertSame(75, $entry->fresh()?->minutes);
        $this->assertSame(900, $foreignInvoice->fresh()?->total_amount);
        $this->assertDatabaseHas('client_invoice_line_time_entries', [
            'workspace_id' => $otherWorkspace->id,
            'client_invoice_line_id' => $foreignLine->id,
            'client_time_entry_id' => $entry->id,
        ]);
    }

    public function test_a_same_workspace_cross_company_invoice_chain_fails_closed(): void
    {
        $entry = $this->approvedEntry();
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Other local client',
            'slug' => 'other-local-client',
        ]);
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $otherCompany->id,
            'invoice_number' => 'WRONG-COMPANY-DRAFT',
            'status' => 'draft',
            'invoice_kind' => 'ad_hoc',
            'currency' => 'USD',
            'subtotal_amount' => 12000,
            'tax_amount' => 0,
            'total_amount' => 12000,
        ]);
        $line = $invoice->lines()->create([
            'workspace_id' => $this->workspace->id,
            'type' => 'time',
            'description' => 'Wrong company line',
            'quantity' => '1.0000',
            'unit_amount' => 12000,
            'tax_amount' => 0,
            'total_amount' => 12000,
            'sort_order' => 0,
        ]);
        $line->timeEntries()->attach($entry->id, ['workspace_id' => $this->workspace->id]);

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $entry,
                $this->manager,
                ['expected_version' => AgentApiVersion::for($entry), 'minutes' => 90],
            );
            $this->fail('A cross-company invoice chain must stop the edit.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame('The time entry invoice allocation has an inconsistent client company.', $exception->getMessage());
        }

        $this->assertSame(60, $entry->fresh()?->minutes);
        $this->assertSame(12000, $invoice->fresh()?->total_amount);
    }

    #[DataProvider('immutableInvoiceStatuses')]
    public function test_every_non_draft_or_unknown_invoice_status_keeps_time_frozen(string $status): void
    {
        $entry = $this->approvedEntry();
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'invoice_number' => 'IMMUTABLE-'.strtoupper($status),
            'status' => $status,
            'invoice_kind' => 'ad_hoc',
            'currency' => 'USD',
            'subtotal_amount' => 12000,
            'tax_amount' => 0,
            'total_amount' => 12000,
        ]);
        $line = $invoice->lines()->create([
            'workspace_id' => $this->workspace->id,
            'type' => 'time',
            'description' => 'Immutable time',
            'quantity' => '1.0000',
            'unit_amount' => 12000,
            'tax_amount' => 0,
            'total_amount' => 12000,
            'sort_order' => 0,
        ]);
        $line->timeEntries()->attach($entry->id, ['workspace_id' => $this->workspace->id]);

        try {
            app(TimeEntryMutationService::class)->update(
                $this->workspace,
                $entry,
                $this->manager,
                ['expected_version' => AgentApiVersion::for($entry), 'minutes' => 90],
            );
            $this->fail("Time on a {$status} invoice must remain frozen.");
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame('Time on an issued, paid, void, or unknown invoice cannot be changed.', $exception->getMessage());
        }

        $this->assertSame(60, $entry->fresh()?->minutes);
        $this->assertSame(12000, $invoice->fresh()?->total_amount);
    }

    /** @return list<array{string}> */
    public static function immutableInvoiceStatuses(): array
    {
        return [['issued'], ['partially_paid'], ['paid'], ['void'], ['unexpected_status']];
    }

    private function agreement(): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Hourly monthly agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-06-01',
            'retainer_minutes' => 0,
            'retainer_amount' => 0,
            'catch_up_threshold_minutes' => 0,
            'hourly_rate_amount' => 12000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 0,
        ]);
    }

    private function quarterlyAgreement(): ClientAgreement
    {
        $agreement = $this->agreement();
        $agreement->forceFill([
            'billing_cadence' => 'quarterly',
            'bill_overage_interim' => true,
            'retainer_minutes' => 120,
        ])->save();

        return $agreement->refresh();
    }

    /** @param array<string, mixed> $attributes */
    private function approvedEntry(array $attributes = []): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create($attributes + [
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->manager->id,
            'worked_on' => '2026-07-14',
            'minutes' => 60,
            'description' => 'Approved invoice work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'billing_rate_amount' => 12000,
            'billing_rate_source' => 'agreement',
            'currency' => 'USD',
        ]);
    }

    private function generateJuly(ClientAgreement $agreement): ClientInvoice
    {
        return app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31'),
            $agreement,
        )->refresh();
    }
}
