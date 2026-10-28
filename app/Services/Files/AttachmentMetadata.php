<?php

namespace App\Services\Files;

use App\Models\ClientAttachment;
use App\Support\AgentApi\AgentApiVersion;

/** The stable legacy scalar contract, extended explicitly by new file surfaces. */
final class AttachmentMetadata
{
    /** @return array{id:string,record_type:string,record_id:string,filename:string,media_type:string,bytes:int,sha256:string,status:string,version:string} */
    public static function for(ClientAttachment $attachment): array
    {
        return ['id' => $attachment->public_id, 'record_type' => $attachment->record_type,
            'record_id' => $attachment->record_public_id, 'filename' => $attachment->original_filename,
            'media_type' => $attachment->media_type, 'bytes' => $attachment->bytes, 'sha256' => $attachment->sha256,
            'status' => $attachment->lifecycle_state, 'version' => AgentApiVersion::for($attachment)];
    }

    /** Finished, audience-authorized capabilities are supplied by the caller.
     * @param array<string,mixed>|null $sharing
     * @return array<string,mixed> */
    public static function native(ClientAttachment $file, string $downloadHref, ?string $deleteHref, ?array $sharing): array
    {
        return [...self::for($file), 'id' => $file->public_id, 'filename' => $file->original_filename, 'media_type' => $file->media_type, 'bytes' => $file->bytes,
            'uploaded_at' => $file->created_at?->toIso8601String(), 'download_href' => $downloadHref,
            'delete_href' => $deleteHref, 'sharing' => $sharing,
            'deletion' => $deleteHref === null ? null : ['expected_version' => AgentApiVersion::for($file),
                ...(in_array($file->record_type, ['topic', 'comment'], true) && $sharing !== null ? ['expected_parent_version' => $sharing['parent_version']] : [])]];
    }
}
