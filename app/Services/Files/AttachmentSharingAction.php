<?php

namespace App\Services\Files;

use App\Contracts\WorkspaceOwned;
use App\Models\ClientAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingMaintenance;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Domain boundary shared by future native/REST/MCP/CLI receipt adapters. */
final class AttachmentSharingAction
{
    public function __construct(private readonly AttachmentPublicationAuthority $authority, private readonly AttachmentSharingRepository $files,
        private readonly AttachmentStorageService $storage) {}

    public function setVisibility(User $actor, Workspace $workspace, string $attachmentId, AttachmentVisibilityChange $change,
        AttachmentMutationOrigin $origin, AttachmentParentReference $parentScope): ClientAttachment
    {
        AttachmentSharingGate::assertEnabled();

        return DB::transaction(function () use ($actor, $workspace, $attachmentId, $change, $origin, $parentScope): ClientAttachment {
            BillingMaintenance::share($workspace->id);
            $parent = $this->authority->lock($actor, $workspace, $parentScope, $origin);
            abort_unless(AgentApiVersion::matches($parent, $change->expectedParentVersion), 409, 'The parent changed. Read it again before sharing.');
            $file = $this->files->lockFile($workspace, $attachmentId, $parentScope);
            abort_unless(AgentApiVersion::matches($file, $change->expectedVersion), 409, 'The attachment changed. Read it again before sharing.');

            return $this->files->setVisibility($workspace, $file, $parentScope, $change->visibleToClient);
        });
    }

    /** Private bytes are staged only after the current parent publisher/version preflight. */
    public function prepareUpload(User $actor, Workspace $workspace, AttachmentParentReference $parentScope, UploadedFile $file,
        string $expectedParentVersion, AttachmentMutationOrigin $origin): ClientAttachment
    {
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:51200']])->validate();
        $parent = $this->uploadPreflight($actor, $workspace, $parentScope, $origin, $expectedParentVersion);

        return $this->storage->prepareForPublication($workspace, $parent, $file, $actor);
    }

    /** Read-only URL/CLI preview validation; never stages bytes or creates a receipt.
     * @return Model&WorkspaceOwned */
    public function uploadPreflight(User $actor, Workspace $workspace, AttachmentParentReference $parentScope,
        AttachmentMutationOrigin $origin, ?string $expectedParentVersion = null): Model
    {
        AttachmentSharingGate::assertEnabled();
        abort_unless($parentScope->collaboration(), 422);

        return DB::transaction(function () use ($actor, $workspace, $parentScope, $expectedParentVersion, $origin) {
            BillingMaintenance::share($workspace->id);
            $parent = $this->authority->lock($actor, $workspace, $parentScope, $origin);
            abort_unless($expectedParentVersion === null || AgentApiVersion::matches($parent, $expectedParentVersion), 409, 'The parent changed. Read it again before uploading.');

            return $parent;
        });
    }

    public function publishPrepared(User $actor, Workspace $workspace, AttachmentParentReference $parentScope, ClientAttachment $prepared,
        string $expectedParentVersion, AttachmentMutationOrigin $origin): ClientAttachment
    {
        AttachmentSharingGate::assertEnabled();
        abort_unless($parentScope->collaboration(), 422);

        return DB::transaction(function () use ($actor, $workspace, $parentScope, $prepared, $expectedParentVersion, $origin): ClientAttachment {
            BillingMaintenance::share($workspace->id);
            $parent = $this->authority->lock($actor, $workspace, $parentScope, $origin);
            abort_unless(AgentApiVersion::matches($parent, $expectedParentVersion), 409, 'The parent changed. Read it again before uploading.');
            $file = $this->files->lockFile($workspace, $prepared->public_id, $parentScope, [ClientAttachment::STATE_STAGED]);
            abort_unless($file->id === $prepared->id && $file->uploader_id === $actor->id && ! $file->is_visible_to_client, 409);

            return $this->storage->publishPrepared($workspace, $parent, $file);
        });
    }

    public function delete(User $actor, Workspace $workspace, AttachmentParentReference $parentScope, string $attachmentId,
        AttachmentDeletion $deletion, AttachmentMutationOrigin $origin): ClientAttachment
    {
        AttachmentSharingGate::assertEnabled();
        abort_unless($parentScope->collaboration(), 422);

        return DB::transaction(function () use ($actor, $workspace, $parentScope, $attachmentId, $deletion, $origin): ClientAttachment {
            BillingMaintenance::share($workspace->id);
            $parent = $this->authority->lock($actor, $workspace, $parentScope, $origin);
            abort_unless(AgentApiVersion::matches($parent, $deletion->expectedParentVersion), 409, 'The parent changed. Read it again before deleting.');
            $file = $this->files->lockFile($workspace, $attachmentId, $parentScope);
            abort_unless(AgentApiVersion::matches($file, $deletion->expectedVersion), 409, 'The attachment changed. Read it again before deleting.');
            $file->setRelation('workspace', $workspace);

            return $this->storage->requestDeletion($file);
        });
    }

    /** A caller holds its receipt; original versions never substitute for current authority. */
    public function assertReplayAllowed(User $actor, Workspace $workspace, AttachmentParentReference $parentScope,
        string $attachmentId, AttachmentMutationOrigin $origin, bool $deletion = false): ClientAttachment
    {
        AttachmentSharingGate::assertEnabled();

        return DB::transaction(function () use ($actor, $workspace, $parentScope, $attachmentId, $origin, $deletion): ClientAttachment {
            BillingMaintenance::share($workspace->id);
            $this->authority->lock($actor, $workspace, $parentScope, $origin);

            return $this->files->lockFile($workspace, $attachmentId, $parentScope,
                $deletion ? [ClientAttachment::STATE_DELETING, ClientAttachment::STATE_DELETED] : [ClientAttachment::STATE_AVAILABLE]);
        });
    }

    /** CLI preview validates the same locked authority/CAS without mutation or receipt/audit. */
    public function mutationPreflight(User $actor, Workspace $workspace, AttachmentParentReference $parentScope,
        string $attachmentId, string $expectedVersion, string $expectedParentVersion): ClientAttachment
    {
        AttachmentSharingGate::assertEnabled();

        return DB::transaction(function () use ($actor, $workspace, $parentScope, $attachmentId, $expectedVersion, $expectedParentVersion): ClientAttachment {
            BillingMaintenance::share($workspace->id);
            $parent = $this->authority->lock($actor, $workspace, $parentScope, AttachmentMutationOrigin::Native);
            abort_unless(AgentApiVersion::matches($parent, $expectedParentVersion), 409, 'The parent changed. Read it again before saving.');
            $file = $this->files->lockFile($workspace, $attachmentId, $parentScope);
            abort_unless(AgentApiVersion::matches($file, $expectedVersion), 409, 'The attachment changed. Read it again before saving.');

            return $file;
        });
    }
}
