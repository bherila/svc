<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceFromTimeService;
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
}
