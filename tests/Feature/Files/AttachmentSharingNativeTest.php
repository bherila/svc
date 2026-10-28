<?php

namespace Tests\Feature\Files;

use App\Models\ClientCollaborationTopic;
use App\Models\ClientInvoice;
use App\Models\ClientProjectMembership;
use App\Models\ClientProposal;
use App\Services\Files\AttachmentPrivacyLatch;
use App\Services\Files\AttachmentStorageService;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\TestCase;

final class AttachmentSharingNativeTest extends TestCase
{
    use BuildsSyntheticExpenses, RefreshDatabase;

    private string $policyDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policyDirectory = sys_get_temp_dir().'/svc-native-sharing-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->policyDirectory, 0700);
        File::put($this->policyDirectory.'/'.AttachmentPrivacyLatch::MARKER, "enabled\n");
        app()->instance(AttachmentPrivacyLatch::class, new AttachmentPrivacyLatch($this->policyDirectory));
        config(['svc.attachments.sharing_writes_enabled' => true, 'svc.filesystem_disk' => 'svc_files']);
        Storage::fake('svc_files');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->policyDirectory);
        parent::tearDown();
    }

    public function test_native_dual_manager_uploads_private_files_and_explicitly_shares_without_changing_the_parent(): void
    {
        [$workspace, $company, $project, $owner, $topic] = $this->fixture();
        $company->portalUsers()->attach($owner->id, ['role' => 'viewer', 'access_scope' => 'company']);
        $parameters = ['workspace' => $workspace, 'clientCompany' => $company->public_id, 'recordType' => 'topic', 'recordPublicId' => $topic->public_id];
        $version = AgentApiVersion::for($topic);
        $this->actingAs($owner)->get(route('svc.files.collaboration.show', $parameters))->assertOk()->assertInertia(fn (Assert $page) => $page->where('workspaceNavigation.current_client_id', $company->public_id));
        $created = $this->actingAs($owner)->withHeader('Idempotency-Key', 'synthetic-native-upload')
            ->postJson(route('svc.files.collaboration.upload', $parameters), ['file' => UploadedFile::fake()->createWithContent('synthetic-native.txt', 'Synthetic native bytes'), 'expected_version' => $version])
            ->assertOk()->assertJsonPath('data.is_visible_to_client', false)->json('data');
        $this->get(route('collaboration.show', [$workspace, $company, $topic]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('collaboration/show')->has('topic.attachment_panel.files', 1)
            ->where('topic.attachment_panel.files.0.filename', 'synthetic-native.txt')
            ->where('topic.attachment_panel.files.0.sharing.is_visible_to_client', false)
            ->where('topic.attachment_panel.upload.href', route('svc.files.collaboration.upload', $parameters, absolute: false)));
        $payload = ['is_visible_to_client' => true, 'expected_version' => $created['version'], 'expected_parent_version' => $version];
        $this->withHeader('Idempotency-Key', 'synthetic-native-share')->patchJson(route('svc.files.collaboration.visibility.update', [...$parameters, 'attachment' => $created['id']]), $payload)
            ->assertOk()->assertJsonPath('data.is_visible_to_client', true)->assertJsonPath('data.effective_visible_to_client', false);
        $this->assertSame($version, AgentApiVersion::for($topic->fresh()));
        config(['svc.attachments.sharing_writes_enabled' => false]);
        $this->get(route('collaboration.show', [$workspace, $company, $topic]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('topic.attachment_panel.files', 1)->where('topic.attachment_panel.files.0.sharing', null)->where('topic.attachment_panel.upload', null));
    }

    public function test_portal_reads_require_both_flags_and_never_receive_writers_or_private_filenames(): void
    {
        [$workspace, $company, $project, $owner, $topic] = $this->fixture();
        $project->update(['is_visible_to_client' => true]);
        $topic->update(['is_visible_to_client' => true]);
        $parameters = ['workspace' => $workspace, 'clientCompany' => $company->public_id, 'recordType' => 'topic', 'recordPublicId' => $topic->public_id];
        $version = AgentApiVersion::for($topic);
        $created = $this->actingAs($owner)->withHeader('Idempotency-Key', 'synthetic-portal-upload')
            ->postJson(route('svc.files.collaboration.upload', $parameters), ['file' => UploadedFile::fake()->createWithContent('synthetic-private.txt', 'Synthetic private bytes'), 'expected_version' => $version])
            ->assertOk()->json('data');
        $portal = $this->syntheticUser('native-sharing-portal');
        $company->portalUsers()->attach($portal->id, ['role' => 'viewer', 'access_scope' => 'company']);
        $portalParameters = ['clientCompany' => $company, 'recordType' => 'topic', 'recordPublicId' => $topic->public_id];
        $this->actingAs($portal)->getJson(route('portal.files.collaboration.index', $portalParameters))->assertOk()->assertJsonCount(0, 'files')->assertJsonPath('upload', null);
        $this->get(route('portal.files.collaboration.download', [...$portalParameters, 'attachment' => $created['id']]))->assertNotFound();
        $this->actingAs($owner)->withHeader('Idempotency-Key', 'synthetic-portal-share')
            ->patchJson(route('svc.files.collaboration.visibility.update', [...$parameters, 'attachment' => $created['id']]), ['is_visible_to_client' => true, 'expected_version' => $created['version'], 'expected_parent_version' => $version])->assertOk();
        $this->actingAs($portal)->getJson(route('portal.files.collaboration.index', $portalParameters))->assertOk()->assertJsonCount(1, 'files')->assertJsonPath('files.0.sharing', null)->assertJsonPath('files.0.delete_href', null)->assertJsonPath('upload', null);
        $topic->update(['is_visible_to_client' => false]);
        $this->get(route('portal.files.collaboration.download', [...$portalParameters, 'attachment' => $created['id']]))->assertNotFound();
    }

    public function test_legacy_parent_page_is_discoverable_and_cannot_escape_workspace_or_publish_authority(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $parameters = [$workspace, 'company', $company->public_id];
        $href = route('svc.files.parent.show', $parameters, absolute: false);
        $this->actingAs($owner)->get(route('clients.show', [$workspace, $company]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('files_href', $href));
        $this->get($href)->assertOk()->assertInertia(fn (Assert $page) => $page->component('files/parent')->has('attachment_panel.files', 0)->has('workspaceNavigation')->where('legacy_upload_href', route('svc.files.store', $parameters, absolute: false)));
        $other = $this->syntheticWorkspace('native-sharing-other');
        $this->get(route('svc.files.parent.show', [$other, 'company', $company->public_id]))->assertNotFound();
        $contributor = $this->syntheticMember($workspace, 'native-contributor', 'member');
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $contributor->id, 'role' => 'contributor']);
        $this->actingAs($contributor)->get(route('svc.files.parent.show', [$workspace, 'project', $project->public_id]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('legacy_upload_href', null));
    }

    public function test_financial_detail_and_operations_use_shared_seeded_controls_without_per_record_queries(): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $this->actingAs($owner);
        $counts = [];
        foreach (range(1, 4) as $index) {
            $proposal = ClientProposal::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'title' => 'Synthetic shared file proposal '.$index, 'status' => 'draft', 'currency' => 'USD', 'is_visible_to_client' => true]);
            $file = app(AttachmentStorageService::class)->store($workspace, $proposal, UploadedFile::fake()->createWithContent('synthetic-proposal-'.$index.'.txt', 'Synthetic proposal bytes'), $owner);
            if ($index === 1) {
                $this->get(route('clients.proposal', [$workspace, $company, $proposal]))->assertOk()->assertInertia(fn (Assert $page) => $page
                    ->has('attachment_panel.files', 1)->where('attachment_panel.files.0.id', $file->public_id)->where('attachment_panel.files.0.sharing.effective_visible_to_client', false));
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->get(route('workspaces.operations', $workspace));
            $counts[] = count(array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')));
            DB::disableQueryLog();
            $response->assertOk()->assertInertia(fn (Assert $page) => $page
                ->has('workspace.clients.0.proposals', $index)->has('workspace.clients.0.proposals.0.attachment_panel.files', 1)
                ->where('workspace.clients.0.proposals.0.attachment_panel.files.0.sharing.is_visible_to_client', false));
        }
        $this->assertSame($counts[0], $counts[3], 'Growing native file parents must not grow SQL query count.');
    }

    public function test_portal_commercial_file_panels_are_bounded_read_only_and_reauthorize_before_names_and_bytes(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $project->update(['is_visible_to_client' => true]);
        $portal = $this->syntheticUser('commercial-file-portal');
        $company->portalUsers()->attach($portal->id, ['role' => 'viewer', 'access_scope' => 'company']);
        $sibling = $this->syntheticCompany($workspace, 'commercial-file-sibling');
        $sibling->portalUsers()->attach($portal->id, ['role' => 'viewer', 'access_scope' => 'company']);
        $parents = [
            'proposal' => ClientProposal::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
                'title' => 'Synthetic Portal proposal', 'status' => 'sent', 'currency' => 'USD', 'is_visible_to_client' => true]),
            'invoice' => ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'invoice_number' => 'SYNTHETIC-FILE-1', 'status' => 'issued', 'currency' => 'USD', 'is_visible_to_client' => true]),
        ];
        foreach ($parents as $type => $parent) {
            $files = [];
            foreach (range(1, 3) as $index) {
                $file = app(AttachmentStorageService::class)->store($workspace, $parent, UploadedFile::fake()->createWithContent('Synthetic-'.$type.'-'.$index.'.txt', 'Synthetic commercial bytes'), $owner);
                if ($index > 1) {
                    $this->actingAs($owner)->withHeader('Idempotency-Key', 'commercial-share-'.$type.'-'.$index)
                        ->patchJson(route('svc.files.visibility.update', [$workspace, $file->public_id]), ['is_visible_to_client' => true,
                            'expected_version' => AgentApiVersion::for($file), 'expected_parent_version' => AgentApiVersion::for($parent)])
                        ->assertOk();
                }
                $files[] = $file;
            }
            $parameters = ['clientCompany' => $company, 'recordType' => $type, 'recordPublicId' => $parent->public_id];
            $detail = route('portal.'.$type, [$company, $parent]);
            $this->actingAs($portal)->get($detail)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->has('attachment_panel.files', 2)->where('attachment_panel.upload', null)->where('attachment_panel.files.0.sharing', null)
                ->where('attachment_panel.files.0.delete_href', null)->where('attachment_panel.files.0.id', $files[2]->public_id));
            $first = $this->getJson(route('portal.files.parent.index', $parameters + ['limit' => 1]))->assertOk()->assertJsonCount(1, 'files')->json();
            $this->assertNotNull($first['next_href']);
            $this->getJson($first['next_href'])->assertOk()->assertJsonCount(1, 'files')->assertJsonPath('files.0.id', $files[1]->public_id)->assertJsonPath('next_href', null);
            $this->get(route('portal.files.parent.download', $parameters + ['attachment' => $files[2]->public_id]))->assertOk()->assertStreamedContent('Synthetic commercial bytes');
            $this->get(route('portal.files.parent.download', $parameters + ['attachment' => $files[0]->public_id]))->assertNotFound();
            if ($type === 'proposal') {
                $membership = $company->portalUsers()->whereKey($portal->id)->firstOrFail()->pivot;
                DB::table('client_company_memberships')->where('id', $membership->id)->update(['access_scope' => 'projects']);
                $this->get($detail)->assertNotFound();
                DB::table('client_portal_project_access')->insert(['workspace_id' => $workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $project->id]);
                $this->get($detail)->assertOk();
                DB::table('client_portal_project_access')->where('client_company_membership_id', $membership->id)->delete();
                $this->get(route('portal.files.parent.download', $parameters + ['attachment' => $files[2]->public_id]))->assertNotFound();
                DB::table('client_company_memberships')->where('id', $membership->id)->update(['access_scope' => 'company']);
            }

            $this->getJson(route('portal.files.parent.index', [...$parameters, 'clientCompany' => $sibling]))->assertNotFound();
            $this->get(route('portal.files.parent.download', [...$parameters, 'clientCompany' => $sibling, 'attachment' => $files[2]->public_id]))->assertNotFound();
            DB::table($parent->getTable())->where('workspace_id', $workspace->id)->where('id', $parent->id)->update(['is_visible_to_client' => false]);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->get($detail)->assertNotFound();
            $fileQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'client_attachments'));
            DB::disableQueryLog();
            $this->assertSame([], $fileQueries, 'A refused parent must not query or disclose file metadata.');
            $this->get(route('portal.files.parent.download', $parameters + ['attachment' => $files[2]->public_id]))->assertNotFound();
        }
    }

    private function fixture(): array
    {
        $workspace = $this->syntheticWorkspace('native-sharing');
        $company = $this->syntheticCompany($workspace, 'native-sharing');
        $project = $this->syntheticProject($company, 'native-sharing');
        $project->update(['is_visible_to_client' => false]);
        $owner = $this->syntheticMember($workspace, 'native-sharing-owner', 'owner');
        $topic = ClientCollaborationTopic::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'kind' => 'note', 'title' => 'Synthetic native topic', 'body' => 'Synthetic native body', 'author_user_id' => $owner->id, 'author_name' => $owner->name, 'is_visible_to_client' => false]);

        return [$workspace, $company, $project, $owner, $topic];
    }
}
