<?php

namespace Tests\Feature;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\TestCase;

class ClientPortalOperationsTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Route::has('svc.engagement.proposals.store')) {
            require base_path('routes/engagement.php');
        }
    }

    public function test_portal_scopes_visible_engagement_billing_and_project_records_to_the_company(): void
    {
        [$admin, $clientUser, $outsider, $workspace, $company, $otherCompany] = $this->tenant();

        $visibleProject = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Visible synthetic project',
            'description' => 'Client-facing project.',
            'status' => 'active',
            'is_visible_to_client' => true,
        ]);
        ClientTask::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $visibleProject->id,
            'title' => 'Visible synthetic task',
            'status' => 'open',
            'is_visible_to_client' => true,
        ]);
        ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Internal synthetic project',
            'status' => 'active',
            'is_visible_to_client' => false,
        ]);

        $this->proposal($workspace, $company, 'Visible sent proposal', 'sent', true);
        $this->proposal($workspace, $company, 'Hidden draft proposal', 'draft', true);
        $this->proposal($workspace, $company, 'Internal sent proposal', 'sent', false);
        $this->proposal($workspace, $otherCompany, 'Other company proposal', 'sent', true);
        $this->proposal($workspace, $company, 'Visible accepted proposal', 'accepted', true);

        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Visible synthetic agreement',
            'starts_on' => '2024-01-01',
            'status' => 'active',
            'agreement_text' => 'Client-facing agreement text.',
            'is_visible_to_client' => true,
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
        ]);
        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Visible but draft agreement',
            'starts_on' => '2024-01-01',
            'status' => 'draft',
            'is_visible_to_client' => true,
            'currency' => 'USD',
            'billing_cadence' => 'one_time',
        ]);
        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $otherCompany->id,
            'title' => 'Other company agreement',
            'starts_on' => '2024-01-01',
            'status' => 'active',
            'is_visible_to_client' => true,
            'currency' => 'USD',
            'billing_cadence' => 'one_time',
        ]);

        foreach (['issued', 'partially_paid', 'paid'] as $index => $status) {
            $this->invoice($workspace, $company, 'INV-SYNTH-'.($index + 1), $status, true);
        }
        $this->invoice($workspace, $company, 'INV-SYNTH-DRAFT', 'draft', true);
        $this->invoice($workspace, $company, 'INV-SYNTH-HIDDEN', 'issued', false);
        $this->invoice($workspace, $otherCompany, 'INV-OTHER', 'paid', true);

        $this->actingAs($clientUser)
            ->get("/portal/{$company->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portal')
                ->has('company.proposals', 2)
                ->where('company.proposals.0.status', 'accepted')
                ->where('company.proposals.1.title', 'Visible sent proposal')
                ->has('company.agreements', 1)
                ->where('company.agreements.0.title', 'Visible synthetic agreement')
                ->has('company.invoices', 3)
                ->where('company.invoices.0.status', 'paid')
                ->where('company.invoices.0.total_amount', 10000)
                ->where('company.invoices.0.balance_amount', 0)
                ->where('company.invoices.0.pdf_url', "/workspaces/{$workspace->public_id}/invoices/".ClientInvoice::query()->where('invoice_number', 'INV-SYNTH-3')->value('public_id').'/pdf')
                ->has('company.projects', 1)
                ->where('company.projects.0.name', 'Visible synthetic project')
                ->has('company.projects.0.tasks', 1));

        $this->actingAs($admin)->get("/portal/{$company->public_id}")->assertOk();
        $this->actingAs($outsider)->get("/portal/{$company->public_id}")->assertForbidden();
    }

    public function test_visible_sent_proposal_acceptance_is_idempotent_for_a_client_user(): void
    {
        [$admin, $clientUser, , $workspace, $company] = $this->tenant();
        $proposal = $this->proposal($workspace, $company, 'Acceptance proposal', 'draft', true);

        $this->actingAs($admin)
            ->postJson("/workspaces/{$workspace->public_id}/proposals/{$proposal->public_id}/send")
            ->assertOk();

        $acceptPath = "/portal/{$company->public_id}/proposals/{$proposal->public_id}/accept";
        $this->actingAs($clientUser)->postJson($acceptPath, [
            'signer_name' => 'Synthetic Portal Signer',
            'signer_title' => 'Synthetic Buyer',
        ])->assertOk();
        $this->actingAs($clientUser)->postJson($acceptPath, [
            'signer_name' => 'Replay Signer',
            'signer_title' => 'Replay Title',
        ])->assertOk();

        $this->assertSame('accepted', $proposal->fresh()->status);
        $this->assertSame('Synthetic Portal Signer', $proposal->fresh()->acceptance_signer_name);
        $this->assertSame(1, ClientAgreement::query()->where('source_proposal_id', $proposal->id)->count());
    }

    public function test_nothing_invisible_reaches_the_portal_payload(): void
    {
        [, $clientUser, , $workspace, $company, $otherCompany] = $this->tenant();

        $visibleProject = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Visible synthetic project',
            'status' => 'active',
            'is_visible_to_client' => true,
        ]);
        ClientTask::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $visibleProject->id,
            'title' => 'Visible synthetic task',
            'status' => 'open',
            'is_visible_to_client' => true,
        ]);
        ClientTask::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $visibleProject->id,
            'title' => 'Internal Only Task Title',
            'status' => 'open',
            'is_visible_to_client' => false,
        ]);
        $internalProject = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Internal Project Name',
            'status' => 'active',
            'is_visible_to_client' => false,
        ]);
        ClientTask::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $internalProject->id,
            'title' => 'Internal Task Title',
            'status' => 'open',
            'is_visible_to_client' => true,
        ]);

        $this->proposal($workspace, $company, 'Visible sent proposal', 'sent', true);
        $this->proposal($workspace, $company, 'Hidden Draft Proposal Title', 'draft', true);
        $this->proposal($workspace, $company, 'Internal Proposal Title', 'sent', false);
        $this->proposal($workspace, $otherCompany, 'Other Company Proposal Title', 'sent', true);

        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Internal Agreement Title',
            'starts_on' => '2024-01-01',
            'status' => 'active',
            'is_visible_to_client' => false,
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
        ]);
        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $otherCompany->id,
            'title' => 'Other Company Agreement Title',
            'starts_on' => '2024-01-01',
            'status' => 'active',
            'is_visible_to_client' => true,
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
        ]);

        $this->invoice($workspace, $company, 'INV-VIS-1', 'issued', true);
        $this->invoice($workspace, $company, 'INV-HIDDEN-1', 'issued', false);
        $this->invoice($workspace, $company, 'INV-DRAFT-1', 'draft', true);
        $this->invoice($workspace, $otherCompany, 'INV-OTHER-1', 'paid', true);

        $worker = User::factory()->create(['name' => 'Internal Worker Name']);
        ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $visibleProject->id,
            'user_id' => $worker->id,
            'worked_on' => '2026-08-20',
            'minutes' => 60,
            'description' => 'Internal Note Never Shown',
            'client_visible_description' => 'Client Facing Summary',
            'is_visible_to_client' => true,
            'status' => 'approved',
        ]);
        ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $visibleProject->id,
            'user_id' => $worker->id,
            'worked_on' => '2026-08-21',
            'minutes' => 60,
            'description' => 'Hidden Time Description',
            'is_visible_to_client' => false,
            'status' => 'approved',
        ]);

        $response = $this->actingAs($clientUser)
            ->get("/portal/{$company->public_id}")
            ->assertOk();

        $this->assertInertiaPayloadOmits($response, [
            'Internal Project Name',
            'Internal Task Title',
            'Internal Only Task Title',
            'Hidden Draft Proposal Title',
            'Internal Proposal Title',
            'Other Company Proposal Title',
            'Internal Agreement Title',
            'Other Company Agreement Title',
            'INV-HIDDEN-1',
            'INV-DRAFT-1',
            'INV-OTHER-1',
            'Internal Note Never Shown',
            'Hidden Time Description',
            'billing_rate_amount',
        ], 'Visible sent proposal');
    }

    public function test_the_portal_names_only_columns_that_exist(): void
    {
        [, $clientUser, , $workspace, $company] = $this->tenant();

        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Visible synthetic project',
            'status' => 'active',
            'is_visible_to_client' => true,
        ]);
        ClientTask::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'title' => 'Visible synthetic task',
            'status' => 'open',
            'is_visible_to_client' => true,
        ]);
        $this->proposal($workspace, $company, 'Visible sent proposal', 'sent', true);
        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Visible synthetic agreement',
            'starts_on' => '2024-01-01',
            'status' => 'active',
            'is_visible_to_client' => true,
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
        ]);
        $this->invoice($workspace, $company, 'INV-IDENT-1', 'issued', true);
        $worker = User::factory()->create();
        ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => $worker->id,
            'worked_on' => '2026-08-20',
            'minutes' => 60,
            'description' => 'Synthetic internal note',
            'client_visible_description' => 'Synthetic client summary',
            'is_visible_to_client' => true,
            'status' => 'approved',
        ]);

        $this->assertQueriesNameOnlyRealIdentifiers(
            fn () => $this->actingAs($clientUser)
                ->get("/portal/{$company->public_id}")
                ->assertOk(),
        );
    }

    public function test_the_portal_does_not_query_once_per_row(): void
    {
        [, $clientUser, , $workspace, $company] = $this->tenant();

        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Visible synthetic project',
            'status' => 'active',
            'is_visible_to_client' => true,
        ]);
        $worker = User::factory()->create();

        $sequence = 0;
        $addRows = function (int $count) use ($workspace, $company, $project, $worker, &$sequence): void {
            for ($i = 0; $i < $count; $i++) {
                $sequence++;
                ClientTask::query()->create([
                    'workspace_id' => $workspace->id,
                    'client_project_id' => $project->id,
                    'title' => "Visible synthetic task {$sequence}",
                    'status' => 'open',
                    'is_visible_to_client' => true,
                ]);
                $this->proposal($workspace, $company, "Visible sent proposal {$sequence}", 'sent', true);
                $this->invoice($workspace, $company, "INV-ROWS-{$sequence}", 'issued', true);
                ClientTimeEntry::query()->create([
                    'workspace_id' => $workspace->id,
                    'client_company_id' => $company->id,
                    'client_project_id' => $project->id,
                    'user_id' => $worker->id,
                    'worked_on' => '2026-08-20',
                    'minutes' => 30,
                    'description' => "Synthetic internal note {$sequence}",
                    'client_visible_description' => "Synthetic client summary {$sequence}",
                    'is_visible_to_client' => true,
                    'status' => 'approved',
                ]);
            }
        };

        $addRows(2);

        $this->assertQueryCountIndependentOfRows(
            fn () => $this->actingAs($clientUser)
                ->get("/portal/{$company->public_id}")
                ->assertOk(),
            fn () => $addRows(8),
        );
    }

    /** @return array{0: User, 1: User, 2: User, 3: Workspace, 4: ClientCompany, 5: ClientCompany} */
    private function tenant(): array
    {
        $admin = User::factory()->create(['email' => 'portal-admin@synthetic.test']);
        $clientUser = User::factory()->create(['email' => 'portal-client@synthetic.test']);
        $outsider = User::factory()->create(['email' => 'portal-outsider@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic Portal Workspace', 'slug' => 'synthetic-portal-workspace-'.uniqid()]);
        $workspace->memberships()->create(['user_id' => $admin->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Portal Client', 'slug' => 'synthetic-portal-client-'.uniqid()]);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Other Synthetic Client', 'slug' => 'other-synthetic-client-'.uniqid()]);
        $company->portalUsers()->attach($clientUser, ['role' => 'client']);

        return [$admin, $clientUser, $outsider, $workspace, $company, $otherCompany];
    }

    private function proposal(Workspace $workspace, ClientCompany $company, string $title, string $status, bool $visible): ClientProposal
    {
        return ClientProposal::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => $title,
            'summary' => 'Synthetic summary.',
            'terms' => 'Synthetic terms.',
            'currency' => 'USD',
            'is_visible_to_client' => $visible,
            'status' => $status,
            'sent_at' => $status !== 'draft' ? now() : null,
            'accepted_at' => $status === 'accepted' ? now() : null,
            'acceptance_signer_name' => $status === 'accepted' ? 'Synthetic Signer' : null,
        ]);
    }

    private function invoice(Workspace $workspace, ClientCompany $company, string $number, string $status, bool $visible): ClientInvoice
    {
        return ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'invoice_number' => $number,
            'status' => $status,
            'issue_date' => '2026-08-15',
            'due_date' => '2026-09-14',
            'service_period_start' => '2026-08-01',
            'service_period_end' => '2026-08-31',
            'currency' => 'USD',
            'subtotal_amount' => 10000,
            'tax_amount' => 0,
            'total_amount' => 10000,
            'paid_amount' => $status === 'paid' ? 10000 : 0,
            'balance_amount' => $status === 'paid' ? 0 : 10000,
            'is_visible_to_client' => $visible,
        ]);
    }
}
