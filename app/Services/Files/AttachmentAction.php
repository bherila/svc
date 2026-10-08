<?php

namespace App\Services\Files;

use App\Contracts\WorkspaceOwned;
use App\Models\ClientAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\Locks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Shared file lifecycle for web forms and agent mutations. */
final class AttachmentAction
{
    public function __construct(private readonly AttachmentRecordResolver $records, private readonly AttachmentStorageService $storage) {}

    public function store(Workspace $workspace, string $type, string $id, UploadedFile $file, User $user, ?string $expectedVersion = null): ClientAttachment
    {
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
    public function publishPrepared(Workspace $workspace, string $type, string $id, ClientAttachment $prepared, string $expectedVersion): ClientAttachment
    {
        return DB::transaction(function () use ($workspace, $type, $id, $prepared, $expectedVersion): ClientAttachment {
            $record = $this->lockedRecord($workspace, $type, $id);
            abort_unless(AgentApiVersion::matches($record, $expectedVersion), 409, 'The record changed. Read it again before uploading.');

            return $this->storage->publishPrepared($workspace, $record, $prepared);
        });
    }

    public function delete(Workspace $workspace, string $id, ?string $expectedVersion = null): ClientAttachment
    {
        return DB::transaction(function () use ($workspace, $id, $expectedVersion): ClientAttachment {
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
