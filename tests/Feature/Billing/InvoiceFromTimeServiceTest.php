<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceFromTimeService;
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
        $this->expectException(\DomainException::class);
        app(InvoiceFromTimeService::class)->create($workspace, $company, ['invoice_number' => 'SVC-00002', 'currency' => 'USD'], [$entry->public_id]);
    }
}
