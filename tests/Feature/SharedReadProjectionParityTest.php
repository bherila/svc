<?php

namespace Tests\Feature;

use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentClientReadService;
use App\Services\AgentApi\AgentProposalReadService;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class SharedReadProjectionParityTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    public function test_native_rows_keep_their_exact_fields_while_sharing_canonical_resource_values(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Shared Reads', 'slug' => 'synthetic-shared-reads']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Client', 'slug' => 'synthetic-client',
            'billing_email' => 'billing@example.test']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic Project', 'status' => 'active']);
        foreach ([['Synthetic internal first', 'Synthetic portal first'], ['Synthetic internal second', 'Synthetic portal second']] as [$internal, $portal]) {
            ClientTimeEntry::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'user_id' => $owner->id,
                'worked_on' => '2026-10-28', 'minutes' => 30, 'description' => $internal, 'client_visible_description' => $portal,
                'status' => 'draft', 'is_billable' => true, 'is_deferred' => false]);
        }
        $proposal = ClientProposal::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'title' => 'Synthetic fractional proposal', 'summary' => 'Synthetic summary', 'terms' => 'Synthetic terms',
            'currency' => 'USD', 'status' => 'sent', 'sent_at' => '2026-10-28 09:10:11']);
        $proposal->items()->create(['workspace_id' => $workspace->id, 'description' => 'Synthetic fractional item',
            'quantity' => '1.125', 'unit_amount' => 2500, 'cadence' => 'one_time', 'sort_order' => 3]);
        $attachment = ClientAttachment::query()->create(['workspace_id' => $workspace->id, 'record_type' => 'proposal',
            'record_public_id' => $proposal->public_id, 'object_key' => 'synthetic/shared-read/private-key.txt',
            'original_filename' => 'synthetic-shared-file.txt', 'media_type' => 'text/plain', 'bytes' => 32,
            'sha256' => hash('sha256', 'synthetic-shared-file'), 'uploader_id' => $owner->id,
            'lifecycle_state' => ClientAttachment::STATE_AVAILABLE, 'available_at' => now()]);
        $foreignWorkspace = Workspace::query()->create(['name' => 'Synthetic Other Tenant', 'slug' => 'synthetic-other-tenant']);
        $this->writingLegacyCrossTenantRows(fn () => $proposal->items()->create([
            'workspace_id' => $foreignWorkspace->id, 'description' => 'Synthetic foreign item must not affect totals',
            'quantity' => '1.000', 'unit_amount' => 999999, 'cadence' => 'one_time', 'sort_order' => 4,
        ]));
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'invoice_number' => 'SYN-SHARED-READ', 'status' => 'issued', 'currency' => 'USD', 'subtotal_amount' => 2813,
            'total_amount' => 2813, 'paid_amount' => 0, 'balance_amount' => 2813, 'issue_date' => '2026-10-28',
            'notes' => 'Synthetic internal invoice note']);
        $this->actingAs($owner, 'web');
        $canonicalCompany = app(AgentClientReadService::class)->get($owner, $workspace, $company->public_id);
        $canonicalProposal = app(AgentProposalReadService::class)->get($owner, $workspace, $proposal->public_id);
        $invoice->setRelation('clientCompany', $company);
        $canonicalInvoice = app(AgentInvoicePresenter::class)->present($workspace, $invoice, includeNotes: true);

        $index = $this->get(route('clients.index', $workspace))->assertOk()->viewData('page')['props'];
        $this->assertSame(Arr::only($canonicalCompany, ['id', 'name', 'billing_email', 'is_active']), Arr::only($index['companies'][0], ['id', 'name', 'billing_email', 'is_active']));
        $this->assertEqualsCanonicalizing(['id', 'name', 'billing_email', 'is_active', 'project_count', 'draft_invoice_count', 'open_invoice_count', 'retainer'], array_keys($index['companies'][0]));

        $nativeProposal = $this->get(route('clients.proposal', [$workspace, $company, $proposal]))->assertOk()->viewData('page')['props'];
        $this->assertEqualsCanonicalizing(['id', 'title', 'summary', 'terms', 'status', 'currency', 'valid_until', 'sent_at', 'accepted_at', 'total_amount'], array_keys($nativeProposal['proposal']));
        $this->assertSame(Arr::except(Arr::only($canonicalProposal, array_keys($nativeProposal['proposal'])), ['sent_at', 'accepted_at']), Arr::except($nativeProposal['proposal'], ['sent_at', 'accepted_at']));
        $this->assertSame('2026-10-28T09:10:11.000000Z', $nativeProposal['proposal']['sent_at']);
        $this->assertSame([Arr::except($canonicalProposal['items'][0], ['sort_order'])], $nativeProposal['items']);

        $operations = $this->get(route('workspaces.operations', $workspace))->assertOk()->viewData('page')['props']['workspace']['clients'][0];
        $this->assertSame(Arr::only($canonicalProposal, ['id', 'title', 'status', 'total_amount', 'currency']), Arr::except($operations['proposals'][0], ['attachments', 'attachment_panel']));
        $this->assertSame(Arr::only($canonicalInvoice, ['id', 'invoice_number', 'status', 'total_amount', 'paid_amount', 'balance_amount', 'currency', 'issue_date', 'due_date']), Arr::except($operations['invoices'][0], ['attachments', 'attachment_panel']));
        $this->assertSame(['Synthetic internal second', 'Synthetic internal first'], array_column($operations['projects'][0]['time_entries'], 'description'));
        $this->assertArrayNotHasKey('notes', $operations['invoices'][0]);
        $this->assertSame([['id' => $attachment->public_id, 'name' => 'synthetic-shared-file.txt', 'media_type' => 'text/plain', 'bytes' => 32,
            'download_url' => "/workspaces/{$workspace->public_id}/attachments/{$attachment->public_id}"]], $operations['proposals'][0]['attachments']);
        $this->assertArrayNotHasKey('sha256', $operations['proposals'][0]['attachments'][0]);
        $this->assertArrayNotHasKey('object_key', $operations['proposals'][0]['attachments'][0]);
        $this->assertArrayNotHasKey('billing_email', $operations);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('workspaces.operations', $workspace))->assertOk();
        $oneCompanyQueries = count(DB::getQueryLog());
        foreach (range(1, 4) as $number) {
            ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic extra '.$number, 'slug' => 'synthetic-extra-'.$number]);
        }
        DB::flushQueryLog();
        $this->get(route('workspaces.operations', $workspace))->assertOk();
        $this->assertLessThanOrEqual($oneCompanyQueries, count(DB::getQueryLog()), 'More company rows must not add presenter queries.');
    }
}
