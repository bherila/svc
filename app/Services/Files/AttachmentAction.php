<?php

namespace App\Services\Files;

use App\Contracts\WorkspaceOwned;
use App\Models\ClientAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingMaintenance;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\Locks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Shared file lifecycle for web forms and agent mutations. */
final class AttachmentAction
{
    public function __construct(private readonly AttachmentRecordResolver $records, private readonly AttachmentStorageService $storage, private readonly AttachmentWriteAccess $access) {}

    public function store(Workspace $workspace, string $type, string $id, UploadedFile $file, User $user, ?string $expectedVersion = null): ClientAttachment
    {
        abort_unless(in_array($type, AttachmentRecordResolver::uploadableTypes(), true), 422);
        if ($expectedVersion === null) {
            return $this->storage->store($workspace, $this->records->resolve($workspace, $type, $id), $file, $user);
        }
        $prepared = $this->storage->prepareForPublication($workspace, $this->records->resolve($workspace, $type, $id), $file, $user);
        try {
            return $this->publishPrepared($workspace, $type, $id, $prepared, $expectedVersion);
        } catch (\Throwable $exception) {
            $this->storage->discardPreparedUpload($workspace, $prepared);
            throw $exception;
        }
    }

    /** Publish preverified bytes under the current tenant-owned parent revision. */
    public function publishPrepared(Workspace $workspace, string $type, string $id, ClientAttachment $prepared, string $expectedVersion, ?User $agentActor = null, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): ClientAttachment
    {
        abort_unless(in_array($type, AttachmentRecordResolver::uploadableTypes(), true), 422);

        return DB::transaction(function () use ($workspace, $type, $id, $prepared, $expectedVersion, $agentActor, $origin): ClientAttachment {
            BillingMaintenance::share($workspace->id);
            $record = $agentActor === null ? $this->lockedRecord($workspace, $type, $id) : $this->lockAgentParent($workspace, $type, $id, $agentActor, $origin);
            abort_unless(AgentApiVersion::matches($record, $expectedVersion), 409, 'The record changed. Read it again before uploading.');

            return $this->storage->publishPrepared($workspace, $record, $prepared);
        });
    }

    public function delete(Workspace $workspace, string $id, ?string $expectedVersion = null, ?User $agentActor = null, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): ClientAttachment
    {
        return DB::transaction(function () use ($workspace, $id, $expectedVersion, $agentActor, $origin): ClientAttachment {
            BillingMaintenance::share($workspace->id);
            if ($agentActor !== null) {
                $identity = ClientAttachment::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->firstOrFail();
                $this->lockAgentParent($workspace, $identity->record_type, $identity->record_public_id, $agentActor, $origin);
            }
            $attachment = ClientAttachment::query()->where('workspace_id', $workspace->id)->where('public_id', $id)
                ->tap(Locks::forUpdate())->firstOrFail();
            if ($attachment->record_type === 'expense') {
                $this->records->resolve($workspace, 'expense', $attachment->record_public_id);
            }
            if ($expectedVersion !== null) {
                abort_unless(AgentApiVersion::matches($attachment, $expectedVersion), 409, 'The attachment changed. Read it again before deleting.');
            }
            $attachment->setRelation('workspace', $workspace);

            return $this->storage->requestDeletion($attachment);
        });
    }

    /** Caller holds its mutation receipt. Publication and replay acquire the same current authority.
     * @return Model&WorkspaceOwned
     */
    public function lockAgentParent(Workspace $workspace, string $type, string $id, User $actor, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): Model
    {
        $early = in_array($type, ['proposal', 'agreement', 'invoice'], true);
        if (! $early) {
            $this->access->lockWorkspace($workspace);
        }
        $record = $this->lockedRecord($workspace, $type, $id);
        if ($early) {
            $this->access->lockWorkspace($workspace);
        }
        $this->access->assertCurrentManager($actor, $workspace, $origin);

        return $record;
    }

    /** @return Model&WorkspaceOwned */
    private function lockedRecord(Workspace $workspace, string $type, string $id): Model
    {
        $record = $this->records->resolve($workspace, $type, $id);
        /** @var Model&WorkspaceOwned $locked */
        $locked = $record->newQuery()->where('workspace_id', $workspace->id)->where('public_id', $id)
            ->tap(Locks::forUpdate())->firstOrFail();

        return $locked;
    }
}
