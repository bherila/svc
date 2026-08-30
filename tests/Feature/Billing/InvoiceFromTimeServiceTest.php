<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceFromTimeService;
use App\Support\Billing\SubcontractorBillingMode;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InvoiceFromTimeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allocates_only_explicit_approved_billable_time_with_project_attribution(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Invoice time', 'slug' => 'invoice-time']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Client', 'slug' => 'invoice-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Project']);
        $user = User::factory()->create();
        $entry = ClientTimeEntry::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'user_id' => $user->id, 'worked_on' => '2026-08-23', 'minutes' => 60, 'description' => 'Approved work', 'is_billable' => true, 'status' => 'approved', 'billing_rate_amount' => 12000, 'currency' => 'USD']);

        $invoice = app(InvoiceFromTimeService::class)->create($workspace, $company, ['invoice_number' => 'SVC-00001', 'currency' => 'USD'], [$entry->public_id]);

        $this->assertSame(12000, $invoice->total_amount);
        $this->assertSame($project->id, $invoice->lines->sole()->client_project_id);
        $this->assertTrue($invoice->lines->sole()->timeEntries()->whereKey($entry->id)->exists());
        $this->expectException(DomainException::class);
        app(InvoiceFromTimeService::class)->create($workspace, $company, ['invoice_number' => 'SVC-00002', 'currency' => 'USD'], [$entry->public_id]);
    }

    public function test_fractional_time_uses_exact_integer_minute_totals(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Exact invoice time', 'slug' => 'exact-invoice-time']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Exact Client', 'slug' => 'exact-invoice-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Exact Project']);
        $user = User::factory()->create();
        $rate = 12345;
        $ids = [];
        $expectedTotal = 0;
        foreach ([1, 10, 20, 25, 30, 45, 60, 90] as $minutes) {
            $entry = ClientTimeEntry::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'client_project_id' => $project->id,
                'user_id' => $user->id,
                'worked_on' => '2026-08-23',
                'minutes' => $minutes,
                'description' => "{$minutes} minutes",
                'is_billable' => true,
                'status' => 'approved',
                'billing_rate_amount' => $rate,
                'currency' => 'USD',
            ]);
            $ids[] = $entry->public_id;
            $expectedTotal += intdiv($minutes * $rate, 60) + (($minutes * $rate) % 60 >= 30 ? 1 : 0);
        }

        $invoice = app(InvoiceFromTimeService::class)->create($workspace, $company, ['invoice_number' => 'SVC-EXACT', 'currency' => 'USD'], $ids);

        $this->assertSame($expectedTotal, $invoice->total_amount);
        $this->assertSame([1, 10, 20, 25, 30, 45, 60, 90], $invoice->lines->map(fn ($line): int => $line->timeEntries->sole()->minutes)->all());
        foreach ($invoice->lines as $line) {
            $entry = $line->timeEntries->sole();
            $expectedLine = intdiv($entry->minutes * $rate, 60) + (($entry->minutes * $rate) % 60 >= 30 ? 1 : 0);
            $this->assertSame($expectedLine, $line->total_amount, (string) $entry->minutes);
        }
    }

    public function test_it_refuses_selected_time_whose_project_belongs_to_another_company(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Invoice integrity', 'slug' => 'invoice-integrity']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Client', 'slug' => 'integrity-client']);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Other Client', 'slug' => 'integrity-other']);
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $otherCompany->id, 'name' => 'Other Project']);
        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $otherProject->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-08-23',
            'minutes' => 60,
            'description' => 'Mismatched work',
            'is_billable' => true,
            'status' => 'approved',
            'billing_rate_amount' => 12000,
            'currency' => 'USD',
        ]);

        try {
            app(InvoiceFromTimeService::class)->create(
                $workspace,
                $company,
                ['invoice_number' => 'SVC-INTEGRITY', 'currency' => 'USD'],
                [$entry->public_id],
            );
            $this->fail('A mismatched project chain must stop explicit invoicing.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('project outside this client company', $exception->getMessage());
        }

        $this->assertDatabaseCount('client_invoices', 0);
        $this->assertDatabaseCount('client_invoice_lines', 0);
        $this->assertDatabaseCount('client_invoice_line_time_entries', 0);
    }

    public function test_it_cannot_select_time_from_another_workspace(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Invoice tenant', 'slug' => 'invoice-tenant']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Client', 'slug' => 'tenant-client']);
        $otherWorkspace = Workspace::query()->create(['name' => 'Other invoice tenant', 'slug' => 'other-invoice-tenant']);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $otherWorkspace->id, 'name' => 'Other Client', 'slug' => 'other-tenant-client']);
        $otherProject = ClientProject::query()->create(['workspace_id' => $otherWorkspace->id, 'client_company_id' => $otherCompany->id, 'name' => 'Other Project']);
        $foreignEntry = ClientTimeEntry::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $otherCompany->id,
            'client_project_id' => $otherProject->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-08-23',
            'minutes' => 60,
            'description' => 'Foreign work',
            'is_billable' => true,
            'status' => 'approved',
            'billing_rate_amount' => 12000,
            'currency' => 'USD',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not found');

        app(InvoiceFromTimeService::class)->create(
            $workspace,
            $company,
            ['invoice_number' => 'SVC-TENANT', 'currency' => 'USD'],
            [$foreignEntry->public_id],
        );
    }

    public function test_flat_hourly_selected_time_uses_its_cost_snapshot_and_regenerates_at_that_rate(): void
    {
        [$workspace, $company, $project, $user] = $this->context('flat-selected');
        $entry = $this->modeEntry($workspace, $company, $project, $user, [
            'subcontractor_billing_mode' => SubcontractorBillingMode::FlatHourly,
            'subcontractor_cost_amount' => 8000,
            'subcontractor_cost_currency' => 'USD',
            // Proves the ordinary consultant rate is not used for this mode.
            'billing_rate_amount' => 12000,
        ]);

        $invoice = app(InvoiceFromTimeService::class)->create(
            $workspace,
            $company,
            ['invoice_number' => 'SVC-FLAT', 'currency' => 'USD'],
            [$entry->public_id],
        );

        $line = $invoice->lines->sole();
        $this->assertSame('subcontractor', $line->type);
        $this->assertSame(8000, $line->unit_amount);
        $this->assertSame(8000, $invoice->total_amount);

        $entry->forceFill(['minutes' => 120])->save();
        $invoice = app(InvoiceFromTimeService::class)->regenerateDraftSelection(
            $invoice,
            $workspace,
            $entry->id,
        );

        $this->assertSame('subcontractor', $invoice->lines->sole()->type);
        $this->assertSame(8000, $invoice->lines->sole()->unit_amount);
        $this->assertSame(16000, $invoice->total_amount);
    }

    public function test_retainer_mode_selected_time_uses_the_ordinary_billing_rate(): void
    {
        [$workspace, $company, $project, $user] = $this->context('retainer-selected');
        $entry = $this->modeEntry($workspace, $company, $project, $user, [
            'subcontractor_billing_mode' => SubcontractorBillingMode::Retainer,
            'billing_rate_amount' => 12000,
        ]);

        $invoice = app(InvoiceFromTimeService::class)->create(
            $workspace,
            $company,
            ['invoice_number' => 'SVC-RETAINER', 'currency' => 'USD'],
            [$entry->public_id],
        );

        $this->assertSame('time', $invoice->lines->sole()->type);
        $this->assertSame(12000, $invoice->total_amount);
    }

    public function test_direct_mode_selected_time_is_never_invoiced(): void
    {
        [$workspace, $company, $project, $user] = $this->context('direct-selected');
        $entry = $this->modeEntry($workspace, $company, $project, $user, [
            'subcontractor_billing_mode' => SubcontractorBillingMode::Direct,
            'billing_rate_amount' => 12000,
        ]);

        try {
            app(InvoiceFromTimeService::class)->create(
                $workspace,
                $company,
                ['invoice_number' => 'SVC-DIRECT', 'currency' => 'USD'],
                [$entry->public_id],
            );
            $this->fail('Direct-mode work must not be invoiceable through explicit selection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('billable by this workspace', $exception->getMessage());
        }

        $this->assertDatabaseCount('client_invoices', 0);
        $this->assertFalse($entry->invoiceLines()->exists());
    }

    public function test_flat_hourly_selected_time_requires_a_matching_cost_currency(): void
    {
        [$workspace, $company, $project, $user] = $this->context('flat-currency');
        $entry = $this->modeEntry($workspace, $company, $project, $user, [
            'subcontractor_billing_mode' => SubcontractorBillingMode::FlatHourly,
            'subcontractor_cost_amount' => 8000,
            'subcontractor_cost_currency' => 'EUR',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('currency-compatible');

        app(InvoiceFromTimeService::class)->create(
            $workspace,
            $company,
            ['invoice_number' => 'SVC-FLAT-EUR', 'currency' => 'USD'],
            [$entry->public_id],
        );
    }

    /** @return array{Workspace, ClientCompany, ClientProject, User} */
    /**
     * A manual line with no project is unattributed, not unresolvable.
     *
     * The refusal fires on "a project was named and could not be found", which
     * has to be told apart from "no project was named at all". Collapsing the
     * two either rejects every ordinary manual line - a flat fee belongs to the
     * client, not to one of their projects - or lets a line naming another
     * tenant's project through unattributed. Both readings are asserted here so
     * the distinction cannot quietly disappear.
     */
    public function test_a_manual_line_without_a_project_is_accepted_unattributed(): void
    {
        [$workspace, $company] = $this->context('unattributed-line');

        $invoice = app(InvoiceFromTimeService::class)->create(
            $workspace,
            $company,
            ['invoice_number' => 'SVC-00001', 'currency' => 'USD'],
            [],
            [['type' => 'service', 'description' => 'Retainer top-up', 'quantity' => '1', 'unit_amount' => 25000, 'tax_amount' => 0]],
        );

        $this->assertNull($invoice->lines->sole()->client_project_id);
        $this->assertSame(25000, $invoice->total_amount);

        // A project that *was* named and cannot be resolved is still refused,
        // so the assertion above is pinned to the absence rather than to the
        // lookup always succeeding.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The selected project is not available for this invoice.');

        app(InvoiceFromTimeService::class)->create(
            $workspace,
            $company,
            ['invoice_number' => 'SVC-00002', 'currency' => 'USD'],
            [],
            [['type' => 'service', 'description' => 'Elsewhere', 'quantity' => '1', 'unit_amount' => 25000, 'tax_amount' => 0, 'project_id' => 'not-a-real-project']],
        );
    }

    private function context(string $slug): array
    {
        $workspace = Workspace::query()->create(['name' => $slug, 'slug' => $slug]);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $slug.' client',
            'slug' => $slug.'-client',
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => $slug.' project',
        ]);
        $user = User::factory()->create();

        return [$workspace, $company, $project, $user];
    }

    /** @param array<string, mixed> $overrides */
    private function modeEntry(
        Workspace $workspace,
        ClientCompany $company,
        ClientProject $project,
        User $user,
        array $overrides,
    ): ClientTimeEntry {
        return ClientTimeEntry::query()->create($overrides + [
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => $user->id,
            'worked_on' => '2026-08-23',
            'minutes' => 60,
            'description' => 'Synthetic subcontractor work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }
}
