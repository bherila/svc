<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\TestCase;

class AgentReadApiTest extends TestCase
{
    use AssertsSurfaceIsolation;
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

        $this->actingAsAgent($contributor, [AgentApiScopes::PROJECTS_READ, AgentApiScopes::TIME_READ]);

        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/projects")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $project->public_id);
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/projects/{$otherProject->public_id}")
            ->assertNotFound();
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/time-entries")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->public_id)
            ->assertJsonMissingPath('data.0.billing_rate_amount')
            ->assertJsonMissingPath('data.0.subcontractor_billing_mode')
            ->assertJsonMissingPath('data.0.subcontractor_cost_amount')
            ->assertJsonMissingPath('data.0.subcontractor_cost_currency');
    }

    public function test_workspace_manager_can_read_subcontractor_billing_snapshots(): void
    {
        [$workspace, $company, $project] = $this->project();
        $owner = User::factory()->create();
        $this->workspaceMember($workspace, $owner, 'owner');
        $entry = $this->time($workspace, $company, $project, $owner, 'Flat subcontractor work', [
            'subcontractor_billing_mode' => 'flat_hourly',
            'subcontractor_cost_amount' => 8000,
            'subcontractor_cost_currency' => 'USD',
        ]);
        $this->actingAsAgent($owner, [AgentApiScopes::TIME_READ]);

        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/time-entries")
            ->assertOk()
            ->assertJsonPath('data.0.id', $entry->public_id)
            ->assertJsonPath('data.0.subcontractor_billing_mode', 'flat_hourly')
            ->assertJsonPath('data.0.subcontractor_cost_amount', 8000)
            ->assertJsonPath('data.0.subcontractor_cost_currency', 'USD');
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

        $this->actingAsAgent($client, [AgentApiScopes::TIME_READ, AgentApiScopes::BILLING_READ]);

        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/time-entries")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->public_id)
            ->assertJsonPath('data.0.description', 'Client summary');
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/invoices")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $invoice->public_id)
            ->assertJsonMissingPath('data.0.notes');
    }

    public function test_client_cannot_read_a_hidden_task_directly(): void
    {
        [$workspace, $company, $project] = $this->project();
        $client = User::factory()->create();
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $client->id, 'role' => 'client']);
        $hidden = ClientTask::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'title' => 'Hidden task must stay private',
            'is_visible_to_client' => false,
        ]);
        $visible = ClientTask::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'title' => 'Visible task',
            'is_visible_to_client' => true,
        ]);
        $this->actingAsAgent($client, [AgentApiScopes::TASKS_READ]);

        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/tasks/{$hidden->public_id}")
            ->assertNotFound();
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/tasks/{$visible->public_id}")
            ->assertOk()
            ->assertJsonPath('data.title', $visible->title);
    }

    public function test_cursor_query_mismatch_is_a_client_error(): void
    {
        [$workspace] = $this->project();
        $owner = User::factory()->create();
        $this->workspaceMember($workspace, $owner, 'owner');
        $cursor = AgentApiCursor::encode(1, $workspace->public_id, 'projects|status=active|search=');
        $this->actingAsAgent($owner, [AgentApiScopes::PROJECTS_READ]);

        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/projects?cursor=".urlencode($cursor))
            ->assertStatus(422)
            ->assertJson(['message' => 'The pagination cursor is not valid for this request.']);
    }

    public function test_legacy_client_visible_time_never_falls_back_to_internal_description(): void
    {
        [$workspace, $company, $project] = $this->project();
        $client = User::factory()->create();
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $client->id, 'role' => 'client']);
        $worker = User::factory()->create();
        $this->workspaceMember($workspace, $worker, 'member');
        $visible = $this->time($workspace, $company, $project, $worker, 'Internal text must stay private', [
            'status' => 'approved',
            'is_visible_to_client' => true,
            'client_visible_description' => null,
        ]);
        $this->actingAsAgent($client, [AgentApiScopes::TIME_READ]);

        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/time-entries")
            ->assertOk()
            ->assertJsonPath('data.0.id', $visible->public_id)
            ->assertJsonPath('data.0.description', null)
            ->assertJsonMissing(['Internal text must stay private']);
    }

    public function test_nothing_invisible_reaches_the_contributor_time_listing(): void
    {
        [$workspace, $company, $project] = $this->project();
        $contributor = User::factory()->create();
        $colleague = User::factory()->create(['name' => 'Colleague Worker Name']);
        $this->workspaceMember($workspace, $contributor, 'member');
        $this->workspaceMember($workspace, $colleague, 'member');
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $contributor->id, 'role' => 'contributor']);
        $this->time($workspace, $company, $project, $contributor, 'Own synthetic work');
        $this->time($workspace, $company, $project, $colleague, 'Colleague private work');

        $foreignWorker = User::factory()->create(['name' => 'Foreign Tenant Worker']);
        $foreign = Workspace::query()->create(['name' => 'Foreign Tenant', 'slug' => 'foreign-tenant']);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $foreign->id, 'name' => 'Foreign Tenant Client', 'slug' => 'foreign-tenant-client']);
        $foreignProject = ClientProject::query()->create(['workspace_id' => $foreign->id, 'client_company_id' => $foreignCompany->id, 'name' => 'Foreign Tenant Project']);
        $this->workspaceMember($foreign, $foreignWorker, 'member');
        $this->time($foreign, $foreignCompany, $foreignProject, $foreignWorker, 'Foreign colleague work');

        // The contributor themselves owns a row in the foreign workspace and
        // holds contributor membership on its project, so every ownership and
        // membership predicate in visibleTo() matches it — the workspace
        // predicate is the only thing keeping it out of this listing, which
        // is exactly the boundary this test must pin on its own.
        $this->workspaceMember($foreign, $contributor, 'member');
        ClientProjectMembership::query()->create(['workspace_id' => $foreign->id, 'client_project_id' => $foreignProject->id, 'user_id' => $contributor->id, 'role' => 'contributor']);
        $this->time($foreign, $foreignCompany, $foreignProject, $contributor, 'Foreign tenant work');

        $this->actingAsAgent($contributor, [AgentApiScopes::TIME_READ]);

        $response = $this->getJson("/api/v1/workspaces/{$workspace->public_id}/time-entries")->assertOk();

        $this->assertJsonPayloadOmits($response, [
            'Colleague private work',
            'Colleague Worker Name',
            'Foreign tenant work',
            'Foreign colleague work',
            'Foreign Tenant Worker',
            'Foreign Tenant Project',
            'billing_rate_amount',
            'subcontractor_cost_amount',
        ], 'Own synthetic work');
    }

    public function test_the_time_listing_names_only_columns_that_exist(): void
    {
        [$workspace, $company, $project] = $this->project();
        $owner = User::factory()->create();
        $this->workspaceMember($workspace, $owner, 'owner');
        $this->time($workspace, $company, $project, $owner, 'Synthetic listing work');

        $this->actingAsAgent($owner, [AgentApiScopes::TIME_READ]);

        $this->assertQueriesNameOnlyRealIdentifiers(
            fn () => $this->getJson("/api/v1/workspaces/{$workspace->public_id}/time-entries")->assertOk(),
        );
    }

    public function test_the_time_listing_does_not_query_once_per_entry(): void
    {
        [$workspace, $company, $project] = $this->project();
        $owner = User::factory()->create();
        $this->workspaceMember($workspace, $owner, 'owner');

        for ($i = 0; $i < 3; $i++) {
            $this->time($workspace, $company, $project, $owner, 'Synthetic row work');
        }

        $this->actingAsAgent($owner, [AgentApiScopes::TIME_READ]);

        $this->assertQueryCountIndependentOfRows(
            fn () => $this->getJson("/api/v1/workspaces/{$workspace->public_id}/time-entries")->assertOk(),
            function () use ($workspace, $company, $project, $owner): void {
                for ($i = 0; $i < 17; $i++) {
                    $this->time($workspace, $company, $project, $owner, 'Synthetic row work');
                }
            },
        );
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

    /** @param list<string> $scopes */
    private function actingAsAgent(User $user, array $scopes): void
    {
        Passport::actingAs(AgentPrincipal::query()->findOrFail($user->id), $scopes);
    }
}
