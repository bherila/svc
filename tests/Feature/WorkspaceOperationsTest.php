<?php

namespace Tests\Feature;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkspaceOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_manager_can_use_the_integrated_operations_screen(): void
    {
        $manager = User::factory()->create();
        $outsider = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Operations', 'slug' => 'synthetic-operations']);
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Client',
            'slug' => 'synthetic-client',
        ]);
        ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Synthetic Project',
            'status' => 'active',
        ]);
        ClientProposal::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Synthetic Proposal',
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Synthetic Agreement',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
        ]);
        ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_agreement_id' => $agreement->id,
            'cadence' => 'monthly',
            'next_run_on' => '2026-09-01',
            'due_days' => 30,
            'currency' => 'USD',
            'is_active' => true,
            'line_template' => [['type' => 'service', 'description' => 'Synthetic service', 'quantity' => '1', 'unit_amount' => 10000]],
        ]);
        ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'invoice_number' => 'SYN-001',
            'status' => 'issued',
            'currency' => 'USD',
            'subtotal_amount' => 10000,
            'tax_amount' => 0,
            'total_amount' => 10000,
            'paid_amount' => 0,
            'balance_amount' => 10000,
        ]);

        $operationsPath = "/workspaces/{$workspace->public_id}/operations";
        $this->actingAs($manager)
            ->get($operationsPath)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations')
                ->where('workspace.id', $workspace->public_id)
                ->has('workspace.clients', 1)
                ->where('workspace.clients.0.id', $company->public_id)
                ->has('workspace.clients.0.projects', 1)
                ->has('workspace.clients.0.proposals', 1)
                ->has('workspace.clients.0.agreements', 1)
                ->has('workspace.clients.0.billing_schedules', 1)
                ->where('workspace.clients.0.billing_schedules.0.agreement_id', $agreement->public_id)
                ->has('workspace.clients.0.invoices', 1));

        $this->actingAs($manager)->from($operationsPath)->post(
            "/workspaces/{$workspace->public_id}/clients/{$company->public_id}/invoices",
            [
                'invoice_number' => 'SYN-002',
                'currency' => 'USD',
                'lines' => [[
                    'type' => 'service',
                    'description' => 'Synthetic service',
                    'quantity' => '1',
                    'unit_amount' => 2500,
                    'tax_amount' => 0,
                    'sort_order' => 0,
                ]],
            ],
        )->assertRedirect($operationsPath);
        $this->assertDatabaseHas('client_invoices', [
            'workspace_id' => $workspace->id,
            'invoice_number' => 'SYN-002',
            'total_amount' => 2500,
        ]);

        $this->actingAs($outsider)->get($operationsPath)->assertForbidden();
    }

    public function test_identity_models_expose_public_ids_without_serializing_internal_keys(): void
    {
        $user = User::factory()->create();
        $clientUser = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Identity', 'slug' => 'synthetic-identity']);
        $membership = $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'admin']);
        $workspace->users()->attach($clientUser, ['role' => 'member']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Identity Client',
            'slug' => 'synthetic-identity-client',
        ]);
        $company->portalUsers()->attach($clientUser, ['role' => 'client']);

        $this->assertTrue(Str::isUuid($user->public_id));
        $this->assertTrue(Str::isUuid($membership->public_id));
        $this->assertTrue(Str::isUuid((string) $workspace->users()->whereKey($clientUser->id)->firstOrFail()->pivot->public_id));
        $this->assertTrue(Str::isUuid((string) $company->portalUsers()->firstOrFail()->pivot->public_id));
        $this->assertArrayNotHasKey('id', $user->toArray());
        $this->assertArrayNotHasKey('id', $membership->toArray());
        $this->assertArrayNotHasKey('workspace_id', $membership->toArray());
        $this->assertArrayNotHasKey('user_id', $membership->toArray());
    }
}
