<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\AgentApi\AgentBillingAuditReadService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AgentBillingAuditReadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reuses_the_workspace_scoped_aggregate_audits(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Audit workspace', 'slug' => 'audit-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Audit client', 'slug' => 'audit-client']);
        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Audit agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'billing_cadence' => 'monthly',
            'retainer_minutes' => 600,
            'initial_rollover_minutes' => 600,
            'rollover_months' => 1,
        ]);
        ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'invoice_number' => 'SYN-AUDIT-001',
            'status' => 'issued',
            'balance_amount' => 10000,
            'currency' => 'USD',
        ]);
        $foreignWorkspace = Workspace::query()->create(['name' => 'Foreign audit workspace', 'slug' => 'foreign-audit-workspace']);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $foreignWorkspace->id, 'name' => 'Foreign audit client', 'slug' => 'foreign-audit-client']);
        ClientAgreement::query()->create([
            'workspace_id' => $foreignWorkspace->id,
            'client_company_id' => $foreignCompany->id,
            'title' => 'Foreign audit agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'billing_cadence' => 'monthly',
            'retainer_minutes' => 1200,
            'initial_rollover_minutes' => 1200,
            'rollover_months' => 2,
        ]);
        ClientInvoice::query()->create([
            'workspace_id' => $foreignWorkspace->id,
            'client_company_id' => $foreignCompany->id,
            'invoice_number' => 'SYN-AUDIT-FOREIGN-001',
            'status' => 'issued',
            'balance_amount' => 25000,
            'currency' => 'USD',
        ]);

        $service = app(AgentBillingAuditReadService::class);
        $principal = AgentPrincipal::query()->findOrFail($user->id);

        $unplaceable = $service->unplaceableInvoices($principal, $workspace);
        $undated = $service->undatedCollectibleInvoices($principal, $workspace);
        $overage = $service->missingBilledOverage($principal, $workspace);
        $openingRollover = $service->openingRollover($principal, $workspace);

        $this->assertSame(1, $unplaceable['invoices']);
        $this->assertSame(1, $undated['invoices']);
        $this->assertSame(['USD' => 10000], $undated['undated_balances']);
        $this->assertSame(1, $overage['invoices']);
        $this->assertSame(1, $openingRollover['agreements']);
        $this->assertSame(1, $openingRollover['affected']);
        $this->assertSame(600, $openingRollover['capacity_at_stake_minutes']);
        $this->assertArrayNotHasKey('agreement_id', $openingRollover);
        $this->assertArrayNotHasKey('invoice_number', $undated);
    }

    public function test_it_denies_a_non_manager_without_disclosing_a_workspace_audit(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Private audit workspace', 'slug' => 'private-audit-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'member']);

        $this->expectException(ModelNotFoundException::class);
        app(AgentBillingAuditReadService::class)->unplaceableInvoices(
            AgentPrincipal::query()->findOrFail($user->id),
            $workspace,
        );
    }
}
