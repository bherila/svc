<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_contributor_reads_only_assigned_project_and_own_time_without_financial_fields(): void
    {
        [$workspace, $company, $project] = $this->project();
        $contributor = User::factory()->create();
        $other = User::factory()->create();
        $this->workspaceMember($workspace, $contributor, 'member');
        $this->workspaceMember($workspace, $other, 'member');
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $contributor->id, 'role' => 'contributor']);
        $own = $this->time($workspace, $company, $project, $contributor, 'Own work');
        $this->time($workspace, $company, $project, $other, 'Other work');
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Unassigned']);

        Sanctum::actingAs($contributor, [AgentApiScopes::PROJECTS_READ, AgentApiScopes::TIME_READ]);

        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/projects")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $project->public_id);
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/projects/{$otherProject->public_id}")
            ->assertNotFound();
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/time-entries")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->public_id)
            ->assertJsonMissingPath('data.0.billing_rate_amount');
    }

    public function test_client_reads_only_visible_issued_invoices_and_client_visible_approved_time(): void
    {
        [$workspace, $company, $project] = $this->project();
        $client = User::factory()->create();
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $client->id, 'role' => 'client']);
        $worker = User::factory()->create();
        $this->workspaceMember($workspace, $worker, 'member');
        $visible = $this->time($workspace, $company, $project, $worker, 'Internal text', ['status' => 'approved', 'is_visible_to_client' => true, 'client_visible_description' => 'Client summary']);
        $this->time($workspace, $company, $project, $worker, 'Hidden text', ['status' => 'approved']);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'INV-1', 'status' => 'issued', 'currency' => 'USD', 'is_visible_to_client' => true]);
        ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'INV-2', 'status' => 'draft', 'currency' => 'USD', 'is_visible_to_client' => true]);

        Sanctum::actingAs($client, [AgentApiScopes::TIME_READ, AgentApiScopes::BILLING_READ]);

        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/time-entries")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->public_id)
            ->assertJsonPath('data.0.description', 'Client summary');
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/invoices")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $invoice->public_id)
            ->assertJsonMissingPath('data.0.notes');
    }

    /** @return array{Workspace, ClientCompany, ClientProject} */
    private function project(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Agent Workspace', 'slug' => 'agent-workspace']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Agent Client', 'slug' => 'agent-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Assigned']);

        return [$workspace, $company, $project];
    }

    /** @param array<string, mixed> $extra */
    private function time(Workspace $workspace, ClientCompany $company, ClientProject $project, User $user, string $description, array $extra = []): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create($extra + ['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'user_id' => $user->id, 'worked_on' => '2026-08-20', 'minutes' => 60, 'description' => $description, 'billing_rate_amount' => 15000, 'currency' => 'USD']);
    }

    private function workspaceMember(Workspace $workspace, User $user, string $role): void
    {
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role]);
    }
}
