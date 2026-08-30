<?php

namespace Tests\Feature;

use App\Models\ClientAgreement;
use App\Models\ClientAttachment;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

class WorkspaceOperationsTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

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

    public function test_operations_activity_is_workspace_scoped_and_bounded_per_company(): void
    {
        $manager = User::factory()->create(['name' => 'Synthetic Manager']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic Activity', 'slug' => 'synthetic-activity']);
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Client',
            'slug' => 'synthetic-client',
        ]);

        foreach (range(1, 101) as $eventNumber) {
            $activity = ClientCompanyActivity::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'actor_user_id' => $manager->id,
                'action' => $eventNumber === 101 ? 'agreement.transitioned' : 'invoice.generated',
                'subject_type' => $eventNumber === 101 ? 'client_agreement' : null,
                'subject_public_id' => $eventNumber === 101 ? '11111111-1111-4111-8111-111111111111' : null,
                'payload' => ['invoice_kind' => 'cadence_period'],
            ]);
            $activity->timestamps = false;
            $activity->forceFill([
                'created_at' => now()->subMinutes(101 - $eventNumber),
                'updated_at' => now()->subMinutes(101 - $eventNumber),
            ])->save();
        }

        $otherWorkspace = Workspace::query()->create(['name' => 'Foreign Activity', 'slug' => 'foreign-activity']);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Foreign Client',
            'slug' => 'foreign-client',
        ]);
        ClientCompanyActivity::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $otherCompany->id,
            'action' => 'foreign.secret',
            'payload' => ['changes' => ['private' => ['before', 'after']]],
        ]);

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/operations")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('workspace.clients', 1)
                ->has('workspace.clients.0.activities', 100)
                ->where('workspace.clients.0.activities.0.action', 'agreement.transitioned')
                ->where('workspace.clients.0.activities.0.actor_name', 'Synthetic Manager')
                ->where('workspace.clients.0.activities.0.subject_type', 'client_agreement')
                ->where('workspace.clients.0.activities.0.subject_id', '11111111-1111-4111-8111-111111111111')
                ->where('workspace.clients.0.activities.0.payload.invoice_kind', 'cadence_period')
                ->where('workspace.clients.0.activities', fn (Collection $activities): bool => $activities
                    ->pluck('action')
                    ->doesntContain('foreign.secret')));
    }

    public function test_operations_queries_are_bounded_and_attachments_are_record_scoped(): void
    {
        $manager = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Query Scope', 'slug' => 'synthetic-query-scope']);
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'admin']);

        foreach (range(1, 4) as $companyNumber) {
            $company = ClientCompany::query()->create([
                'workspace_id' => $workspace->id,
                'name' => "Synthetic Client {$companyNumber}",
                'slug' => "synthetic-client-{$companyNumber}",
            ]);
            $project = ClientProject::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'name' => "Synthetic Project {$companyNumber}",
                'status' => 'active',
            ]);
            $proposal = ClientProposal::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'title' => "Synthetic Proposal {$companyNumber}",
                'currency' => 'USD',
                'status' => 'draft',
            ]);

            foreach (range(1, 26) as $entryNumber) {
                ClientTimeEntry::query()->create([
                    'workspace_id' => $workspace->id,
                    'client_company_id' => $company->id,
                    'client_project_id' => $project->id,
                    'user_id' => $manager->id,
                    'worked_on' => '2026-01-'.str_pad((string) $entryNumber, 2, '0', STR_PAD_LEFT),
                    'minutes' => $entryNumber,
                    'description' => "Synthetic time entry {$companyNumber}-{$entryNumber}",
                    'is_billable' => true,
                    'is_deferred' => false,
                    'status' => 'draft',
                ]);
            }

            ClientAttachment::query()->create([
                'workspace_id' => $workspace->id,
                'record_type' => 'proposal',
                'record_public_id' => $proposal->public_id,
                'object_key' => "synthetic/query-scope/proposal-{$companyNumber}.txt",
                'original_filename' => "synthetic-proposal-{$companyNumber}.txt",
                'media_type' => 'text/plain',
                'bytes' => 32,
                'sha256' => hash('sha256', "synthetic-proposal-{$companyNumber}"),
                'uploader_id' => $manager->id,
                'lifecycle_state' => ClientAttachment::STATE_AVAILABLE,
                'available_at' => now(),
            ]);
            ClientAttachment::query()->create([
                'workspace_id' => $workspace->id,
                'record_type' => 'company',
                'record_public_id' => $company->public_id,
                'object_key' => "synthetic/query-scope/company-{$companyNumber}.txt",
                'original_filename' => "synthetic-company-{$companyNumber}.txt",
                'media_type' => 'text/plain',
                'bytes' => 32,
                'sha256' => hash('sha256', "synthetic-company-{$companyNumber}"),
                'uploader_id' => $manager->id,
                'lifecycle_state' => ClientAttachment::STATE_AVAILABLE,
                'available_at' => now(),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($manager)->get("/workspaces/{$workspace->public_id}/operations");

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('workspace.clients', 4)
            ->has('workspace.clients.0.projects.0.time_entries', 25)
            ->has('workspace.clients.0.proposals.0.attachments', 1));

        $queries = DB::getQueryLog();
        $this->assertLessThanOrEqual(12, count($queries));

        $attachmentQueries = collect($queries)->filter(
            fn (array $query): bool => str_contains($query['query'], 'client_attachments'),
        );
        $this->assertCount(1, $attachmentQueries);
        $this->assertNotContains('company', $attachmentQueries->first()['bindings']);
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

    public function test_nothing_from_another_workspace_reaches_the_operations_payload(): void
    {
        $manager = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Visible', 'slug' => 'synthetic-visible']);
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Visible Client',
            'slug' => 'synthetic-visible-client',
        ]);
        $visibleProposal = ClientProposal::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Visible Synthetic Proposal',
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        $visibleProject = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Synthetic Visible Project',
            'status' => 'active',
        ]);

        $foreignWorker = User::factory()->create(['name' => 'Foreign Worker Name']);
        $foreign = Workspace::query()->create(['name' => 'Foreign Tenant Name', 'slug' => 'foreign-tenant']);
        $foreignCompany = ClientCompany::query()->create([
            'workspace_id' => $foreign->id,
            'name' => 'Foreign Client Name',
            'slug' => 'foreign-client-name',
        ]);
        $foreignProject = ClientProject::query()->create([
            'workspace_id' => $foreign->id,
            'client_company_id' => $foreignCompany->id,
            'name' => 'Foreign Project Name',
            'status' => 'active',
        ]);
        ClientProposal::query()->create([
            'workspace_id' => $foreign->id,
            'client_company_id' => $foreignCompany->id,
            'title' => 'Foreign Proposal Title',
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        ClientAgreement::query()->create([
            'workspace_id' => $foreign->id,
            'client_company_id' => $foreignCompany->id,
            'title' => 'Foreign Agreement Title',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'status' => 'active',
        ]);
        ClientInvoice::query()->create([
            'workspace_id' => $foreign->id,
            'client_company_id' => $foreignCompany->id,
            'invoice_number' => 'FOREIGN-INV-7777',
            'status' => 'issued',
            'currency' => 'USD',
            'subtotal_amount' => 10000,
            'tax_amount' => 0,
            'total_amount' => 10000,
        ]);
        ClientCompanyActivity::query()->create([
            'workspace_id' => $foreign->id,
            'client_company_id' => $foreignCompany->id,
            'actor_user_id' => $foreignWorker->id,
            'action' => 'invoice.generated',
            'payload' => ['note' => 'Foreign Activity Payload'],
        ]);
        ClientTimeEntry::query()->create([
            'workspace_id' => $foreign->id,
            'client_company_id' => $foreignCompany->id,
            'client_project_id' => $foreignProject->id,
            'user_id' => $foreignWorker->id,
            'worked_on' => '2026-08-20',
            'minutes' => 60,
            'description' => 'Foreign Time Description',
            'status' => 'draft',
        ]);

        // Cross-tenant child rows: a foreign workspace_id pointing at the
        // VISIBLE company and project. The parent whereIn filters cannot
        // exclude these, so only the workspace predicate on each query keeps
        // them out — which is exactly what this test must pin independently.
        // Since #113 these rows are unrepresentable, which is the stronger
        // guarantee - but the scoped query is still the second line of
        // defence for a database migrated from before those keys, and it
        // can only be proved against a row that exists. So the fixture is
        // written with enforcement suspended, and the helper asserts that
        // enforcement is back before the assertion phase runs.
        $this->writingLegacyCrossTenantRows(function () use ($foreign, $company, $foreignWorker, $visibleProject): void {
            ClientProposal::query()->create([
                'workspace_id' => $foreign->id,
                'client_company_id' => $company->id,
                'title' => 'Mixed Tenant Proposal Title',
                'currency' => 'USD',
                'status' => 'draft',
            ]);
            ClientAgreement::query()->create([
                'workspace_id' => $foreign->id,
                'client_company_id' => $company->id,
                'title' => 'Mixed Tenant Agreement Title',
                'currency' => 'USD',
                'billing_cadence' => 'monthly',
                'status' => 'active',
            ]);
            ClientInvoice::query()->create([
                'workspace_id' => $foreign->id,
                'client_company_id' => $company->id,
                'invoice_number' => 'MIXED-INV-8888',
                'status' => 'issued',
                'currency' => 'USD',
                'subtotal_amount' => 10000,
                'tax_amount' => 0,
                'total_amount' => 10000,
            ]);
            ClientCompanyActivity::query()->create([
                'workspace_id' => $foreign->id,
                'client_company_id' => $company->id,
                'actor_user_id' => $foreignWorker->id,
                'action' => 'invoice.generated',
                'payload' => ['note' => 'Mixed Tenant Activity Payload'],
            ]);
            ClientTimeEntry::query()->create([
                'workspace_id' => $foreign->id,
                'client_company_id' => $company->id,
                'client_project_id' => $visibleProject->id,
                'user_id' => $foreignWorker->id,
                'worked_on' => '2026-08-20',
                'minutes' => 60,
                'description' => 'Mixed Tenant Time Description',
                'status' => 'draft',
            ]);
        });
        ClientAttachment::query()->create([
            'workspace_id' => $foreign->id,
            'record_type' => 'proposal',
            'record_public_id' => $visibleProposal->public_id,
            'object_key' => 'synthetic/mixed-tenant/proposal.txt',
            'original_filename' => 'Mixed Tenant Attachment Name.txt',
            'media_type' => 'text/plain',
            'bytes' => 32,
            'sha256' => hash('sha256', 'synthetic-mixed-tenant-proposal'),
            'uploader_id' => $foreignWorker->id,
            'lifecycle_state' => ClientAttachment::STATE_AVAILABLE,
            'available_at' => now(),
        ]);

        $response = $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/operations")
            ->assertOk();

        $this->assertInertiaPayloadOmits($response, [
            'Foreign Tenant Name',
            'Foreign Client Name',
            'Foreign Project Name',
            'Foreign Proposal Title',
            'Foreign Agreement Title',
            'FOREIGN-INV-7777',
            'Foreign Activity Payload',
            'Foreign Time Description',
            'Foreign Worker Name',
            'Mixed Tenant Proposal Title',
            'Mixed Tenant Agreement Title',
            'MIXED-INV-8888',
            'Mixed Tenant Activity Payload',
            'Mixed Tenant Time Description',
            'Mixed Tenant Attachment Name.txt',
        ], 'Visible Synthetic Proposal');
    }

    public function test_the_operations_page_names_only_columns_that_exist(): void
    {
        $manager = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Identifier Scope', 'slug' => 'synthetic-identifier-scope']);
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Client',
            'slug' => 'synthetic-client',
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Synthetic Project',
            'status' => 'active',
        ]);
        $proposal = ClientProposal::query()->create([
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
            'invoice_number' => 'SYN-IDENT-1',
            'status' => 'issued',
            'currency' => 'USD',
            'subtotal_amount' => 10000,
            'tax_amount' => 0,
            'total_amount' => 10000,
        ]);
        ClientCompanyActivity::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'actor_user_id' => $manager->id,
            'action' => 'invoice.generated',
            'payload' => ['invoice_kind' => 'cadence_period'],
        ]);
        ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => $manager->id,
            'worked_on' => '2026-08-20',
            'minutes' => 60,
            'description' => 'Synthetic identifier work',
            'status' => 'draft',
        ]);
        ClientAttachment::query()->create([
            'workspace_id' => $workspace->id,
            'record_type' => 'proposal',
            'record_public_id' => $proposal->public_id,
            'object_key' => 'synthetic/identifier-scope/proposal.txt',
            'original_filename' => 'synthetic-proposal.txt',
            'media_type' => 'text/plain',
            'bytes' => 32,
            'sha256' => hash('sha256', 'synthetic-identifier-proposal'),
            'uploader_id' => $manager->id,
            'lifecycle_state' => ClientAttachment::STATE_AVAILABLE,
            'available_at' => now(),
        ]);

        $this->assertQueriesNameOnlyRealIdentifiers(
            fn () => $this->actingAs($manager)
                ->get("/workspaces/{$workspace->public_id}/operations")
                ->assertOk(),
        );
    }

    public function test_the_operations_page_does_not_query_once_per_row(): void
    {
        $manager = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Row Scope', 'slug' => 'synthetic-row-scope']);
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'admin']);

        // A full company bundle, so the second render grows the PARENT
        // collections too — the controller iterates companies and projects,
        // and a lazy query per parent repeats identically across two renders
        // of the same parent count, which is how a child-only version of this
        // test would stay green through a real multi-client N+1.
        $sequence = 0;
        $addCompanyBundle = function (int $entries, int $activities) use ($workspace, $manager, &$sequence): void {
            $sequence++;
            $company = ClientCompany::query()->create([
                'workspace_id' => $workspace->id,
                'name' => "Synthetic Client {$sequence}",
                'slug' => "synthetic-client-{$sequence}",
            ]);
            $project = ClientProject::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'name' => "Synthetic Project {$sequence}",
                'status' => 'active',
            ]);
            ClientProposal::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'title' => "Synthetic Proposal {$sequence}",
                'currency' => 'USD',
                'status' => 'draft',
            ]);
            for ($i = 0; $i < $entries; $i++) {
                ClientTimeEntry::query()->create([
                    'workspace_id' => $workspace->id,
                    'client_company_id' => $company->id,
                    'client_project_id' => $project->id,
                    'user_id' => $manager->id,
                    'worked_on' => '2026-08-20',
                    'minutes' => 30,
                    'description' => "Synthetic row work {$sequence}-{$i}",
                    'status' => 'draft',
                ]);
            }
            for ($i = 0; $i < $activities; $i++) {
                ClientCompanyActivity::query()->create([
                    'workspace_id' => $workspace->id,
                    'client_company_id' => $company->id,
                    'actor_user_id' => $manager->id,
                    'action' => 'invoice.generated',
                    'payload' => ['invoice_kind' => 'cadence_period'],
                ]);
            }
        };

        $addCompanyBundle(3, 2);

        $this->assertQueryCountIndependentOfRows(
            fn () => $this->actingAs($manager)
                ->get("/workspaces/{$workspace->public_id}/operations")
                ->assertOk(),
            function () use ($addCompanyBundle): void {
                $addCompanyBundle(27, 40);
                $addCompanyBundle(5, 3);
            },
        );
    }
}
