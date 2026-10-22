<?php

namespace Tests\Feature\Files;

use App\Models\ClientAgreement;
use App\Models\ClientAttachment;
use App\Models\ClientCollaborationTopic;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientProposal;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentAttachmentService;
use App\Services\AgentApi\AgentExpenseReceiptService;
use App\Services\Files\AttachmentAudience;
use App\Services\Files\AttachmentReader;
use App\Services\Files\AttachmentStorageService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Files\AttachmentListing;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class AttachmentReaderTest extends TestCase
{
    use BuildsSyntheticExpenses, CallsMcp, RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $client;

    private ClientCompany $company;

    private ClientProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('svc_files');
        config(['svc.filesystem_disk' => 'svc_files', 'agent_api.writes_enabled' => true,
            'agent_api.file_writes_enabled' => true, 'agent_api.expense_writes_enabled' => true]);
        $this->workspace = $this->syntheticWorkspace('attachment-reader');
        $this->owner = $this->syntheticMember($this->workspace, 'attachment-owner', 'owner');
        $this->client = $this->syntheticUser('attachment-portal');
        $this->company = $this->syntheticCompany($this->workspace, 'attachment-client');
        $this->project = $this->syntheticProject($this->company, 'attachment-project');
        $this->project->update(['is_visible_to_client' => true]);
        $this->grant($this->client, $this->project);
    }

    /** @param 'company'|'project'|'task'|'proposal'|'agreement'|'invoice'|'expense' $type */
    #[DataProvider('legacyUploadTypes')]
    public function test_every_native_parent_upload_explicitly_retains_legacy_true(string $type): void
    {
        $fields = ['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_project_id' => $this->project->id];
        $record = match ($type) {
            'company' => $this->company,
            'project' => $this->project,
            'task' => ClientTask::query()->create(['workspace_id' => $this->workspace->id, 'client_project_id' => $this->project->id, 'title' => 'Synthetic upload task', 'is_visible_to_client' => false]),
            'proposal' => ClientProposal::query()->create([...$fields, 'title' => 'Synthetic upload proposal', 'currency' => 'USD']),
            'agreement' => ClientAgreement::query()->create([...$fields, 'title' => 'Synthetic upload agreement', 'starts_on' => '2026-10-01', 'currency' => 'USD']),
            'invoice' => ClientInvoice::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'invoice_number' => 'SYNTHETIC-UPLOAD', 'currency' => 'USD']),
            'expense' => $this->recordSyntheticExpense($this->workspace, $this->company),
        };
        $this->actingAs($this->owner)->postJson(route('svc.files.store', [$this->workspace, $type, $record->public_id]),
            ['file' => UploadedFile::fake()->createWithContent('synthetic-legacy.txt', 'Synthetic legacy bytes')])->assertRedirect();
        $file = ClientAttachment::query()->where('workspace_id', $this->workspace->id)->sole();
        $this->assertTrue($file->is_visible_to_client);
        $this->assertSame($type, $file->record_type);
        $this->assertSame(ClientAttachment::STATE_AVAILABLE, $file->lifecycle_state);
    }

    /** @return list<array{string}> */
    public static function legacyUploadTypes(): array
    {
        return array_map(fn (string $type): array => [$type], ['company', 'project', 'task', 'proposal', 'agreement', 'invoice', 'expense']);
    }

    public function test_stored_private_flag_filters_metadata_and_held_downloads_without_affecting_internal_reads(): void
    {
        $file = $this->attach();
        $reader = app(AttachmentReader::class);
        $this->assertTrue($file->is_visible_to_client);
        $url = route('svc.files.download', [$this->workspace, $file->public_id]);
        $this->actingAs($this->client)->get($url)->assertOk();
        DB::table('client_attachments')->where('workspace_id', $this->workspace->id)->where('id', $file->id)->update(['is_visible_to_client' => false]);
        $this->actingAs($this->client)->get($url)->assertForbidden();
        $this->assertSame([], $reader->forParent($this->client, $this->workspace, 'project', $this->project->public_id, AttachmentAudience::Portal)->get()->all());
        $this->actingAs($this->owner)->get($url)->assertOk()->assertStreamedContent('Synthetic reader bytes');
        $this->project->update(['is_visible_to_client' => false]);
        $this->project->update(['is_visible_to_client' => true]);
        $this->assertFalse($reader->canView($this->client, $this->workspace, $file, AttachmentAudience::Portal));
    }

    public function test_selected_parent_policies_are_an_allowlist_and_empty_never_means_all(): void
    {
        $this->attach();
        $reader = app(AttachmentReader::class);
        $this->assertSame(1, $reader->queryForTypes($this->owner, $this->workspace, AttachmentAudience::Native, ['project'])->count());
        $this->assertSame(0, $reader->queryForTypes($this->owner, $this->workspace, AttachmentAudience::Native, [])->count());
        $this->assertSame(0, $reader->queryForTypes($this->owner, $this->workspace, AttachmentAudience::Native, ['agreement'])->count());
        foreach ([['unknown'], ['project', 'unknown']] as $types) {
            try {
                $reader->queryForTypes($this->owner, $this->workspace, AttachmentAudience::Native, $types)->get();
                $this->fail('An unsupported parent policy must not broaden the query.');
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
            }
        }
    }

    public function test_private_rows_are_filtered_before_the_listing_limit_and_queries_are_bounded(): void
    {
        $file = $this->attach();
        $template = DB::table('client_attachments')->where('workspace_id', $this->workspace->id)->where('id', $file->id)->first();
        $this->assertNotNull($template);
        $row = (array) $template;
        unset($row['id']);
        for ($i = 0; $i < 101; $i++) {
            DB::table('client_attachments')->insert([...$row, 'public_id' => (string) Str::uuid(), 'object_key' => 'synthetic/'.Str::uuid(), 'is_visible_to_client' => false]);
        }
        $list = AttachmentListing::for($this->workspace, 'project', $this->project->public_id, false, $this->client, AttachmentAudience::Portal);
        $this->assertSame([$file->public_id], array_column($list, 'id'));
        $this->assertNull($list[0]['delete_href']);
        DB::enableQueryLog();
        AttachmentListing::for($this->workspace, 'project', $this->project->public_id, false, $this->client, AttachmentAudience::Portal);
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::table('client_attachments')->where('workspace_id', $this->workspace->id)->update(['is_visible_to_client' => true]);
        DB::flushQueryLog();
        $this->assertCount(100, AttachmentListing::for($this->workspace, 'project', $this->project->public_id, false, $this->client, AttachmentAudience::Portal));
        $this->assertSame($one, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_real_portal_agreement_listing_omits_private_filename_and_internal_operator_retains_it(): void
    {
        $agreement = ClientAgreement::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id, 'title' => 'Synthetic reader agreement', 'starts_on' => '2026-10-01',
            'currency' => 'USD', 'status' => 'active', 'is_visible_to_client' => true]);
        $files = [];
        foreach (['synthetic-shared-agreement.txt', 'synthetic-private-only.txt'] as $filename) {
            $files[] = app(AttachmentStorageService::class)->store($this->workspace, $agreement, UploadedFile::fake()->createWithContent($filename, 'Synthetic agreement bytes'), $this->owner);
        }
        DB::table('client_attachments')->where('workspace_id', $this->workspace->id)->where('id', $files[1]->id)->update(['is_visible_to_client' => false]);
        $response = $this->actingAs($this->client)->get(route('portal.agreement', [$this->company, $agreement]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('files', 1)->where('files.0.filename', 'synthetic-shared-agreement.txt')->where('files.0.delete_href', null));
        $this->assertStringNotContainsString('synthetic-private-only.txt', $response->getContent());
        $this->actingAs($this->owner)->get(route('svc.files.download', [$this->workspace, $files[1]->public_id]))->assertOk();
    }

    public function test_native_agreement_with_a_foreign_company_project_keeps_terms_but_no_file_authority(): void
    {
        $sibling = $this->syntheticCompany($this->workspace, 'file-lineage-sibling');
        $foreignProject = $this->syntheticProject($sibling, 'file-lineage-sibling');
        $valid = ClientAgreement::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id, 'title' => 'Synthetic valid file terms', 'starts_on' => '2026-10-01', 'currency' => 'USD']);
        $malformed = ClientAgreement::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $foreignProject->id, 'title' => 'Synthetic malformed file terms', 'starts_on' => '2026-10-01', 'currency' => 'USD']);
        $storage = app(AttachmentStorageService::class);
        $visible = $storage->store($this->workspace, $valid, UploadedFile::fake()->createWithContent('synthetic-valid-lineage.txt', 'Synthetic valid bytes'), $this->owner);
        $hidden = $storage->store($this->workspace, $malformed, UploadedFile::fake()->createWithContent('synthetic-malformed-lineage.txt', 'Synthetic malformed bytes'), $this->owner);
        $this->actingAs($this->owner)->get(route('clients.agreement', [$this->workspace, $this->company, $valid]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('files', 1)->where('files.0.id', $visible->public_id)
                ->where('actions.upload_file', route('svc.files.store', [$this->workspace, 'agreement', $valid->public_id], absolute: false)));
        $response = $this->get(route('clients.agreement', [$this->workspace, $this->company, $malformed]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('agreement.project', null)->has('files', 0)
                ->where('files_next_href', null)->where('files_previous_href', null)->where('actions.upload_file', null));
        $this->assertStringNotContainsString('synthetic-malformed-lineage.txt', $response->getContent());
        $this->get(route('svc.files.download', [$this->workspace, $hidden->public_id]))->assertNotFound();
        $this->actingAsMcp($this->owner, ['files:read']);
        $this->getJson('/api/v1/workspaces/'.$this->workspace->public_id.'/attachments/'.$hidden->public_id)->assertNotFound();
    }

    public function test_native_portal_preview_without_grants_keeps_client_safe_flags_and_no_management(): void
    {
        $agreement = ClientAgreement::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id, 'title' => 'Synthetic native preview', 'starts_on' => '2026-10-01',
            'currency' => 'USD', 'status' => 'active', 'is_visible_to_client' => true]);
        $url = route('portal.agreement', [$this->company, $agreement]);
        // Existing owner preview must also work when there are no attachments.
        $this->actingAs($this->owner)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->has('files', 0));
        $shared = app(AttachmentStorageService::class)->store($this->workspace, $agreement,
            UploadedFile::fake()->createWithContent('synthetic-preview-shared.txt', 'Synthetic preview bytes'), $this->owner);
        $private = app(AttachmentStorageService::class)->store($this->workspace, $agreement,
            UploadedFile::fake()->createWithContent('synthetic-preview-private.txt', 'Synthetic preview private bytes'), $this->owner);
        DB::table('client_attachments')->where('workspace_id', $this->workspace->id)->where('id', $private->id)->update(['is_visible_to_client' => false]);
        foreach ([false, true] as $dual) {
            if ($dual) {
                ClientCompanyMembership::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
                    'user_id' => $this->owner->id, 'role' => 'client', 'access_scope' => 'projects']);
            }
            $response = $this->actingAs($this->owner)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->has('files', 1)
                ->where('files.0.id', $shared->public_id)->where('files.0.delete_href', null)->where('actions.upload_file', null));
            $this->assertStringNotContainsString('synthetic-preview-private.txt', $response->getContent());
        }
        $reader = app(AttachmentReader::class);
        $this->assertFalse($reader->canView($this->owner, $this->workspace, $private, AttachmentAudience::PortalPreview));
        try {
            $reader->assertParentAccess($this->client, $this->workspace, 'agreement', $agreement->public_id, AttachmentAudience::PortalPreview);
            $this->fail('An ordinary Portal member cannot claim native preview authority.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->workspace->memberships()->where('user_id', $this->owner->id)->update(['role' => 'member']);
        try {
            $reader->query($this->owner, $this->workspace, AttachmentAudience::PortalPreview)->get();
            $this->fail('Preview rechecks the current native owner/admin role.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_native_task_preview_uses_visible_structure_after_current_manager_authorization(): void
    {
        $parent = ClientTask::query()->create(['workspace_id' => $this->workspace->id, 'client_project_id' => $this->project->id,
            'title' => 'Synthetic preview containing task', 'is_visible_to_client' => true]);
        $leaf = ClientTask::query()->create(['workspace_id' => $this->workspace->id, 'client_project_id' => $this->project->id,
            'title' => 'Synthetic preview child task', 'is_visible_to_client' => true]);
        DB::table('client_tasks')->where('workspace_id', $this->workspace->id)->where('id', $leaf->id)->update(['parent_task_id' => $parent->id]);
        $file = app(AttachmentStorageService::class)->store($this->workspace, $leaf,
            UploadedFile::fake()->createWithContent('synthetic-preview-task.txt', 'Synthetic preview bytes'), $this->owner);
        $reader = app(AttachmentReader::class);
        $this->assertTrue($reader->canView($this->owner, $this->workspace, $file, AttachmentAudience::PortalPreview));
        $this->assertFalse($reader->canView($this->owner, $this->workspace, $file, AttachmentAudience::Portal));
        $parent->update(['is_visible_to_client' => false]);
        $this->assertFalse($reader->canView($this->owner, $this->workspace, $file, AttachmentAudience::PortalPreview));
        $this->assertTrue($reader->canView($this->owner, $this->workspace, $file, AttachmentAudience::Native));
    }

    public function test_operations_file_metadata_reuses_specific_parent_authority(): void
    {
        $other = $this->syntheticProject($this->company, 'operations-ungranted');
        $member = $this->syntheticMember($this->workspace, 'operations-member', 'member');
        ClientProjectMembership::query()->create(['workspace_id' => $this->workspace->id, 'client_project_id' => $this->project->id,
            'user_id' => $member->id, 'role' => 'viewer']);
        foreach ([$this->project, $other] as $index => $project) {
            $agreement = ClientAgreement::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
                'client_project_id' => $project->id, 'title' => 'Synthetic operations agreement '.$index, 'starts_on' => '2026-10-01', 'currency' => 'USD']);
            app(AttachmentStorageService::class)->store($this->workspace, $agreement,
                UploadedFile::fake()->createWithContent($index === 0 ? 'synthetic-allowed-operations.txt' : 'synthetic-denied-operations.txt', 'Synthetic operations bytes'), $this->owner);
        }
        $response = $this->actingAs($member)->get(route('workspaces.operations', $this->workspace))->assertOk();
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('synthetic-allowed-operations.txt', $content);
        $this->assertStringNotContainsString('synthetic-denied-operations.txt', $content);
    }

    public function test_generic_agent_remains_manager_only_and_dual_manager_uses_exact_portal_grants_for_legacy_files(): void
    {
        $allowed = $this->attach();
        $other = $this->syntheticProject($this->company, 'ungranted');
        $other->update(['is_visible_to_client' => true]);
        $denied = app(AttachmentStorageService::class)->store($this->workspace, $other, UploadedFile::fake()->createWithContent('synthetic-denied.txt', 'Synthetic denied bytes'), $this->owner);
        $service = app(AgentAttachmentService::class);
        $this->assertSame($denied->public_id, $service->metadata($this->owner, $this->workspace, $denied->public_id)['data']['id']);
        $this->grant($this->owner, $this->project);
        $this->assertSame($allowed->public_id, $service->metadata($this->owner, $this->workspace, $allowed->public_id)['data']['id']);
        $held = $service->downloadUrl($this->owner, $this->workspace, $allowed->public_id);
        $this->assertStringContainsString($allowed->public_id, $held['data']['url']);
        foreach ([$denied, $allowed] as $file) {
            if ($file->id === $allowed->id) {
                DB::table('client_portal_project_access')->where('workspace_id', $this->workspace->id)->delete();
            }
            try {
                $service->metadata($this->owner, $this->workspace, $file->public_id);
                $this->fail('Dual manager must not bypass exact grants.');
            } catch (ModelNotFoundException $e) {
                $this->assertSame(ClientAttachment::class, $e->getModel());
            }
        }
        try {
            $service->metadata($this->client, $this->workspace, $allowed->public_id);
            $this->fail('Portal file grants do not make a workspace manager.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        // Native operator access retains its existing independent staff policy.
        $this->actingAs($this->owner)->get(route('svc.files.download', [$this->workspace, $denied->public_id]))->assertOk();
    }

    public function test_dual_invoice_reader_requires_all_attributed_project_grants(): void
    {
        $other = $this->syntheticProject($this->company, 'invoice-other');
        $invoice = ClientInvoice::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'invoice_number' => 'SYNTHETIC-READER-1', 'currency' => 'USD', 'status' => 'issued', 'is_visible_to_client' => true]);
        foreach ([$this->project, $other] as $project) {
            $invoice->lines()->create(['workspace_id' => $this->workspace->id, 'client_project_id' => $project->id, 'type' => 'manual',
                'description' => 'Synthetic reader line', 'quantity' => '1.0000', 'unit_amount' => 100, 'tax_amount' => 0, 'total_amount' => 100, 'sort_order' => 0]);
        }
        $file = app(AttachmentStorageService::class)->store($this->workspace, $invoice, UploadedFile::fake()->createWithContent('synthetic-invoice.txt', 'Synthetic invoice'), $this->owner);
        $this->grant($this->owner, $this->project);
        $this->assertFalse(app(AttachmentReader::class)->canView($this->owner, $this->workspace, $file, AttachmentAudience::Portal));
        $this->assertTrue(app(AttachmentReader::class)->canView($this->owner, $this->workspace, $file, AttachmentAudience::Native));
        $membership = ClientCompanyMembership::query()->where('workspace_id', $this->workspace->id)->where('user_id', $this->owner->id)->sole();
        DB::table('client_portal_project_access')->insert(['workspace_id' => $this->workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $other->id]);
        $this->assertTrue(app(AttachmentReader::class)->canView($this->owner, $this->workspace, $file, AttachmentAudience::Portal));
    }

    #[DataProvider('collaborationParentTypes')]
    public function test_collaboration_parent_readers_fail_closed_after_tombstone_and_wrong_company(string $type): void
    {
        $topic = ClientCollaborationTopic::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id, 'kind' => 'note', 'title' => 'Synthetic reader topic', 'body' => 'Synthetic body',
            'author_user_id' => $this->owner->id, 'author_name' => 'Synthetic owner', 'is_visible_to_client' => true]);
        $parent = $type === 'topic' ? $topic : ClientComment::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id, 'client_collaboration_topic_id' => $topic->id, 'body' => 'Synthetic reader comment',
            'author_user_id' => $this->owner->id, 'author_name' => 'Synthetic owner', 'is_visible_to_client' => true]);
        // R1 has no writers for these types; seed reader-compatible future rows directly.
        $file = $this->attach();
        DB::table('client_attachments')->where('workspace_id', $this->workspace->id)->where('id', $file->id)
            ->update(['record_type' => $type, 'record_public_id' => $parent->public_id]);
        $file = $file->fresh();
        $reader = app(AttachmentReader::class);
        $this->assertTrue($reader->canView($this->client, $this->workspace, $file, AttachmentAudience::Portal));
        $this->actingAs($this->client)->get(route('svc.files.download', [$this->workspace, $file->public_id]))->assertOk();
        $topic->forceFill(['deleted_at' => now()])->save();
        $this->assertFalse($reader->canView($this->client, $this->workspace, $file, AttachmentAudience::Portal));
        $this->assertFalse($reader->canView($this->owner, $this->workspace, $file, AttachmentAudience::Internal));
        $this->actingAs($this->owner)->get(route('svc.files.download', [$this->workspace, $file->public_id]))->assertForbidden();
        $topic->forceFill(['deleted_at' => null])->save();
        if ($parent instanceof ClientComment) {
            $parent->forceFill(['deleted_at' => now()])->save();
            $this->assertFalse($reader->canView($this->owner, $this->workspace, $file, AttachmentAudience::Internal));
            $parent->forceFill(['deleted_at' => null])->save();
        }
        $otherCompany = $this->syntheticCompany($this->workspace, 'reader-wrong-company');
        DB::table('client_collaboration_topics')->where('workspace_id', $this->workspace->id)->where('id', $topic->id)->update(['client_company_id' => $otherCompany->id]);
        $this->assertFalse($reader->canView($this->owner, $this->workspace, $file, AttachmentAudience::Internal));
        $this->actingAs($this->owner)->get(route('svc.files.download', [$this->workspace, $file->public_id]))->assertNotFound();
    }

    /** @return list<array{string}> */
    public static function collaborationParentTypes(): array
    {
        return [['topic'], ['comment']];
    }

    /** @param array<string, mixed> $fields */
    #[DataProvider('unsupportedUploadPayloads')]
    public function test_native_upload_rejects_private_or_new_parent_before_staging(string $type, array $fields): void
    {
        $this->actingAs($this->owner)->postJson(route('svc.files.store', [$this->workspace, $type, $this->project->public_id]),
            ['file' => UploadedFile::fake()->createWithContent('synthetic-rejected.txt', 'Synthetic rejected bytes'), ...$fields])->assertUnprocessable();
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertSame([], Storage::disk('svc_files')->allFiles());
    }

    /** @return list<array{string, array<string, mixed>}> */
    public static function unsupportedUploadPayloads(): array
    {
        return [['project', ['is_visible_to_client' => false]], ['project', ['is_visible_to_client' => true]],
            ['project', ['is_visible_to_client' => null]], ['topic', []], ['comment', []]];
    }

    public function test_agent_upload_preparation_and_multipart_reject_new_parent_types_before_staging(): void
    {
        $service = app(AgentAttachmentService::class);
        foreach (['topic', 'comment'] as $type) {
            foreach (['url', 'multipart'] as $entryPoint) {
                try {
                    if ($entryPoint === 'url') {
                        $service->uploadUrl($this->owner, $this->workspace, $type, $this->project->public_id);
                    } else {
                        $service->upload($this->owner, $this->workspace, 'synthetic-reader', 'synthetic-new-parent-'.$type, $type, $this->project->public_id,
                            UploadedFile::fake()->createWithContent('synthetic-rejected.txt', 'Synthetic rejected bytes'),
                            ['expected_version' => AgentApiVersion::for($this->project)]);
                    }
                    $this->fail('Reader support must not enable a new parent uploader.');
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('record_type', $e->errors());
                }
            }
        }
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertSame([], Storage::disk('svc_files')->allFiles());
    }

    public function test_agent_generic_and_expense_uploads_reject_private_before_preparation_and_keep_ordinary_uploads_true(): void
    {
        $file = UploadedFile::fake()->createWithContent('synthetic-agent.txt', 'Synthetic agent bytes');
        $expense = $this->recordSyntheticExpense($this->workspace, $this->company);
        foreach (['generic', 'expense'] as $kind) {
            try {
                if ($kind === 'generic') {
                    app(AgentAttachmentService::class)->upload($this->owner, $this->workspace, 'synthetic-reader', 'synthetic-reader-key', 'project', $this->project->public_id, $file,
                        ['expected_version' => AgentApiVersion::for($this->project), 'is_visible_to_client' => false]);
                } else {
                    app(AgentExpenseReceiptService::class)->upload($this->owner, $this->workspace, 'synthetic-reader', 'synthetic-expense-key', $expense->public_id,
                        ['expected_version' => AgentApiVersion::for($expense), 'is_visible_to_client' => false], $file);
                }
                $this->fail('R1 must reject a supplied private flag.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('client_attachments', 0);
                $this->assertSame([], Storage::disk('svc_files')->allFiles());
            }
        }
        app(AgentAttachmentService::class)->upload($this->owner, $this->workspace, 'synthetic-reader', 'synthetic-reader-valid', 'project', $this->project->public_id, $file,
            ['expected_version' => AgentApiVersion::for($this->project), 'file' => $file]);
        app(AgentExpenseReceiptService::class)->upload($this->owner, $this->workspace, 'synthetic-reader', 'synthetic-expense-valid', $expense->public_id,
            ['expected_version' => AgentApiVersion::for($expense)], $file);
        $this->assertSame(0, ClientAttachment::query()->where('workspace_id', $this->workspace->id)->where('is_visible_to_client', false)->count());
        $this->assertDatabaseCount('client_attachments', 2);
    }

    public function test_dual_agent_file_and_receipt_writes_are_denied_before_staging_but_native_uploads_keep_legacy_true(): void
    {
        $expense = $this->recordSyntheticExpense($this->workspace, $this->company);
        $this->grant($this->owner, $this->project);
        $file = UploadedFile::fake()->createWithContent('synthetic-dual.txt', 'Synthetic dual bytes');
        foreach (['generic', 'expense'] as $kind) {
            try {
                if ($kind === 'generic') {
                    app(AgentAttachmentService::class)->upload($this->owner, $this->workspace, 'synthetic-dual', 'synthetic-dual-file', 'project', $this->project->public_id, $file,
                        ['expected_version' => AgentApiVersion::for($this->project), 'file' => $file]);
                } else {
                    app(AgentExpenseReceiptService::class)->upload($this->owner, $this->workspace, 'synthetic-dual', 'synthetic-dual-receipt', $expense->public_id,
                        ['expected_version' => AgentApiVersion::for($expense)], $file);
                }
                $this->fail('Dual Agent writes must be denied before preparation.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertSame([], Storage::disk('svc_files')->allFiles());
        $this->actingAs($this->owner)->postJson(route('svc.files.store', [$this->workspace, 'project', $this->project->public_id]), ['file' => $file])->assertRedirect();
        $this->assertTrue(ClientAttachment::query()->where('workspace_id', $this->workspace->id)->sole()->is_visible_to_client);
    }

    private function attach(): ClientAttachment
    {
        return app(AttachmentStorageService::class)->store($this->workspace, $this->project,
            UploadedFile::fake()->createWithContent('synthetic-reader.txt', 'Synthetic reader bytes'), $this->owner);
    }

    private function grant(User $actor, ClientProject $project): void
    {
        $membership = ClientCompanyMembership::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'user_id' => $actor->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_PROJECTS]);
        DB::table('client_portal_project_access')->insert(['workspace_id' => $this->workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $project->id]);
    }
}
