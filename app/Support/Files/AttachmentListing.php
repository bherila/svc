<?php

namespace App\Support\Files;

use App\Models\ClientAttachment;
use App\Models\Workspace;

/**
 * The files stored against one record, ready for a screen to render.
 *
 * Shared between the operator's copy of a screen and the client's for the same
 * reason the terms payload is: two hand-built copies of the same list is how
 * one audience ends up offered a file the other cannot see, or the reverse.
 *
 * This lists; it does not authorize. The caller has already decided the reader
 * may see this record, and `AttachmentController` re-checks on the way to every
 * URL below - workspace membership for an operator, and for a portal user the
 * record's own visibility, lifecycle and project scope. So a listing that
 * slipped out would still not be a file that could be fetched.
 */
final class AttachmentListing
{
    /**
     * Available files only.
     *
     * A staged attachment has no verified object behind it yet and one marked
     * for deletion is on its way out. Offering either is offering a link that
     * resolves to a 404 the reader can do nothing about.
     *
     * @param  bool  $manages  whether to offer removal; downloading is not gated on it
     * @return list<array{id: string, filename: string, media_type: string, bytes: int, uploaded_at: string|null, download_href: string, delete_href: string|null}>
     */
    public static function for(Workspace $workspace, string $recordType, string $recordPublicId, bool $manages): array
    {
        $base = "/workspaces/{$workspace->public_id}/attachments";

        $attachments = ClientAttachment::query()
            ->where('workspace_id', $workspace->id)
            ->where('record_type', $recordType)
            ->where('record_public_id', $recordPublicId)
            ->where('lifecycle_state', ClientAttachment::STATE_AVAILABLE)
            ->orderByDesc('id')
            ->get();

        $listed = [];

        foreach ($attachments as $attachment) {
            $listed[] = [
                'id' => (string) $attachment->public_id,
                'filename' => (string) $attachment->original_filename,
                'media_type' => (string) $attachment->media_type,
                'bytes' => (int) $attachment->bytes,
                'uploaded_at' => $attachment->available_at?->toISOString(),
                'download_href' => "{$base}/{$attachment->public_id}",
                'delete_href' => $manages ? "{$base}/{$attachment->public_id}" : null,
            ];
        }

        return $listed;
    }
}
