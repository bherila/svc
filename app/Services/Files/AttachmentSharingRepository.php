<?php

namespace App\Services\Files;

use App\Models\ClientAttachment;
use App\Models\Workspace;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;

/** The sole mutable flag boundary; every query names tenant, identity and parent. */
final class AttachmentSharingRepository
{
    public function __construct(private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    /** @param list<string> $states */
    public function lockFile(Workspace $workspace, string $id, AttachmentParentReference $parent, array $states = [ClientAttachment::STATE_AVAILABLE]): ClientAttachment
    {
        return ClientAttachment::query()->where('workspace_id', $workspace->id)->where('public_id', $id)
            ->where('record_type', $parent->type)->where('record_public_id', $parent->publicId)
            ->whereIn('lifecycle_state', $states)->tap(Locks::forUpdate())->firstOrFail();
    }

    public function setVisibility(Workspace $workspace, ClientAttachment $file, AttachmentParentReference $parent, bool $visible): ClientAttachment
    {
        abort_unless($file->exists && $file->workspace_id === $workspace->id && $file->record_type === $parent->type
            && $file->record_public_id === $parent->publicId && $file->lifecycle_state === ClientAttachment::STATE_AVAILABLE, 404);
        $changed = ClientAttachment::query()->where('workspace_id', $workspace->id)->whereKey($file->id)
            ->where('public_id', $file->public_id)->where('record_type', $parent->type)->where('record_public_id', $parent->publicId)
            ->where('lifecycle_state', ClientAttachment::STATE_AVAILABLE)->where('lock_version', $file->lock_version)
            ->update(['is_visible_to_client' => $visible, 'lock_version' => $file->lock_version + 1,
                'updated_at' => $this->clock->now($workspace)->utc()]);
        abort_unless($changed === 1, 409, 'The attachment changed. Read it again before sharing.');

        return $this->lockFile($workspace, $file->public_id, $parent);
    }
}
