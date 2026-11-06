<?php

namespace Tests\Feature\Files;

use App\Models\ClientAttachment;
use App\Models\ClientCollaborationTopic;
use App\Models\ClientInvoice;
use App\Models\ClientProjectMembership;
use App\Models\ClientProposal;
use App\Models\ClientTask;
use App\Services\Files\AttachmentMutationOrigin;
use App\Services\Files\AttachmentParentReference;
use App\Services\Files\AttachmentPrivacyLatch;
use App\Services\Files\AttachmentSharingTransport;
use App\Services\Files\AttachmentStorageService;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\Concerns\CallsNativeFileApi;
use Tests\TestCase;

final class AttachmentSharingNativeTest extends TestCase
{
    use BuildsSyntheticExpenses, RefreshDatabase;
    use CallsNativeFileApi;

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
        $this->actingAs($owner, 'web')->get(route('svc.files.collaboration.show', $parameters))->assertOk()->assertInertia(fn (Assert $page) => $page->where('workspaceNavigation.current_client_id', $company->public_id));
        $created = $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'synthetic-native-upload')
            ->uploadNativeCollaborationFile($workspace, $company->public_id, 'topic', $topic->public_id, ['file' => UploadedFile::fake()->createWithContent('synthetic-native.txt', 'Synthetic native bytes'), 'expected_version' => $version])
            ->assertOk()->assertJsonPath('data.is_visible_to_client', false)->json('data');
        $this->get(route('collaboration.show', [$workspace, $company, $topic]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('collaboration/show')->has('topic.attachment_panel.files', 1)
            ->where('topic.attachment_panel.files.0.filename', 'synthetic-native.txt')
            ->where('topic.attachment_panel.files.0.sharing.is_visible_to_client', false)
            ->where('topic.attachment_panel.upload.href', route('agent-api.v1.collaboration.attachments.upload-url', [$workspace, $company->public_id, 'topic', $topic->public_id], absolute: false)));
        $payload = ['is_visible_to_client' => true, 'expected_version' => $created['version'], 'expected_parent_version' => $version];
        $this->withHeader('Idempotency-Key', 'synthetic-native-share')->nativeFileJson('PATCH', route('agent-api.v1.collaboration.attachments.visibility.update', [$workspace, $company->public_id, 'topic', $topic->public_id, $created['id']]), $payload)
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
        $created = $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'synthetic-portal-upload')
            ->uploadNativeCollaborationFile($workspace, $company->public_id, 'topic', $topic->public_id, ['file' => UploadedFile::fake()->createWithContent('synthetic-private.txt', 'Synthetic private bytes'), 'expected_version' => $version])
            ->assertOk()->json('data');
        $portal = $this->syntheticUser('native-sharing-portal');
        $company->portalUsers()->attach($portal->id, ['role' => 'viewer', 'access_scope' => 'company']);
        $portalParameters = ['clientCompany' => $company, 'recordType' => 'topic', 'recordPublicId' => $topic->public_id];
        $this->actingAs($portal, 'web')->nativeFileJson('GET', route('agent-api.v1.collaboration.attachments.index', [$workspace, $company->public_id, 'topic', $topic->public_id, 'presentation' => 'portal']), [])->assertOk()->assertJsonCount(0, 'data.files')->assertJsonPath('data.upload', null);
        $this->get(route('portal.files.collaboration.download', [...$portalParameters, 'attachment' => $created['id']]))->assertNotFound();
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'synthetic-portal-share')
            ->nativeFileJson('PATCH', route('agent-api.v1.collaboration.attachments.visibility.update', [$workspace, $company->public_id, 'topic', $topic->public_id, $created['id']]), ['is_visible_to_client' => true, 'expected_version' => $created['version'], 'expected_parent_version' => $version])->assertOk();
        $this->actingAs($portal, 'web')->nativeFileJson('GET', route('agent-api.v1.collaboration.attachments.index', [$workspace, $company->public_id, 'topic', $topic->public_id, 'presentation' => 'portal']), [])->assertOk()->assertJsonCount(1, 'data.files')->assertJsonPath('data.files.0.sharing', null)->assertJsonPath('data.files.0.delete_href', null)->assertJsonPath('data.upload', null);
        $topic->update(['is_visible_to_client' => false]);
        $this->get(route('portal.files.collaboration.download', [...$portalParameters, 'attachment' => $created['id']]))->assertNotFound();
    }

    public function test_legacy_parent_page_is_discoverable_and_cannot_escape_workspace_or_publish_authority(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $parameters = [$workspace, 'company', $company->public_id];
        $href = route('svc.files.parent.show', $parameters, absolute: false);
        $this->actingAs($owner, 'web')->get(route('clients.show', [$workspace, $company]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('files_href', $href));
        $this->get($href)->assertOk()->assertInertia(fn (Assert $page) => $page->component('files/parent')->has('attachment_panel.files', 0)->has('workspaceNavigation')->where('attachment_panel.upload.href', route('agent-api.v1.attachments.upload-url', $parameters, absolute: false))->missing('legacy_upload_href'));
        $other = $this->syntheticWorkspace('native-sharing-other');
        $this->get(route('svc.files.parent.show', [$other, 'company', $company->public_id]))->assertNotFound();
        $contributor = $this->syntheticMember($workspace, 'native-contributor', 'member');
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $contributor->id, 'role' => 'contributor']);
        $this->actingAs($contributor, 'web')->get(route('svc.files.parent.show', [$workspace, 'project', $project->public_id]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('attachment_panel.upload', null)->missing('legacy_upload_href'));
    }

    public function test_financial_detail_and_operations_use_shared_seeded_controls_without_per_record_queries(): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $this->actingAs($owner, 'web');
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
                    $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'commercial-share-'.$type.'-'.$index)
                        ->nativeFileJson('PATCH', route('agent-api.v1.attachments.visibility.update', [$workspace, $file->public_id]), ['is_visible_to_client' => true,
                            'expected_version' => AgentApiVersion::for($file), 'expected_parent_version' => AgentApiVersion::for($parent)])
                        ->assertOk();
                }
                $files[] = $file;
            }
            $parameters = ['clientCompany' => $company, 'recordType' => $type, 'recordPublicId' => $parent->public_id];
            $detail = route('portal.'.$type, [$company, $parent]);
            $this->actingAs($portal, 'web')->get($detail)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->has('attachment_panel.files', 2)->where('attachment_panel.upload', null)->where('attachment_panel.files.0.sharing', null)
                ->where('attachment_panel.files.0.delete_href', null)->where('attachment_panel.files.0.id', $files[2]->public_id));
            $first = $this->nativeFileJson('GET', route('agent-api.v1.attachments.index', [$workspace, $type, $parent->public_id, 'presentation' => 'portal', 'client' => $company->public_id, 'limit' => 1]), [])->assertOk()->assertJsonCount(1, 'data.files')->json('data');
            $this->assertNotNull($first['next_href']);
            $this->nativeFileJson('GET', $first['next_href'], [])->assertOk()->assertJsonCount(1, 'data.files')->assertJsonPath('data.files.0.id', $files[1]->public_id)->assertJsonPath('data.next_href', null);
            $this->get(route('portal.files.parent.download', $parameters + ['attachment' => $files[2]->public_id]))->assertOk()->assertStreamedContent('Synthetic commercial bytes');
            $this->get(route('portal.files.parent.download', $parameters + ['attachment' => $files[0]->public_id]))->assertNotFound();
            if ($type === 'proposal') {
                $membership = DB::table('client_company_memberships')->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->where('user_id', $portal->id)->firstOrFail();
                DB::table('client_company_memberships')->where('id', $membership->id)->update(['access_scope' => 'projects']);
                $this->get($detail)->assertNotFound();
                DB::table('client_portal_project_access')->insert(['workspace_id' => $workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $project->id]);
                $this->get($detail)->assertOk();
                DB::table('client_portal_project_access')->where('client_company_membership_id', $membership->id)->delete();
                $this->get(route('portal.files.parent.download', $parameters + ['attachment' => $files[2]->public_id]))->assertNotFound();
                DB::table('client_company_memberships')->where('id', $membership->id)->update(['access_scope' => 'company']);
            }

            $this->nativeFileJson('GET', route('agent-api.v1.attachments.index', [$workspace, $type, $parent->public_id, 'presentation' => 'portal', 'client' => $sibling->public_id]), [])->assertNotFound();
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

    public function test_existing_commercial_page_is_preserved_when_its_file_lineage_is_invalid(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $proposal = ClientProposal::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'title' => 'Synthetic existing commercial terms', 'status' => 'sent', 'currency' => 'USD', 'is_visible_to_client' => true]);
        $file = app(AttachmentStorageService::class)->store($workspace, $proposal, UploadedFile::fake()->createWithContent('SyntheticHiddenParentFile.txt', 'Synthetic file bytes'), $owner);
        DB::table('client_attachments')->where('workspace_id', $workspace->id)->where('id', $file->id)->update(['is_visible_to_client' => true]);
        $sibling = $this->syntheticCompany($workspace, 'commercial-file-malformed-sibling');
        $foreignProject = $this->syntheticProject($sibling, 'commercial-file-malformed-project');
        DB::table('client_proposals')->where('workspace_id', $workspace->id)->where('id', $proposal->id)->update(['client_project_id' => $foreignProject->id]);
        $portal = $this->syntheticUser('commercial-parent-portal');
        $company->portalUsers()->attach($portal->id, ['role' => 'viewer', 'access_scope' => 'company']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($portal, 'web')->get(route('portal.proposal', [$company, $proposal]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('proposal.title', 'Synthetic existing commercial terms')->where('attachment_panel', null));
        $fileQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'client_attachments'));
        DB::disableQueryLog();
        $this->assertSame([], $fileQueries);
        $this->nativeFileJson('GET', route('agent-api.v1.attachments.index', [$workspace, 'proposal', $proposal->public_id, 'presentation' => 'portal', 'client' => $company->public_id]), [])->assertNotFound();
    }

    public function test_portal_project_and_task_file_leaves_follow_current_grants_and_ancestry_without_company_disclosure(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $project->update(['is_visible_to_client' => true]);
        $task = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id,
            'title' => 'Synthetic file task', 'status' => 'pending', 'is_visible_to_client' => true]);
        $child = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id,
            'title' => 'Synthetic descendant file task', 'status' => 'pending', 'is_visible_to_client' => true]);
        DB::table('client_tasks')->where('workspace_id', $workspace->id)->where('id', $child->id)->update(['parent_task_id' => $task->id]);
        $portal = $this->syntheticUser('project-file-portal');
        $company->portalUsers()->attach($portal->id, ['role' => 'viewer', 'access_scope' => 'projects']);
        $membership = DB::table('client_company_memberships')->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->where('user_id', $portal->id)->firstOrFail();
        DB::table('client_portal_project_access')->insert(['workspace_id' => $workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $project->id]);
        $held = [];
        foreach (['project' => $project, 'task' => $child] as $type => $parent) {
            $file = app(AttachmentStorageService::class)->store($workspace, $parent, UploadedFile::fake()->createWithContent('Synthetic-'.$type.'-shared.txt', 'Synthetic project/task bytes'), $owner);
            $private = app(AttachmentStorageService::class)->store($workspace, $parent, UploadedFile::fake()->createWithContent('Synthetic-'.$type.'-private.txt', 'Synthetic private bytes'), $owner);
            $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'project-task-share-'.$type)->nativeFileJson('PATCH', route('agent-api.v1.attachments.visibility.update', [$workspace, $file->public_id]),
                ['is_visible_to_client' => true, 'expected_version' => AgentApiVersion::for($file), 'expected_parent_version' => AgentApiVersion::for($parent)])->assertOk();
            config(['svc.attachments.sharing_writes_enabled' => false]);
            $parameters = [$company, $type, $parent->public_id];
            $this->actingAs($portal, 'web')->get(route('portal.files.parent.show', $parameters))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('files/parent')->has('attachment_panel.files', 1)->where('attachment_panel.files.0.id', $file->public_id)
                ->where('attachment_panel.files.0.sharing', null)->where('attachment_panel.files.0.delete_href', null)->where('attachment_panel.upload', null));
            $url = route('portal.files.parent.download', [...$parameters, $file->public_id]);
            $held[$type] = $url;
            $this->get($url)->assertOk()->assertStreamedContent('Synthetic project/task bytes');
            $this->get(route('portal.files.parent.download', [...$parameters, $private->public_id]))->assertNotFound();
            config(['svc.attachments.sharing_writes_enabled' => true]);
        }
        $this->get(route('portal.show', $company))->assertOk()->assertInertia(fn (Assert $page) => $page->missing('files_href')->where('open_tasks.0.files_href', route('portal.files.parent.show', [$company, 'task', $child->public_id], absolute: false)));
        $this->get(route('portal.tasks', $company))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('projects.0.files_href', route('portal.files.parent.show', [$company, 'project', $project->public_id], absolute: false))->has('tasks', 2));
        $this->get(route('portal.files.parent.show', [$company, 'company', $company->public_id]))->assertNotFound();
        $this->nativeFileJson('GET', route('agent-api.v1.attachments.index', [$workspace, 'company', $company->public_id, 'presentation' => 'portal', 'client' => $company->public_id]), [])->assertNotFound();
        DB::table('client_tasks')->where('workspace_id', $workspace->id)->where('id', $task->id)->update(['is_visible_to_client' => false]);
        $this->get($held['task'])->assertNotFound();
        $this->get(route('portal.tasks', $company))->assertOk()->assertInertia(fn (Assert $page) => $page->has('tasks', 0));
        DB::table('client_portal_project_access')->where('client_company_membership_id', $membership->id)->delete();
        $this->get($held['project'])->assertNotFound();
        $this->get(route('portal.tasks', $company))->assertOk()->assertInertia(fn (Assert $page) => $page->has('projects', 0)->has('tasks', 0));
        $sibling = $this->syntheticCompany($workspace, 'project-task-file-sibling');
        $this->get(route('portal.files.parent.show', [$sibling, 'project', $project->public_id]))->assertNotFound();
    }

    public function test_native_cursor_continuations_preserve_contributor_access_and_current_parent_authority(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $files = [];
        foreach (range(1, 3) as $number) {
            $files[] = app(AttachmentStorageService::class)->store($workspace, $project, UploadedFile::fake()->createWithContent('Synthetic-page-'.$number.'.txt', 'Synthetic cursor bytes'), $owner);
        }
        $contributor = $this->syntheticMember($workspace, 'pagination-contributor', 'member');
        $membership = ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $contributor->id, 'role' => 'contributor']);
        config(['agent_api.writes_enabled' => false, 'agent_api.file_writes_enabled' => false, 'svc.attachments.sharing_writes_enabled' => false]);
        $this->actingAs($contributor, 'web');
        $url = route('agent-api.v1.attachments.index', [$workspace, 'project', $project->public_id, 'presentation' => 'native', 'limit' => 1]);
        $first = $this->nativeFileJson('GET', $url, [])->assertOk()->assertJsonCount(1, 'data.files')
            ->assertJsonPath('data.files.0.id', $files[2]->public_id)->assertJsonPath('data.files.0.sharing', null)
            ->assertJsonPath('data.files.0.delete_href', null)->assertJsonPath('data.upload', null)->json('data');
        $this->assertStringContainsString('/api/v1/', $first['next_href']);
        $this->assertStringContainsString('presentation=native', $first['next_href']);
        $this->assertStringContainsString('cursor=', $first['next_href']);
        $second = $this->nativeFileJson('GET', $first['next_href'], [])->assertOk()->assertJsonCount(1, 'data.files')
            ->assertJsonPath('data.files.0.id', $files[1]->public_id)->json('data');
        $this->nativeFileJson('GET', $second['next_href'], [])->assertOk()->assertJsonPath('data.files.0.id', $files[0]->public_id)->assertJsonPath('data.next_href', null);
        $this->get($first['files'][0]['download_href'])->assertOk()->assertStreamedContent('Synthetic cursor bytes');
        $foreign = $this->syntheticWorkspace('pagination-foreign');
        $this->nativeFileJson('GET', route('agent-api.v1.attachments.index', [$foreign, 'project', $project->public_id, 'presentation' => 'native']), [])->assertNotFound();
        $sibling = $this->syntheticProject($company, 'pagination-sibling');
        $this->nativeFileJson('GET', str_replace($project->public_id, $sibling->public_id, $first['next_href']), [])->assertNotFound();
        $membership->delete();
        $this->nativeFileJson('GET', $first['next_href'], [])->assertNotFound()->assertDontSee('Synthetic-page', false);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_owner_portal_projection_stays_safe_and_cursors_cannot_change_audience_or_actor(): void
    {
        [$workspace, $company, $project, $owner, $topic] = $this->fixture();
        $project->update(['is_visible_to_client' => true]);
        // Collaboration retains its exact Portal membership policy, including owner previews.
        $company->portalUsers()->attach($owner->id, ['role' => 'viewer', 'access_scope' => 'company']);
        $topic->update(['is_visible_to_client' => true]);
        foreach ([$project, $topic] as $parent) {
            foreach (range(1, 3) as $number) {
                $upload = UploadedFile::fake()->createWithContent($number === 3 ? 'Synthetic-private-name.txt' : 'Synthetic-public-'.$number.'.txt', 'Synthetic safe preview');
                if ($parent === $topic) {
                    $created = app(AttachmentSharingTransport::class)->upload($owner, $workspace, new AttachmentParentReference('topic', $topic->public_id, $company->public_id), $upload,
                        ['expected_version' => AgentApiVersion::for($topic)], 'synthetic-pagination', 'synthetic-preview-'.$number, AttachmentMutationOrigin::Native);
                    $file = ClientAttachment::query()->where('workspace_id', $workspace->id)->where('public_id', $created['data']['id'])->firstOrFail();
                } else {
                    $file = app(AttachmentStorageService::class)->store($workspace, $parent, $upload, $owner);
                }
                DB::table('client_attachments')->where('workspace_id', $workspace->id)->where('id', $file->id)->update(['is_visible_to_client' => $number !== 3]);
            }
            $collaboration = $parent === $topic;
            $url = $collaboration
                ? route('agent-api.v1.collaboration.attachments.index', [$workspace, $company->public_id, 'topic', $topic->public_id, 'presentation' => 'portal', 'limit' => 1])
                : route('agent-api.v1.attachments.index', [$workspace, 'project', $project->public_id, 'presentation' => 'portal', 'client' => $company->public_id, 'limit' => 1]);
            $this->actingAs($owner, 'web');
            $first = $this->nativeFileJson('GET', $url, [])->assertOk()->assertJsonCount(1, 'data.files')
                ->assertJsonPath('data.files.0.sharing', null)->assertJsonPath('data.files.0.deletion', null)->assertJsonPath('data.upload', null)
                ->assertDontSee('Synthetic-private-name', false)->json('data');
            $this->nativeFileJson('GET', $first['next_href'], [])->assertOk()->assertJsonCount(1, 'data.files')->assertJsonPath('data.next_href', null)->assertDontSee('Synthetic-private-name', false);
            $this->nativeFileJson('GET', str_replace('presentation=portal', 'presentation=native', $first['next_href']), [])->assertUnprocessable();
            $viewer = $this->syntheticUser('pagination-portal-'.($collaboration ? 'topic' : 'project'));
            $company->portalUsers()->attach($viewer->id, ['role' => 'viewer', 'access_scope' => 'company']);
            $this->actingAs($viewer, 'web');
            $this->nativeFileJson('GET', $first['next_href'], [])->assertUnprocessable();
            $this->nativeFileJson('GET', $url, [])->assertOk()->assertDontSee('Synthetic-private-name', false)->assertJsonPath('data.upload', null);
            $this->nativeFileJson('GET', str_replace('presentation=portal', 'presentation=native', $url), [])->assertNotFound();
            $this->actingAs($owner, 'web');
            DB::table($parent->getTable())->where('workspace_id', $workspace->id)->where('id', $parent->id)->update(['is_visible_to_client' => false]);
            $this->nativeFileJson('GET', $first['next_href'], [])->assertNotFound()->assertDontSee('Synthetic-public', false);
            if (! $collaboration) {
                $project->refresh()->update(['is_visible_to_client' => true]);
            }
        }
    }

    public function test_bearer_cannot_forge_native_panel_representation_or_widen_ordinary_agent_reads(): void
    {
        [$workspace, $company, $project, $owner, $topic] = $this->fixture();
        $file = app(AttachmentStorageService::class)->store($workspace, $project, UploadedFile::fake()->createWithContent('Synthetic-agent-file.txt', 'Synthetic agent bytes'), $owner);
        $this->actingAsMcp($owner, ['files:read', 'collaboration:read']);
        foreach (['native', 'portal'] as $presentation) {
            $this->getJson(route('agent-api.v1.attachments.index', [$workspace, 'project', $project->public_id, 'presentation' => $presentation, 'client' => $company->public_id, 'svc_first_party_session' => true]))->assertForbidden();
            $this->getJson(route('agent-api.v1.collaboration.attachments.index', [$workspace, $company->public_id, 'topic', $topic->public_id, 'presentation' => $presentation]))->assertForbidden();
        }
        $this->getJson(route('agent-api.v1.attachments.index', [$workspace, 'project', $project->public_id]))->assertOk()
            ->assertJsonPath('data.0.id', $file->public_id)->assertJsonStructure(['data', 'parent_version', 'meta' => ['next_cursor']])
            ->assertJsonMissingPath('data.0.download_href')->assertJsonMissingPath('data.0.sharing');
        foreach (['svc.files.parent.index', 'svc.files.collaboration.index', 'portal.files.parent.index', 'portal.files.collaboration.index'] as $route) {
            $this->assertFalse(Route::has($route));
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
