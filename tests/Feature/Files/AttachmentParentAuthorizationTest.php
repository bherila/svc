<?php

namespace Tests\Feature\Files;

use App\Contracts\WorkspaceOwned;
use App\Models\ClientAgreement;
use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientProposal;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\PortalInvoiceQuery;
use App\Services\Files\AttachmentStorageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class AttachmentParentAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    private Workspace $workspace;

    private ClientCompany $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('svc_files');
        config(['svc.filesystem_disk' => 'svc_files']);
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic file workspace', 'slug' => 'synthetic-file-workspace']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic file client', 'slug' => 'synthetic-file-client']);
        $this->owner = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
    }

    #[DataProvider('projectRecordTypes')]
    public function test_internal_downloads_require_access_to_the_specific_parent(string $type): void
    {
        $assigned = $this->project('Assigned');
        $other = $this->project('Other');
        $member = $this->internalMember($assigned);
        $allowed = $this->attach($this->record($type, $assigned));
        $denied = $this->attach($this->record($type, $other));

        $this->actingAs($member)->get($this->url($allowed))->assertOk()->assertStreamedContent('Synthetic parent-authorized bytes.');
        $this->actingAs($member)->get($this->url($denied))->assertForbidden();
        $this->actingAs($this->owner)->get($this->url($denied))->assertOk();

        ClientProjectMembership::query()->where('workspace_id', $this->workspace->id)->where('user_id', $member->id)->delete();
        $this->actingAs($member)->get($this->url($allowed))->assertForbidden();
    }

    public static function projectRecordTypes(): array
    {
        return [['project'], ['task'], ['proposal'], ['agreement'], ['invoice']];
    }

    public function test_company_files_and_unattributed_financial_files_do_not_inherit_company_reachability(): void
    {
        $member = $this->internalMember($this->project('Assigned'));
        foreach ([$this->company, $this->record('agreement', null), $this->record('invoice', null)] as $record) {
            $attachment = $this->attach($record);
            $this->actingAs($member)->get($this->url($attachment))->assertForbidden();
            $this->actingAs($this->owner)->get($this->url($attachment))->assertOk();
        }
    }

    public function test_company_level_proposal_files_keep_the_existing_detail_permission(): void
    {
        $member = $this->internalMember($this->project('Assigned'));
        $attachment = $this->attach($this->record('proposal', null));
        $this->actingAs($member)->get($this->url($attachment))->assertOk();
        ClientProjectMembership::query()->where('workspace_id', $this->workspace->id)->where('user_id', $member->id)->delete();
        $this->actingAs($member)->get($this->url($attachment))->assertForbidden();
    }

    #[DataProvider('projectRecordTypes')]
    public function test_a_malformed_parent_chain_is_not_authority_even_for_a_workspace_manager(string $type): void
    {
        $record = $this->record($type, $this->project('Local'));
        $attachment = $this->attach($record);
        $foreignWorkspace = Workspace::query()->create(['name' => 'Synthetic foreign workspace', 'slug' => 'synthetic-foreign-workspace']);
        $foreignCompany = ClientCompany::query()->create(['workspace_id' => $foreignWorkspace->id, 'name' => 'Synthetic foreign client', 'slug' => 'synthetic-foreign-client']);
        $foreignProject = ClientProject::query()->create(['workspace_id' => $foreignWorkspace->id, 'client_company_id' => $foreignCompany->id, 'name' => 'Synthetic foreign project']);
        $attributes = in_array($type, ['project', 'invoice'], true)
            ? ['client_company_id' => $foreignCompany->id]
            : ['client_project_id' => $foreignProject->id];
        $this->writingLegacyCrossTenantRows(fn () => DB::table($record->getTable())->where('id', $record->id)->update($attributes));

        $this->actingAs($this->owner)->get($this->url($attachment))->assertNotFound();
    }

    public function test_a_project_in_another_company_cannot_authorize_a_proposal_or_agreement_file(): void
    {
        $project = $this->project('Local');
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic other client', 'slug' => 'synthetic-other-client']);
        $otherProject = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $otherCompany->id, 'name' => 'Synthetic other project']);
        foreach (['proposal', 'agreement'] as $type) {
            $record = $this->record($type, $project);
            $attachment = $this->attach($record);
            // Both references are in the tenant, but they do not form one company chain.
            DB::table($record->getTable())->where('id', $record->id)->update(['client_project_id' => $otherProject->id]);
            $this->actingAs($this->owner)->get($this->url($attachment))->assertNotFound();
        }
    }

    #[DataProvider('invoiceAttribution')]
    public function test_portal_invoice_files_follow_the_full_invoice_grant_rule(string $attribution, bool $allowed): void
    {
        $assigned = $this->project('Assigned');
        $other = $this->project('Other');
        $portal = $this->portalUser($assigned);
        $invoice = $this->record('invoice', null);
        $this->assertInstanceOf(ClientInvoice::class, $invoice);
        if (in_array($attribution, ['assigned', 'mixed', 'assigned-line-other-agreement'], true)) {
            $this->line($invoice, $assigned);
        }
        if (in_array($attribution, ['other', 'mixed'], true)) {
            $this->line($invoice, $other);
        }
        if (in_array($attribution, ['assigned-agreement', 'other-agreement', 'assigned-line-other-agreement'], true)) {
            $agreement = $this->record('agreement', $attribution === 'assigned-agreement' ? $assigned : $other);
            $invoice->update(['client_agreement_id' => $agreement->id]);
        }
        $attachment = $this->attach($invoice);
        $this->assertSame($allowed, app(PortalInvoiceQuery::class)->visibleTo($this->company, $portal)->whereKey($invoice->id)->exists());
        $response = $this->actingAs($portal)->get($this->url($attachment));
        $allowed ? $response->assertOk() : $response->assertForbidden();

        // Company-wide access intentionally reaches company invoices without project lineage.
        $this->company->portalUsers()->updateExistingPivot($portal->id, ['access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);
        $this->actingAs($portal)->get($this->url($attachment))->assertOk();
        $this->company->portalUsers()->detach($portal->id);
        $this->actingAs($portal)->get($this->url($attachment))->assertForbidden();
    }

    public static function invoiceAttribution(): array
    {
        return [['assigned', true], ['other', false], ['mixed', false], ['none', false], ['assigned-agreement', true], ['other-agreement', false], ['assigned-line-other-agreement', false]];
    }

    public function test_held_portal_invoice_url_stops_working_after_grant_or_publication_is_withdrawn(): void
    {
        $project = $this->project('Assigned');
        $portal = $this->portalUser($project);
        $invoice = $this->record('invoice', $project);
        $attachment = $this->attach($invoice);
        $this->actingAs($portal)->get($this->url($attachment))->assertOk();
        $invoice->update(['is_visible_to_client' => false]);
        $this->actingAs($portal)->get($this->url($attachment))->assertForbidden();
        $invoice->update(['is_visible_to_client' => true, 'status' => 'draft']);
        $this->actingAs($portal)->get($this->url($attachment))->assertForbidden();
        $invoice->update(['status' => 'issued']);
        DB::table('client_portal_project_access')->where('workspace_id', $this->workspace->id)->delete();
        $this->actingAs($portal)->get($this->url($attachment))->assertForbidden();
    }

    private function project(string $name): ClientProject
    {
        return ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic '.$name, 'is_visible_to_client' => false]);
    }

    private function internalMember(ClientProject $project): User
    {
        $user = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $user->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $this->workspace->id, 'client_project_id' => $project->id, 'user_id' => $user->id, 'role' => 'viewer']);

        return $user;
    }

    private function portalUser(ClientProject $project): User
    {
        $user = User::factory()->create();
        $membership = ClientCompanyMembership::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'user_id' => $user->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_PROJECTS]);
        DB::table('client_portal_project_access')->insert(['workspace_id' => $this->workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $project->id]);

        return $user;
    }

    private function record(string $type, ?ClientProject $project): Model&WorkspaceOwned
    {
        $company = ['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id];

        return match ($type) {
            'project' => $project ?? throw new \LogicException('A project is required.'),
            'task' => ClientTask::query()->create(['workspace_id' => $this->workspace->id, 'client_project_id' => $project?->id, 'title' => 'Synthetic task', 'is_visible_to_client' => false]),
            'proposal' => ClientProposal::query()->create($company + ['client_project_id' => $project?->id, 'title' => 'Synthetic proposal', 'currency' => 'USD']),
            'agreement' => ClientAgreement::query()->create($company + ['client_project_id' => $project?->id, 'title' => 'Synthetic agreement', 'starts_on' => '2026-01-01', 'currency' => 'USD']),
            'invoice' => $this->invoice($project),
        };
    }

    private function invoice(?ClientProject $project): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'invoice_number' => 'SYNTHETIC-'.ClientInvoice::query()->count(), 'currency' => 'USD', 'status' => 'issued', 'is_visible_to_client' => true]);
        if ($project !== null) {
            $this->line($invoice, $project);
        }

        return $invoice;
    }

    private function line(ClientInvoice $invoice, ClientProject $project): void
    {
        $invoice->lines()->create(['workspace_id' => $this->workspace->id, 'client_project_id' => $project->id, 'type' => 'manual', 'description' => 'Synthetic line', 'quantity' => '1.0000', 'unit_amount' => 100, 'tax_amount' => 0, 'total_amount' => 100, 'sort_order' => 0]);
    }

    private function attach(Model&WorkspaceOwned $record): ClientAttachment
    {
        return app(AttachmentStorageService::class)->store($this->workspace, $record, UploadedFile::fake()->createWithContent('synthetic-parent.txt', 'Synthetic parent-authorized bytes.'), $this->owner);
    }

    private function url(ClientAttachment $attachment): string
    {
        return route('svc.files.download', ['workspace' => $this->workspace, 'clientAttachment' => $attachment->public_id]);
    }
}
