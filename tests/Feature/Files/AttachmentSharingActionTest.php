<?php

namespace Tests\Feature\Files;

use App\Models\ClientAttachment;
use App\Models\ClientCollaborationTopic;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Collaboration\CollaborationAction;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Collaboration\TopicDraft;
use App\Services\Files\AttachmentAudience;
use App\Services\Files\AttachmentDeletion;
use App\Services\Files\AttachmentMutationOrigin;
use App\Services\Files\AttachmentParentReference;
use App\Services\Files\AttachmentPrivacyLatch;
use App\Services\Files\AttachmentReader;
use App\Services\Files\AttachmentRecordResolver;
use App\Services\Files\AttachmentSharingAction;
use App\Services\Files\AttachmentSharingRepository;
use App\Services\Files\AttachmentStorageService;
use App\Services\Files\AttachmentVisibilityChange;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\TestCase;

final class AttachmentSharingActionTest extends TestCase
{
    use BuildsSyntheticExpenses, RefreshDatabase;

    private string $policyDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policyDirectory = sys_get_temp_dir().'/svc-sharing-action-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->policyDirectory, 0700);
        File::put($this->policyDirectory.'/'.AttachmentPrivacyLatch::MARKER, "enabled\n");
        app()->instance(AttachmentPrivacyLatch::class, new AttachmentPrivacyLatch($this->policyDirectory));
        config(['svc.attachments.sharing_writes_enabled' => true, 'svc.attachments.private_uploads_enabled' => false,
            'svc.filesystem_disk' => 'svc_files']);
        Storage::fake('svc_files');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->policyDirectory);
        parent::tearDown();
    }

    public function test_project_publisher_uploads_private_and_explicit_sharing_never_publishes_its_parent(): void
    {
        [$workspace, $company, $project, $owner, $publisher, $topic] = $this->fixture();
        $reference = new AttachmentParentReference('topic', $topic->public_id, $company->public_id);
        $action = app(AttachmentSharingAction::class);
        $prepared = $action->prepareUpload($publisher, $workspace, $reference, $this->upload(), AgentApiVersion::for($topic), AttachmentMutationOrigin::Agent);
        $this->assertFalse($prepared->is_visible_to_client);
        $this->assertSame(ClientAttachment::STATE_STAGED, $prepared->lifecycle_state);
        $this->assertRefused(409, fn () => $action->publishPrepared($owner, $workspace, $reference, $prepared,
            AgentApiVersion::for($topic), AttachmentMutationOrigin::Native));
        $file = $action->publishPrepared($publisher, $workspace, $reference, $prepared, AgentApiVersion::for($topic), AttachmentMutationOrigin::Agent);
        $this->assertSame(ClientAttachment::STATE_AVAILABLE, $file->lifecycle_state);
        $this->assertFalse($file->is_visible_to_client);
        $identity = $file->getAttributes();
        $parentVersion = AgentApiVersion::for($topic);
        $shared = $action->setVisibility($publisher, $workspace, $file->public_id, $this->change($file, $topic, true), AttachmentMutationOrigin::Agent, $reference);
        $this->assertTrue($shared->is_visible_to_client);
        $this->assertSame($file->lock_version + 1, $shared->lock_version);
        foreach (['record_type', 'record_public_id', 'object_key', 'original_filename', 'media_type', 'bytes', 'sha256', 'uploader_id'] as $key) {
            $this->assertSame($identity[$key], $shared->getRawOriginal($key));
        }
        $this->assertSame($parentVersion, AgentApiVersion::for($topic->fresh()));
        $this->assertFalse($project->fresh()->is_visible_to_client);
        $this->assertFalse($topic->fresh()->is_visible_to_client);
        $portal = $this->syntheticUser('sharing-portal');
        $company->portalUsers()->attach($portal, ['role' => 'client']);
        $reader = app(AttachmentReader::class);
        $this->assertFalse($reader->canView($portal, $workspace, $shared, AttachmentAudience::Portal));
        $project->update(['is_visible_to_client' => true]);
        $topic->update(['is_visible_to_client' => true]);
        $this->assertTrue($reader->canView($portal, $workspace, $shared, AttachmentAudience::Portal));
        $again = $action->setVisibility($publisher, $workspace, $shared->public_id, $this->change($shared, $topic->fresh(), true), AttachmentMutationOrigin::Agent, $reference);
        $this->assertSame($shared->lock_version + 1, $again->lock_version, 'A new accepted key advances exactly once even for the same stored choice.');
        $this->assertSame($again->lock_version, $action->assertReplayAllowed($publisher, $workspace, $reference, $again->public_id, AttachmentMutationOrigin::Agent)->lock_version);
        Storage::disk('svc_files')->assertExists($again->object_key);
        $this->assertDatabaseCount('client_attachments', 1);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $deleted = $action->delete($publisher, $workspace, $reference, $again->public_id,
            AttachmentDeletion::fromInput(['confirm' => true, 'expected_version' => AgentApiVersion::for($again),
                'expected_parent_version' => AgentApiVersion::for($topic->fresh())]), AttachmentMutationOrigin::Agent);
        $this->assertSame(ClientAttachment::STATE_DELETING, $deleted->lifecycle_state);
        $this->assertTrue($deleted->is_visible_to_client, 'Lifecycle cleanup preserves the stored sharing choice.');
        $this->assertSame($deleted->lock_version, $action->assertReplayAllowed($publisher, $workspace, $reference,
            $deleted->public_id, AttachmentMutationOrigin::Agent, deletion: true)->lock_version);
        $this->travel(8)->days();
        $this->assertSame(1, app(AttachmentStorageService::class)->repair(true)['purged_rows']);
        Storage::disk('svc_files')->assertMissing($deleted->object_key);
        $purged = $deleted->fresh();
        $this->assertSame(ClientAttachment::STATE_DELETED, $purged->lifecycle_state);
        $this->assertSame($purged->lock_version, $action->assertReplayAllowed($publisher, $workspace, $reference,
            $purged->public_id, AttachmentMutationOrigin::Agent, deletion: true)->lock_version);
        $this->assertTrue($purged->fresh()->is_visible_to_client);
    }

    public function test_stale_file_parent_and_repository_snapshots_cannot_overwrite_later_visibility(): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $file = app(AttachmentStorageService::class)->store($workspace, $company, $this->upload(), $owner);
        $reference = new AttachmentParentReference('company', $company->public_id);
        $action = app(AttachmentSharingAction::class);
        $change = $this->change($file, $company, true);
        $shared = $action->setVisibility($owner, $workspace, $file->public_id, $change, AttachmentMutationOrigin::Native, $reference);
        $this->assertRefused(409, fn () => $action->setVisibility($owner, $workspace, $file->public_id, $change, AttachmentMutationOrigin::Native, $reference));
        $company->update(['name' => 'Synthetic changed sharing company']);
        $this->assertRefused(409, fn () => $action->setVisibility($owner, $workspace, $file->public_id,
            new AttachmentVisibilityChange(false, AgentApiVersion::for($shared), $change->expectedParentVersion), AttachmentMutationOrigin::Native, $reference));
        $this->assertRefused(409, fn () => app(AttachmentSharingRepository::class)->setVisibility($workspace, $file, $reference, false));
        $this->assertTrue($shared->fresh()->is_visible_to_client);
        $this->expectException(\LogicException::class);
        $shared->is_visible_to_client = false;
        $shared->save();
    }

    public function test_legacy_manager_and_dual_native_policy_remain_distinct_from_scoped_agent_publication(): void
    {
        [$workspace, $company, , $owner, $publisher, $topic] = $this->fixture();
        $file = app(AttachmentStorageService::class)->store($workspace, $company, $this->upload(), $owner);
        $legacy = new AttachmentParentReference('company', $company->public_id);
        $action = app(AttachmentSharingAction::class);
        $this->assertRefused(403, fn () => $action->setVisibility($publisher, $workspace, $file->public_id, $this->change($file, $company, true), AttachmentMutationOrigin::Native, $legacy));
        $company->portalUsers()->attach($owner, ['role' => 'client']);
        $this->assertRefused(403, fn () => $action->setVisibility($owner, $workspace, $file->public_id, $this->change($file, $company, true), AttachmentMutationOrigin::Agent, $legacy));
        $shared = $action->setVisibility($owner, $workspace, $file->public_id, $this->change($file, $company, true), AttachmentMutationOrigin::Native, $legacy);
        $this->assertTrue($shared->is_visible_to_client);
        $reference = new AttachmentParentReference('topic', $topic->public_id, $company->public_id);
        $this->assertRefused(403, fn () => $action->prepareUpload($owner, $workspace, $reference, $this->upload(), AgentApiVersion::for($topic), AttachmentMutationOrigin::Agent));
        $this->assertSame(['company', 'expense', 'project', 'task', 'proposal', 'agreement', 'invoice'], AttachmentRecordResolver::uploadableTypes());
        $expense = $this->recordSyntheticExpense($workspace, $company, recordedBy: $owner);
        $receipt = app(AttachmentStorageService::class)->store($workspace, $expense, $this->upload(), $owner);
        $this->assertRefused(422, fn () => $action->setVisibility($owner, $workspace, $receipt->public_id,
            $this->change($receipt, $expense, true), AttachmentMutationOrigin::Native, new AttachmentParentReference('expense', $expense->public_id)));
        $this->assertFalse($receipt->fresh()->is_visible_to_client);
    }

    public function test_comment_authorship_or_contributor_status_does_not_grant_file_publishing(): void
    {
        [$workspace, $company, $project, , $publisher, $topic] = $this->fixture();
        $contributor = $this->syntheticMember($workspace, 'sharing-contributor', 'member');
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $contributor->id, 'role' => 'contributor']);
        $comment = ClientComment::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'client_project_id' => $project->id, 'client_collaboration_topic_id' => $topic->id, 'body' => 'Synthetic authored comment',
            'author_user_id' => $contributor->id, 'author_name' => $contributor->name]);
        $reference = new AttachmentParentReference('comment', $comment->public_id, $company->public_id);
        $action = app(AttachmentSharingAction::class);
        $this->assertRefused(403, fn () => $action->prepareUpload($contributor, $workspace, $reference, $this->upload(), AgentApiVersion::for($comment), AttachmentMutationOrigin::Native));
        $this->assertDatabaseCount('client_attachments', 0);
        $prepared = $action->prepareUpload($publisher, $workspace, $reference, $this->upload(), AgentApiVersion::for($comment), AttachmentMutationOrigin::Agent);
        $file = $action->publishPrepared($publisher, $workspace, $reference, $prepared, AgentApiVersion::for($comment), AttachmentMutationOrigin::Agent);
        $this->assertFalse($file->is_visible_to_client);
        $topic->forceFill(['deleted_at' => now()])->save();
        $this->assertRefused(404, fn () => $action->assertReplayAllowed($publisher, $workspace, $reference, $file->public_id, AttachmentMutationOrigin::Agent));
        $this->assertRefused(404, fn () => $action->setVisibility($publisher, $workspace, $file->public_id, $this->change($file, $comment, true), AttachmentMutationOrigin::Native, $reference));
    }

    public function test_disabled_controls_and_revoked_or_cross_company_uploads_leave_no_available_file_or_bytes(): void
    {
        [$workspace, $company, $project, $owner, $publisher, $topic] = $this->fixture();
        $reference = new AttachmentParentReference('topic', $topic->public_id, $company->public_id);
        $action = app(AttachmentSharingAction::class);
        config(['svc.attachments.sharing_writes_enabled' => false]);
        $this->assertRefused(404, fn () => $action->prepareUpload($publisher, $workspace, $reference, $this->upload(), AgentApiVersion::for($topic), AttachmentMutationOrigin::Agent));
        // Turning controls off does not stop latch-aware ordinary upload service.
        $legacy = app(AttachmentStorageService::class)->store($workspace, $company, $this->upload(), $owner);
        $this->assertFalse($legacy->is_visible_to_client);
        config(['svc.attachments.sharing_writes_enabled' => true]);
        File::delete($this->policyDirectory.'/'.AttachmentPrivacyLatch::MARKER);
        $this->assertRefused(503, fn () => $action->prepareUpload($publisher, $workspace, $reference, $this->upload(), AgentApiVersion::for($topic), AttachmentMutationOrigin::Agent));
        File::put($this->policyDirectory.'/'.AttachmentPrivacyLatch::MARKER, "enabled\n");
        $other = $this->syntheticCompany($workspace, 'sharing-other');
        $this->assertRefused(404, fn () => $action->prepareUpload($owner, $workspace,
            new AttachmentParentReference('topic', $topic->public_id, $other->public_id), $this->upload(), AgentApiVersion::for($topic), AttachmentMutationOrigin::Native));
        $this->assertRefused(422, fn () => app(AttachmentStorageService::class)->store($workspace, $topic, $this->upload(), $publisher));
        $prepared = $action->prepareUpload($publisher, $workspace, $reference, $this->upload(), AgentApiVersion::for($topic), AttachmentMutationOrigin::Agent);
        ClientProjectMembership::query()->where('workspace_id', $workspace->id)->where('client_project_id', $project->id)->where('user_id', $publisher->id)->update(['role' => 'viewer']);
        $this->assertRefused(403, fn () => $action->publishPrepared($publisher, $workspace, $reference, $prepared, AgentApiVersion::for($topic), AttachmentMutationOrigin::Agent));
        $this->assertSame(ClientAttachment::STATE_STAGED, $prepared->fresh()->lifecycle_state);
        app(AttachmentStorageService::class)->discardPreparedUpload($workspace, $prepared);
        $this->assertDatabaseCount('client_attachments', 1);
        $this->assertCount(1, Storage::disk('svc_files')->allFiles());
    }

    /** @return iterable<string,array{string}> */
    public static function parentLockCases(): iterable
    {
        yield 'topic' => ['topic'];
        yield 'topic comment' => ['topic_comment'];
        yield 'task comment' => ['task_comment'];
    }

    #[DataProvider('parentLockCases')]
    public function test_publication_locks_the_parent_chain_and_current_access_before_the_file(string $kind): void
    {
        [$workspace, $company, $project, , $publisher, $topic] = $this->fixture();
        $parent = $topic;
        $domainLocks = [LockResource::ClientCollaborationTopic];
        if ($kind !== 'topic') {
            $task = $kind === 'task_comment' ? ClientTask::query()->create(['workspace_id' => $workspace->id,
                'client_project_id' => $project->id, 'title' => 'Synthetic file lock task']) : null;
            $parent = ClientComment::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'client_project_id' => $project->id, 'client_task_id' => $task?->id, 'client_collaboration_topic_id' => $task === null ? $topic->id : null,
                'body' => 'Synthetic file lock comment', 'author_user_id' => $publisher->id, 'author_name' => $publisher->name]);
            $domainLocks = [$task === null ? LockResource::ClientCollaborationTopic : LockResource::ClientTask, LockResource::ClientComment];
        }
        $reference = new AttachmentParentReference($parent instanceof ClientComment ? 'comment' : 'topic', $parent->public_id, $company->public_id);
        $action = app(AttachmentSharingAction::class);
        $version = AgentApiVersion::for($parent);
        $prepared = $action->prepareUpload($publisher, $workspace, $reference, $this->upload(), $version, AttachmentMutationOrigin::Native);
        $file = $action->publishPrepared($publisher, $workspace, $reference, $prepared, $version, AttachmentMutationOrigin::Native);
        LockOrderRecorder::start();
        try {
            $action->setVisibility($publisher, $workspace, $file->public_id, $this->change($file, $parent, true), AttachmentMutationOrigin::Native, $reference);
            $this->assertSame([[LockResource::BillingMaintenance, LockResource::Workspace, ...$domainLocks,
                LockResource::ClientProject, LockResource::WorkspaceMembership, LockResource::ClientProjectMembership,
                LockResource::ClientCompanyMembership, LockResource::ClientAttachment, LockResource::ClientAttachment]], LockOrderRecorder::sequences());
        } finally {
            LockOrderRecorder::stop();
        }
    }

    /** @return array{Workspace,ClientCompany,ClientProject,User,User,ClientCollaborationTopic} */
    private function fixture(): array
    {
        $workspace = $this->syntheticWorkspace('sharing');
        $company = $this->syntheticCompany($workspace, 'sharing');
        $project = $this->syntheticProject($company, 'sharing');
        $project->update(['is_visible_to_client' => false]);
        $owner = $this->syntheticMember($workspace, 'sharing-owner', 'owner');
        $publisher = $this->syntheticMember($workspace, 'sharing-publisher', 'member');
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $publisher->id, 'role' => 'manager']);
        $topic = app(CollaborationAction::class)->createTopic($owner, $workspace, $company,
            TopicDraft::fromInput(['kind' => 'note', 'title' => 'Synthetic sharing topic', 'body' => 'Synthetic private topic body', 'project_id' => $project->public_id]), CollaborationAudience::Internal);

        return [$workspace, $company, $project, $owner, $publisher, $topic];
    }

    private function upload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('synthetic-sharing.txt', 'Synthetic private bytes');
    }

    private function change(ClientAttachment $file, Model $parent, bool $visible): AttachmentVisibilityChange
    {
        return new AttachmentVisibilityChange($visible, AgentApiVersion::for($file), AgentApiVersion::for($parent));
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
