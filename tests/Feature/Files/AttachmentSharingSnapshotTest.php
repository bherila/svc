<?php

namespace Tests\Feature\Files;

use App\Models\ClientAttachment;
use App\Models\ClientCollaborationTopic;
use App\Models\ClientComment;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProjectMembership;
use App\Services\Files\AttachmentDeletion;
use App\Services\Files\AttachmentMutationOrigin;
use App\Services\Files\AttachmentParentReference;
use App\Services\Files\AttachmentPrivacyLatch;
use App\Services\Files\AttachmentSharingAction;
use App\Services\Files\AttachmentVisibilityChange;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class AttachmentSharingSnapshotTest extends TestCase
{
    use BuildsSyntheticExpenses, UsesAProbeDatabase;

    /** @return iterable<string, array{string,string}> */
    public static function currentAuthorityCases(): iterable
    {
        foreach (['publish', 'sharing', 'replay', 'delete', 'delete_replay'] as $operation) {
            yield $operation.' after new-company Portal grant' => [$operation, 'portal'];
            yield $operation.' after staff demotion' => [$operation, 'demotion'];
        }
        yield 'publish after topic ancestor tombstone' => ['publish', 'ancestor_deleted'];
        yield 'publish after comment tombstone' => ['publish', 'comment_deleted'];
        yield 'sharing after topic ancestor tombstone' => ['sharing', 'ancestor_deleted'];
        yield 'replay after topic ancestor tombstone' => ['replay', 'ancestor_deleted'];
        yield 'publish after project manager grant revocation' => ['publish', 'grant_revoked'];
        yield 'replay after project manager grant revocation' => ['replay', 'grant_revoked'];
    }

    /** @param 'publish'|'sharing'|'replay'|'delete'|'delete_replay' $operation */
    #[DataProvider('currentAuthorityCases')]
    public function test_new_current_authority_overrides_real_old_repeatable_read_snapshot(string $operation, string $change): void
    {
        if (DB::getDriverName() !== 'mariadb') {
            $this->markTestSkipped('A genuine MariaDB repeatable-read snapshot is required.');
        }
        $original = DB::getDefaultConnection();
        $this->bootProbeDatabase('sharing_rr_probe');
        Artisan::call('migrate', ['--database' => 'sharing_rr_probe', '--force' => true]);
        DB::setDefaultConnection('sharing_rr_probe');
        Schema::clearResolvedInstance('db.schema');
        config(['database.connections.sharing_rr_writer' => config('database.connections.sharing_rr_probe')]);
        DB::purge('sharing_rr_writer');
        $directory = sys_get_temp_dir().'/svc-sharing-rr-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory, 0700);
        File::put($directory.'/'.AttachmentPrivacyLatch::MARKER, "enabled\n");
        app()->instance(AttachmentPrivacyLatch::class, new AttachmentPrivacyLatch($directory));
        config(['svc.attachments.sharing_writes_enabled' => true, 'svc.filesystem_disk' => 'svc_files']);
        Storage::fake('svc_files');
        try {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::statement('SET SESSION innodb_snapshot_isolation=OFF');
            $workspace = $this->syntheticWorkspace('sharing-rr');
            $owner = $this->syntheticMember($workspace, 'sharing-rr-owner', 'owner');
            $company = $this->syntheticCompany($workspace, 'sharing-rr');
            $project = $this->syntheticProject($company, 'sharing-rr');
            $staffRole = $change === 'grant_revoked' ? 'member' : 'owner';
            if ($change === 'grant_revoked') {
                $workspace->memberships()->where('user_id', $owner->id)->update(['role' => $staffRole]);
                ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $owner->id, 'role' => 'manager']);
            }
            $topic = ClientCollaborationTopic::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'client_project_id' => $project->id, 'kind' => 'note', 'title' => 'Synthetic RR topic', 'body' => 'Synthetic RR body',
                'author_user_id' => $owner->id, 'author_name' => $owner->name]);
            $parent = $topic;
            if (in_array($change, ['ancestor_deleted', 'comment_deleted'], true)) {
                $parent = ClientComment::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                    'client_project_id' => $project->id, 'client_collaboration_topic_id' => $topic->id,
                    'body' => 'Synthetic RR comment', 'author_user_id' => $owner->id, 'author_name' => $owner->name]);
            }
            $reference = new AttachmentParentReference($parent instanceof ClientComment ? 'comment' : 'topic', $parent->public_id, $company->public_id);
            $action = app(AttachmentSharingAction::class);
            $version = AgentApiVersion::for($parent);
            $file = $action->prepareUpload($owner, $workspace, $reference,
                UploadedFile::fake()->createWithContent('synthetic-rr.txt', 'Synthetic RR private bytes'), $version, AttachmentMutationOrigin::Agent);
            if ($operation !== 'publish') {
                $file = $action->publishPrepared($owner, $workspace, $reference, $file, $version, AttachmentMutationOrigin::Agent);
            }
            if ($operation === 'delete_replay') {
                $file = $action->delete($owner, $workspace, $reference, $file->public_id,
                    AttachmentDeletion::fromInput(['confirm' => true, 'expected_version' => AgentApiVersion::for($file), 'expected_parent_version' => $version]), AttachmentMutationOrigin::Agent);
            }
            DB::transaction(function () use ($operation, $change, $workspace, $owner, $topic, $parent, $reference, $action, $version, $file, $staffRole, $project): void {
                $this->assertSame(0, ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->count());
                $this->assertSame($staffRole, $workspace->memberships()->where('user_id', $owner->id)->value('role'));
                if ($change === 'grant_revoked') {
                    $this->assertSame('manager', DB::table('client_project_memberships')->where('workspace_id', $workspace->id)
                        ->where('client_project_id', $project->id)->where('user_id', $owner->id)->value('role'));
                }
                $writer = DB::connection('sharing_rr_writer');
                $writer->transaction(function () use ($writer, $change, $workspace, $owner, $topic, $parent, $project): void {
                    if ($change === 'grant_revoked') {
                        $writer->table('client_project_memberships')->where('workspace_id', $workspace->id)
                            ->where('client_project_id', $project->id)->where('user_id', $owner->id)->delete();

                        return;
                    }
                    if (in_array($change, ['ancestor_deleted', 'comment_deleted'], true)) {
                        $target = $change === 'ancestor_deleted' ? $topic : $parent;
                        $writer->table($target->getTable())->where('workspace_id', $workspace->id)->where('id', $target->id)
                            ->update(['deleted_at' => now(), 'lock_version' => $target->lock_version + 1]);

                        return;
                    }
                    if ($change === 'demotion') {
                        $writer->table('workspace_memberships')->where('workspace_id', $workspace->id)->where('user_id', $owner->id)->update(['role' => 'member']);

                        return;
                    }
                    $newId = $writer->table('client_companies')->insertGetId(['workspace_id' => $workspace->id, 'public_id' => (string) Str::uuid(),
                        'name' => 'Synthetic newly committed company', 'slug' => 'synthetic-new-'.Str::lower(Str::random(10))]);
                    $writer->table('client_company_memberships')->insert(['workspace_id' => $workspace->id, 'client_company_id' => $newId,
                        'public_id' => (string) Str::uuid(), 'user_id' => $owner->id, 'role' => 'client', 'access_scope' => 'company']);
                });
                $this->assertSame(0, ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->count(), 'Ordinary reads really retain the old snapshot.');
                $this->assertSame($staffRole, $workspace->memberships()->where('user_id', $owner->id)->value('role'));
                if ($change === 'grant_revoked') {
                    $this->assertSame('manager', DB::table('client_project_memberships')->where('workspace_id', $workspace->id)
                        ->where('client_project_id', $project->id)->where('user_id', $owner->id)->value('role'), 'The ordinary project grant read also retains its old snapshot.');
                }
                try {
                    match ($operation) {
                        'publish' => $action->publishPrepared($owner, $workspace, $reference, $file, $version, AttachmentMutationOrigin::Agent),
                        'sharing' => $action->setVisibility($owner, $workspace, $file->public_id,
                            new AttachmentVisibilityChange(true, AgentApiVersion::for($file), AgentApiVersion::for($parent)), AttachmentMutationOrigin::Agent, $reference),
                        'replay' => $action->assertReplayAllowed($owner, $workspace, $reference, $file->public_id, AttachmentMutationOrigin::Agent),
                        'delete' => $action->delete($owner, $workspace, $reference, $file->public_id,
                            AttachmentDeletion::fromInput(['confirm' => true, 'expected_version' => AgentApiVersion::for($file), 'expected_parent_version' => $version]), AttachmentMutationOrigin::Agent),
                        'delete_replay' => $action->assertReplayAllowed($owner, $workspace, $reference, $file->public_id, AttachmentMutationOrigin::Agent, deletion: true),
                    };
                    $this->fail('An old snapshot revived revoked attachment publication authority.');
                } catch (HttpExceptionInterface $exception) {
                    $this->assertSame(in_array($change, ['ancestor_deleted', 'comment_deleted'], true) ? 404 : 403, $exception->getStatusCode());
                }
            });
            $this->assertFalse($file->fresh()->is_visible_to_client);
            $this->assertSame(match ($operation) {
                'publish' => ClientAttachment::STATE_STAGED,
                'delete_replay' => ClientAttachment::STATE_DELETING,
                default => ClientAttachment::STATE_AVAILABLE,
            }, $file->fresh()->lifecycle_state);
            $this->assertSame($file->lock_version, $file->fresh()->lock_version);
            $this->assertDatabaseCount('agent_mutation_receipts', 0);
            $this->assertDatabaseCount('agent_mutation_audits', 0);
        } finally {
            DB::purge('sharing_rr_writer');
            File::deleteDirectory($directory);
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        }
    }
}
