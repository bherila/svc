<?php

namespace Tests\Feature\Engagement;

use App\Models\ClientAgreement;
use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * The isolation and shape guarantees the retired Operations screen was tested
 * for, held now by the pages its tools moved to (docs/ux-overhaul.md, #624
 * item 5): the Agreements and Invoices tabs, the time sheet, the project page
 * and the proposal, agreement and invoice pages with their file panels.
 *
 * Every row is synthetic.
 */
final class RehomedOperationsToolsTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

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

    public function test_nothing_from_another_workspace_reaches_the_pages_the_tools_moved_to(): void
    {
        [$manager, $workspace, $company, $visible] = $this->fixture();
        $foreignWorker = User::factory()->create(['name' => 'Foreign Worker Name']);
        $foreign = Workspace::query()->create(['name' => 'Foreign Tenant Name', 'slug' => 'foreign-tenant']);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $foreign->id, 'name' => 'Foreign Client Name', 'slug' => 'foreign-client-name']);
        $foreignProject = ClientProject::query()->create(['workspace_id' => $foreign->id, 'client_company_id' => $foreignCompany->id, 'name' => 'Foreign Project Name', 'status' => 'active']);
        ClientProposal::query()->create(['workspace_id' => $foreign->id, 'client_company_id' => $foreignCompany->id, 'title' => 'Foreign Proposal Title', 'currency' => 'USD', 'status' => 'draft']);
        ClientAgreement::query()->create(['workspace_id' => $foreign->id, 'client_company_id' => $foreignCompany->id, 'title' => 'Foreign Agreement Title',
            'starts_on' => '2024-01-01', 'currency' => 'USD', 'billing_cadence' => 'monthly', 'status' => 'active']);
        ClientInvoice::query()->create(['workspace_id' => $foreign->id, 'client_company_id' => $foreignCompany->id, 'invoice_number' => 'FOREIGN-INV-7777',
            'status' => 'issued', 'currency' => 'USD', 'subtotal_amount' => 10000, 'tax_amount' => 0, 'total_amount' => 10000]);
        ClientTimeEntry::query()->create(['workspace_id' => $foreign->id, 'client_company_id' => $foreignCompany->id, 'client_project_id' => $foreignProject->id,
            'user_id' => $foreignWorker->id, 'worked_on' => '2026-08-20', 'minutes' => 60, 'description' => 'Foreign Time Description', 'status' => 'draft']);

        // Cross-tenant child rows: a foreign workspace_id pointing at the
        // VISIBLE company and project. The parent filters cannot exclude these,
        // so only the workspace predicate on each query keeps them out. Since
        // #113 they are unrepresentable, but the scoped query is still the
        // second line of defence for a database migrated from before those
        // keys, and it can only be proved against a row that exists.
        $this->writingLegacyCrossTenantRows(function () use ($foreign, $company, $foreignWorker, $visible): void {
            ClientProposal::query()->create(['workspace_id' => $foreign->id, 'client_company_id' => $company->id, 'title' => 'Mixed Tenant Proposal Title', 'currency' => 'USD', 'status' => 'draft']);
            ClientAgreement::query()->create(['workspace_id' => $foreign->id, 'client_company_id' => $company->id, 'title' => 'Mixed Tenant Agreement Title',
                'starts_on' => '2024-01-01', 'currency' => 'USD', 'billing_cadence' => 'monthly', 'status' => 'active']);
            ClientInvoice::query()->create(['workspace_id' => $foreign->id, 'client_company_id' => $company->id, 'invoice_number' => 'MIXED-INV-8888',
                'status' => 'issued', 'currency' => 'USD', 'subtotal_amount' => 10000, 'tax_amount' => 0, 'total_amount' => 10000]);
            ClientTimeEntry::query()->create(['workspace_id' => $foreign->id, 'client_company_id' => $company->id, 'client_project_id' => $visible['project']->id,
                'user_id' => $foreignWorker->id, 'worked_on' => '2026-08-20', 'minutes' => 60, 'description' => 'Mixed Tenant Time Description', 'status' => 'draft']);
        });
        ClientAttachment::query()->create([
            'workspace_id' => $foreign->id, 'record_type' => 'proposal', 'record_public_id' => $visible['proposal']->public_id,
            'object_key' => 'synthetic/mixed-tenant/proposal.txt', 'original_filename' => 'Mixed Tenant Attachment Name.txt', 'media_type' => 'text/plain',
            'bytes' => 32, 'sha256' => hash('sha256', 'synthetic-mixed-tenant-proposal'), 'uploader_id' => $foreignWorker->id,
            'lifecycle_state' => ClientAttachment::STATE_AVAILABLE, 'available_at' => now(),
        ]);

        $secrets = [
            'Foreign Tenant Name', 'Foreign Client Name', 'Foreign Project Name', 'Foreign Proposal Title', 'Foreign Agreement Title',
            'FOREIGN-INV-7777', 'Foreign Time Description', 'Foreign Worker Name', 'Mixed Tenant Proposal Title', 'Mixed Tenant Agreement Title',
            'MIXED-INV-8888', 'Mixed Tenant Time Description', 'Mixed Tenant Attachment Name.txt',
        ];
        $pages = [
            [route('clients.agreements', [$workspace, $company]), 'Visible Synthetic Proposal'],
            [route('clients.invoices', [$workspace, $company]), 'VISIBLE-INV-1'],
            [route('clients.time', [$workspace, $company]), 'Visible Time Description'],
            [route('clients.project', [$workspace, $company, $visible['project']]), 'Synthetic Visible Project'],
            [route('clients.proposal', [$workspace, $company, $visible['proposal']]), 'Visible Synthetic Proposal'],
            [route('clients.agreement', [$workspace, $company, $visible['agreement']]), 'Visible Synthetic Agreement'],
            [route('clients.invoice', [$workspace, $company, $visible['invoice']]), 'VISIBLE-INV-1'],
            [route('clients.show', [$workspace, $company]), 'Synthetic Visible Project'],
        ];
        $this->actingAs($manager);
        foreach ($pages as [$url, $mustContain]) {
            $this->assertInertiaPayloadOmits($this->get($url)->assertOk(), $secrets, $mustContain);
        }
    }

    public function test_a_record_file_panel_lists_only_that_records_own_files(): void
    {
        [$manager, $workspace, $company, $visible] = $this->fixture();
        $other = ClientProposal::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'title' => 'Synthetic Sibling Proposal', 'currency' => 'USD', 'status' => 'draft']);
        $files = [
            ['proposal', $visible['proposal']->public_id, 'synthetic-own-proposal.txt', $workspace],
            ['proposal', $other->public_id, 'synthetic-sibling-proposal.txt', $workspace],
            ['company', $company->public_id, 'synthetic-company-file.txt', $workspace],
            ['agreement', $visible['agreement']->public_id, 'synthetic-own-agreement.txt', $workspace],
            ['invoice', $visible['invoice']->public_id, 'synthetic-own-invoice.txt', $workspace],
        ];
        foreach ($files as [$type, $recordId, $name, $owner]) {
            ClientAttachment::query()->create([
                'workspace_id' => $owner->id, 'record_type' => $type, 'record_public_id' => $recordId,
                'object_key' => 'synthetic/panels/'.$name, 'original_filename' => $name, 'media_type' => 'text/plain', 'bytes' => 32,
                'sha256' => hash('sha256', $name), 'uploader_id' => $manager->id,
                'lifecycle_state' => ClientAttachment::STATE_AVAILABLE, 'available_at' => now(),
            ]);
        }

        $this->actingAs($manager)->get(route('clients.proposal', [$workspace, $company, $visible['proposal']]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('attachment_panel.files', 1)->where('attachment_panel.files.0.filename', 'synthetic-own-proposal.txt'));
        $this->get(route('clients.agreement', [$workspace, $company, $visible['agreement']]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('files', 1)->where('files.0.filename', 'synthetic-own-agreement.txt'));
        $this->get(route('clients.invoice', [$workspace, $company, $visible['invoice']]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('attachment_panel.files', 1)->where('attachment_panel.files.0.filename', 'synthetic-own-invoice.txt'));
    }

    public function test_the_moved_pages_name_only_columns_that_exist(): void
    {
        [$manager, $workspace, $company, $visible] = $this->fixture();
        ClientAttachment::query()->create([
            'workspace_id' => $workspace->id, 'record_type' => 'proposal', 'record_public_id' => $visible['proposal']->public_id,
            'object_key' => 'synthetic/identifier-scope/proposal.txt', 'original_filename' => 'synthetic-proposal.txt', 'media_type' => 'text/plain',
            'bytes' => 32, 'sha256' => hash('sha256', 'synthetic-identifier-proposal'), 'uploader_id' => $manager->id,
            'lifecycle_state' => ClientAttachment::STATE_AVAILABLE, 'available_at' => now(),
        ]);

        $this->assertQueriesNameOnlyRealIdentifiers(function () use ($manager, $workspace, $company, $visible): void {
            $this->actingAs($manager);
            foreach ([
                route('clients.agreements', [$workspace, $company]),
                route('clients.invoices', [$workspace, $company]),
                route('clients.time', [$workspace, $company]),
                route('clients.project', [$workspace, $company, $visible['project']]),
                route('clients.proposal', [$workspace, $company, $visible['proposal']]),
                route('clients.agreement', [$workspace, $company, $visible['agreement']]),
                route('clients.invoice', [$workspace, $company, $visible['invoice']]),
            ] as $url) {
                $this->get($url)->assertOk();
            }
        });
    }

    /**
     * @return array{User, Workspace, ClientCompany, array{project: ClientProject, proposal: ClientProposal, agreement: ClientAgreement, invoice: ClientInvoice}}
     */
    private function fixture(): array
    {
        $manager = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Visible', 'slug' => 'synthetic-visible']);
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Visible Client', 'slug' => 'synthetic-visible-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic Visible Project', 'status' => 'active']);
        $proposal = ClientProposal::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Visible Synthetic Proposal', 'currency' => 'USD', 'status' => 'draft']);
        $agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Visible Synthetic Agreement',
            'starts_on' => '2024-01-01', 'currency' => 'USD', 'billing_cadence' => 'monthly', 'status' => 'active']);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'VISIBLE-INV-1',
            'status' => 'issued', 'currency' => 'USD', 'subtotal_amount' => 10000, 'tax_amount' => 0, 'total_amount' => 10000, 'paid_amount' => 0, 'balance_amount' => 10000]);
        ClientTimeEntry::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'user_id' => $manager->id, 'worked_on' => '2026-08-20', 'minutes' => 60, 'description' => 'Visible Time Description', 'status' => 'draft']);

        return [$manager, $workspace, $company, compact('project', 'proposal', 'agreement', 'invoice')];
    }
}
