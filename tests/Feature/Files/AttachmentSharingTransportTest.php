<?php

namespace Tests\Feature\Files;

use App\Models\ClientAttachment;
use App\Models\ClientCollaborationTopic;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Collaboration\CollaborationReadService;
use App\Services\Files\AttachmentAudience;
use App\Services\Files\AttachmentMutationOrigin;
use App\Services\Files\AttachmentParentReference;
use App\Services\Files\AttachmentPrivacyLatch;
use App\Services\Files\AttachmentSharingPresenter;
use App\Services\Files\AttachmentSharingTransport;
use App\Services\Files\AttachmentStorageService;
use App\Services\Files\CollaborationAttachmentPreview;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\TestCase;

final class AttachmentSharingTransportTest extends TestCase
{
    use BuildsSyntheticExpenses, RefreshDatabase;

    private string $policyDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policyDirectory = sys_get_temp_dir().'/svc-sharing-transport-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->policyDirectory, 0700);
        File::put($this->policyDirectory.'/'.AttachmentPrivacyLatch::MARKER, "enabled\n");
        app()->instance(AttachmentPrivacyLatch::class, new AttachmentPrivacyLatch($this->policyDirectory));
        config(['svc.attachments.sharing_writes_enabled' => true, 'svc.filesystem_disk' => 'svc_files',
            'agent_api.writes_enabled' => true, 'agent_api.file_writes_enabled' => true, 'agent_api.collaboration_writes_enabled' => true]);
        Storage::fake('svc_files');
        Route::post('/synthetic/{workspace}/{client}/{recordType}/{recordPublicId}/upload', fn () => null)
            ->name('agent-api.v1.collaboration.attachments.upload');
        Route::get('/synthetic/{workspace}/{client}/{recordType}/{recordPublicId}/files', fn () => null)
            ->name('agent-api.v1.collaboration.attachments.index');
        Route::getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->policyDirectory);
        parent::tearDown();
    }

    public function test_upload_replay_uses_current_authority_without_staging_or_reusing_stale_versions(): void
    {
        [$workspace, $company, $project, $owner, $publisher, $topic] = $this->fixture();
        $service = app(AttachmentSharingTransport::class);
        $scope = new AttachmentParentReference('topic', $topic->public_id, $company->public_id);
        $payload = ['expected_version' => AgentApiVersion::for($topic)];
        $result = $service->upload($publisher, $workspace, $scope, $this->upload(), $payload, 'synthetic-client', 'upload-one', AttachmentMutationOrigin::Agent);
        $this->assertFalse($result['data']['is_visible_to_client']);
        $this->assertFalse($result['data']['effective_visible_to_client']);
        $this->assertSame('The parent is not currently visible to clients.', $result['data']['sharing_note']);
        $objects = Storage::disk('svc_files')->allFiles();
        $topic->update(['title' => 'Synthetic later title']);
        $replay = $service->upload($publisher, $workspace, $scope, $this->upload(), $payload, 'synthetic-client', 'upload-one', AttachmentMutationOrigin::Agent);
        $this->assertSame($result, $replay);
        $this->assertSame($objects, Storage::disk('svc_files')->allFiles());
        $this->assertDatabaseCount('client_attachments', 1);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
        $this->assertRefused(409, fn () => $service->upload($publisher, $workspace, $scope,
            UploadedFile::fake()->createWithContent('synthetic.txt', 'Changed synthetic bytes'), $payload, 'synthetic-client', 'upload-one', AttachmentMutationOrigin::Agent));
        ClientProjectMembership::query()->where('workspace_id', $workspace->id)->where('client_project_id', $project->id)->where('user_id', $publisher->id)->delete();
        $this->assertRefused(403, fn () => $service->upload($publisher, $workspace, $scope, $this->upload(), $payload,
            'synthetic-client', 'upload-one', AttachmentMutationOrigin::Agent));
        $this->assertSame($objects, Storage::disk('svc_files')->allFiles());
    }

    public function test_visibility_replay_does_not_bump_but_new_key_does_and_readers_survive_flag_off(): void
    {
        [$workspace, $company, $project, $owner, $publisher, $topic] = $this->fixture();
        $service = app(AttachmentSharingTransport::class);
        $scope = new AttachmentParentReference('topic', $topic->public_id, $company->public_id);
        $created = $service->upload($publisher, $workspace, $scope, $this->upload(), ['expected_version' => AgentApiVersion::for($topic)],
            'synthetic-client', 'upload-share', AttachmentMutationOrigin::Agent);
        $id = $created['data']['id'];
        $payload = ['is_visible_to_client' => true, 'expected_version' => $created['data']['version'], 'expected_parent_version' => AgentApiVersion::for($topic)];
        $shared = $service->updateVisibility($publisher, $workspace, $scope, $id, $payload, 'synthetic-client', 'share-one', AttachmentMutationOrigin::Agent);
        $file = ClientAttachment::query()->where('public_id', $id)->firstOrFail();
        $this->assertTrue($shared['data']['is_visible_to_client']);
        $this->assertFalse($shared['data']['effective_visible_to_client']);
        $this->assertSame($shared, $service->updateVisibility($publisher, $workspace, $scope, $id, $payload, 'synthetic-client', 'share-one', AttachmentMutationOrigin::Agent));
        $this->assertSame($file->lock_version, $file->fresh()->lock_version);
        $payload['expected_version'] = $shared['data']['version'];
        $service->updateVisibility($publisher, $workspace, $scope, $id, $payload, 'synthetic-client', 'share-two', AttachmentMutationOrigin::Agent);
        $this->assertSame($file->lock_version + 1, $file->fresh()->lock_version);
        $project->update(['is_visible_to_client' => true]);
        $topic->update(['is_visible_to_client' => true]);
        config(['svc.attachments.sharing_writes_enabled' => false]);
        $portal = $this->syntheticUser('sharing-reader');
        $company->portalUsers()->attach($portal, ['role' => 'client']);
        $rows = $service->listing($portal, $workspace, $scope, AttachmentAudience::Portal);
        $this->assertSame([$id], array_column($rows['data'], 'id'));
        $this->assertTrue($rows['data'][0]['effective_visible_to_client']);
        $this->assertNull($rows['data'][0]['sharing_note']);
        $this->assertRefused(404, fn () => $service->updateVisibility($publisher, $workspace, $scope, $id, $payload,
            'synthetic-client', 'flag-off', AttachmentMutationOrigin::Agent));
    }

    public function test_delete_receipt_replays_after_physical_purge_but_not_after_revocation(): void
    {
        [$workspace, $company, $project, $owner, $publisher, $topic] = $this->fixture();
        $service = app(AttachmentSharingTransport::class);
        $scope = new AttachmentParentReference('topic', $topic->public_id, $company->public_id);
        $created = $service->upload($publisher, $workspace, $scope, $this->upload(), ['expected_version' => AgentApiVersion::for($topic)],
            'synthetic-client', 'upload-delete', AttachmentMutationOrigin::Agent);
        $payload = ['confirm' => true, 'expected_version' => $created['data']['version'], 'expected_parent_version' => AgentApiVersion::for($topic)];
        $deleted = $service->delete($publisher, $workspace, $scope, $created['data']['id'], $payload, 'synthetic-client', 'delete-one', AttachmentMutationOrigin::Agent);
        $this->assertSame(ClientAttachment::STATE_DELETING, $deleted['data']['status']);
        $this->travel(8)->days();
        $this->assertSame(1, app(AttachmentStorageService::class)->repair(true)['purged_rows']);
        $replay = $service->delete($publisher, $workspace, $scope, $created['data']['id'], $payload, 'synthetic-client', 'delete-one', AttachmentMutationOrigin::Agent);
        $this->assertSame(ClientAttachment::STATE_DELETED, $replay['data']['status']);
        $this->assertSame([], Storage::disk('svc_files')->allFiles());
        $company->portalUsers()->attach($publisher, ['role' => 'client']);
        $this->assertRefused(403, fn () => $service->delete($publisher, $workspace, $scope, $created['data']['id'], $payload,
            'synthetic-client', 'delete-one', AttachmentMutationOrigin::Agent));
    }

    public function test_url_preparation_and_native_capabilities_require_latch_without_durable_side_effects(): void
    {
        [$workspace, $company, $project, $owner, $publisher, $topic] = $this->fixture();
        $service = app(AttachmentSharingTransport::class);
        $scope = new AttachmentParentReference('topic', $topic->public_id, $company->public_id);
        $url = $service->uploadUrl($publisher, $workspace, $scope, AttachmentMutationOrigin::Agent);
        $this->assertSame(AgentApiVersion::for($topic), $url['data']['expected_version']);
        $this->assertSame('Idempotency-Key', $url['data']['idempotency_header']);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseCount('agent_mutation_audits', 0);
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertSame([], Storage::disk('svc_files')->allFiles());
        $created = $service->upload($publisher, $workspace, $scope, $this->upload(), ['expected_version' => AgentApiVersion::for($topic)],
            'synthetic-native', 'native-one', AttachmentMutationOrigin::Native);
        $file = ClientAttachment::query()->where('public_id', $created['data']['id'])->firstOrFail();
        $presenter = app(AttachmentSharingPresenter::class);
        $view = $presenter->view($publisher, $workspace, $scope, AttachmentAudience::Internal);
        $control = $presenter->nativeControl($file, $view, '/synthetic-update', AttachmentAudience::Internal);
        $this->assertSame('/synthetic-update', $control['update_href']);
        $this->assertNull($presenter->nativeControl($file, $view, '/synthetic-update', AttachmentAudience::PortalPreview));
        File::delete($this->policyDirectory.'/'.AttachmentPrivacyLatch::MARKER);
        $this->assertNull($presenter->nativeControl($file, $view, '/synthetic-update', AttachmentAudience::Internal));
        $this->assertRefused(503, fn () => $service->uploadUrl($publisher, $workspace, $scope, AttachmentMutationOrigin::Agent));
        $this->assertCount(1, $service->listing($publisher, $workspace, $scope, AttachmentAudience::Internal)['data']);
    }

    public function test_legacy_read_state_is_manager_only_closed_to_six_parents_and_native_dual_can_write(): void
    {
        [$workspace, $company, , $owner, $publisher] = $this->fixture();
        $file = app(AttachmentStorageService::class)->store($workspace, $company, $this->upload(), $owner);
        $company->portalUsers()->attach($owner, ['role' => 'client']);
        $service = app(AttachmentSharingTransport::class);
        $scope = $service->legacyReference($owner, $workspace, $file->public_id, AttachmentAudience::Internal);
        $payload = ['is_visible_to_client' => true, 'expected_version' => AgentApiVersion::for($file), 'expected_parent_version' => AgentApiVersion::for($company)];
        $native = $service->updateVisibility($owner, $workspace, $scope, $file->public_id, $payload,
            'synthetic-native', 'native-dual', AttachmentMutationOrigin::Native);
        $this->assertTrue($native['data']['is_visible_to_client']);
        $this->assertFalse($native['data']['effective_visible_to_client']);
        $this->assertRefused(403, fn () => $service->updateVisibility($owner, $workspace, $scope, $file->public_id,
            [...$payload, 'expected_version' => $native['data']['version']], 'synthetic-agent', 'agent-dual', AttachmentMutationOrigin::Agent));
        $this->assertRefused(403, fn () => $service->legacyReference($publisher, $workspace, $file->public_id));
        $otherWorkspace = $this->syntheticWorkspace('foreign-sharing');
        $otherCompany = $this->syntheticCompany($otherWorkspace, 'foreign-sharing');
        $this->assertRefused(404, fn () => $service->visibility($owner, $workspace, new AttachmentParentReference('company', $otherCompany->public_id),
            $file->public_id, AttachmentAudience::Internal));
        config(['svc.attachments.sharing_writes_enabled' => false]);
        $this->assertSame($native, $service->visibility($owner, $workspace, $scope, $file->public_id, AttachmentAudience::Internal));
        $this->expectException(ValidationException::class);
        config(['svc.attachments.sharing_writes_enabled' => true]);
        $service->updateVisibility($owner, $workspace, $scope, $file->public_id, [...$payload, 'origin' => 'native'],
            'synthetic-native', 'invalid-extra', AttachmentMutationOrigin::Native);
    }

    public function test_cli_preview_has_no_objects_rows_receipts_or_audit_and_apply_uses_the_same_private_contract(): void
    {
        [$workspace, $company, , , $publisher, $topic] = $this->fixture();
        $source = $this->policyDirectory.'/synthetic-source.txt';
        $input = $this->policyDirectory.'/synthetic-request.json';
        File::put($source, 'Synthetic command upload bytes');
        File::put($input, json_encode(['source_path' => $source, 'expected_version' => AgentApiVersion::for($topic)], JSON_THROW_ON_ERROR));
        chmod($input, 0600);
        $arguments = ['operation' => 'upload', 'workspace' => $workspace->public_id, 'record_type' => 'topic', 'record_id' => $topic->public_id,
            '--company' => $company->public_id, '--actor' => $publisher->public_id, '--input' => $input, '--key' => 'synthetic-cli-key', '--format' => 'json'];
        $this->artisan('svc:attachments', $arguments)->assertExitCode(0);
        $this->assertDatabaseCount('client_attachments', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->assertDatabaseCount('agent_mutation_audits', 0);
        $this->assertSame([], Storage::disk('svc_files')->allFiles());
        chmod($input, 0644);
        $this->artisan('svc:attachments', $arguments)->assertExitCode(1);
        $this->assertDatabaseCount('client_attachments', 0);
        chmod($input, 0600);
        $this->artisan('svc:attachments', [...$arguments, '--apply' => true])->assertExitCode(0);
        $file = ClientAttachment::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertFalse($file->is_visible_to_client);
        $this->assertSame('synthetic-source.txt', $file->original_filename);
        Storage::disk('svc_files')->assertExists($file->object_key);
        $this->assertDatabaseCount('agent_mutation_receipts', 1);
        $this->assertDatabaseCount('agent_mutation_audits', 1);
    }

    public function test_comment_previews_filter_before_window_bounds_and_agent_budget_without_truncation(): void
    {
        [$workspace, $company, $project, $owner, $publisher, $topic] = $this->fixture();
        $project->update(['is_visible_to_client' => true]);
        $topic->update(['is_visible_to_client' => true]);
        $comments = [];
        $sharedNames = [];
        for ($index = 0; $index < 3; $index++) {
            $comment = ClientComment::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'client_project_id' => $project->id, 'client_collaboration_topic_id' => $topic->id, 'body' => 'Synthetic attachment comment '.$index,
                'author_user_id' => $publisher->id, 'author_name' => $publisher->name, 'is_visible_to_client' => true]);
            $comments[] = $comment;
            for ($number = 0; $number < 9; $number++) {
                $name = $number < 4 ? 'Synthetic-'.str_repeat('測試', 100).'-'.$index.'-'.$number.'.txt' : 'Synthetic private filename '.$index.'-'.$number;
                $file = ClientAttachment::query()->create(['workspace_id' => $workspace->id, 'record_type' => 'comment', 'record_public_id' => $comment->public_id,
                    'object_key' => 'synthetic-comment/'.$index.'/'.$number, 'original_filename' => $name, 'media_type' => 'text/plain', 'bytes' => 1,
                    'sha256' => str_repeat('a', 64), 'uploader_id' => $publisher->id, 'lifecycle_state' => ClientAttachment::STATE_AVAILABLE]);
                if ($number < 4) {
                    ClientAttachment::query()->where('workspace_id', $workspace->id)->whereKey($file->id)->update(['is_visible_to_client' => true]);
                    $sharedNames[$comment->public_id][] = $name;
                }
            }
        }
        $portal = $this->syntheticUser('preview-portal');
        $company->portalUsers()->attach($portal, ['role' => 'client']);
        $previews = app(CollaborationAttachmentPreview::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $single = $previews->comments($portal, $workspace, $company, [$comments[0]], AttachmentAudience::Portal, false, true, agent: true);
        $oneCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        $all = $previews->comments($portal, $workspace, $company, $comments, AttachmentAudience::Portal, false, true, agent: true);
        $allQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($oneCount, count($allQueries), 'Query count must not grow with comments.');
        $this->assertCount(1, array_filter($allQueries, fn (array $query): bool => str_contains($query['query'], 'ROW_NUMBER()')));
        $this->assertSame(array_reverse(array_slice($sharedNames[$comments[0]->public_id], 1)), array_column($single[$comments[0]->public_id]['data'], 'filename'));
        foreach ($all as $preview) {
            $this->assertCount(3, $preview['data']);
            $this->assertNotNull($preview['next_href']);
            $this->assertStringNotContainsString('private filename', json_encode($preview, JSON_THROW_ON_ERROR));
        }
        $read = app(CollaborationReadService::class);
        $withoutFiles = $read->commentPage($portal, $workspace, $company, 'topic', $topic->public_id, CollaborationAudience::Portal, 3, null);
        $this->assertArrayNotHasKey('attachments', $withoutFiles['data'][0]);
        config(['agent_api.mcp_max_result_bytes' => 32768, 'agent_api.mcp_max_response_body_bytes' => 32768]);
        $withFiles = $read->commentPage($portal, $workspace, $company, 'topic', $topic->public_id, CollaborationAudience::Portal, 3, null, includeFiles: true, agentFiles: true);
        $this->assertCount(1, $withFiles['data']);
        $this->assertNotNull($withFiles['meta']['next_cursor']);
        $this->assertSame($single[$comments[0]->public_id], $withFiles['data'][0]['attachments']);
        $topic->update(['is_visible_to_client' => false]);
        $hidden = $previews->comments($portal, $workspace, $company, $comments, AttachmentAudience::Portal, false, false, agent: true);
        foreach ($hidden as $preview) {
            $this->assertSame([], $preview['data']);
            $this->assertNull($preview['next_href']);
        }
    }

    /** @return array{Workspace,ClientCompany,ClientProject,User,User,ClientCollaborationTopic} */
    private function fixture(): array
    {
        $workspace = $this->syntheticWorkspace('transport-sharing');
        $company = $this->syntheticCompany($workspace, 'transport-sharing');
        $project = $this->syntheticProject($company, 'transport-sharing');
        $project->update(['is_visible_to_client' => false]);
        $owner = $this->syntheticMember($workspace, 'transport-owner', 'owner');
        $publisher = $this->syntheticMember($workspace, 'transport-publisher', 'member');
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $publisher->id, 'role' => 'manager']);
        $topic = ClientCollaborationTopic::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'client_project_id' => $project->id, 'kind' => 'note', 'title' => 'Synthetic transport topic', 'body' => 'Synthetic topic body',
            'author_user_id' => $owner->id, 'author_name' => $owner->name, 'is_visible_to_client' => false]);

        return [$workspace, $company, $project, $owner, $publisher, $topic];
    }

    private function upload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('synthetic.txt', 'Synthetic private bytes');
    }

    private function assertRefused(int $status, callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('The sharing operation unexpectedly succeeded.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        } catch (ModelNotFoundException) {
            $this->assertSame(404, $status);
        }
    }
}
