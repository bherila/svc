<?php

namespace App\Support\Files;

use App\Models\ClientAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Files\AttachmentAudience;
use App\Services\Files\AttachmentFilePage;
use App\Services\Files\AttachmentParentReference;
use App\Services\Files\AttachmentReader;
use App\Services\Files\AttachmentSharingGate;
use App\Services\Files\AttachmentSharingPresenter;
use App\Services\Files\AttachmentSharingView;
use Illuminate\Database\Eloquent\Collection;

/** Authorized, bounded metadata; every held download URL rechecks the same policy. */
final class AttachmentListing
{
    /** @return list<array{id:string,filename:string,media_type:string,bytes:int,uploaded_at:string|null,download_href:string,delete_href:string|null,sharing:array<string,mixed>|null}> */
    public static function for(Workspace $workspace, string $recordType, string $recordPublicId, bool $manages, User $actor, AttachmentAudience $audience): array
    {
        return self::present($workspace, app(AttachmentReader::class)->forParent($actor, $workspace, $recordType, $recordPublicId, $audience)
            ->orderByDesc('id')->limit(100)->get(), self::sharing($actor, $workspace, $recordType, $recordPublicId, $manages, $audience), $manages && in_array($audience, [AttachmentAudience::Native, AttachmentAudience::Internal], true));
    }

    public static function page(Workspace $workspace, string $recordType, string $recordPublicId, bool $manages, User $actor, AttachmentAudience $audience, int $page, string $pageHref): AttachmentFilePage
    {
        abort_unless($page >= 1 && $page <= 1_000_000, 422);
        $rows = app(AttachmentReader::class)->forParent($actor, $workspace, $recordType, $recordPublicId, $audience)
            ->orderByDesc('id')->simplePaginate(100, ['*'], 'files_page', $page)->withPath($pageHref)->fragment('files');

        return new AttachmentFilePage(self::present($workspace, $rows->getCollection(), self::sharing($actor, $workspace, $recordType, $recordPublicId, $manages, $audience), $manages && in_array($audience, [AttachmentAudience::Native, AttachmentAudience::Internal], true)), $rows->nextPageUrl(), $rows->previousPageUrl());
    }

    private static function sharing(User $actor, Workspace $workspace, string $type, string $id, bool $manages, AttachmentAudience $audience): ?AttachmentSharingView
    {
        return ! $manages || ! in_array($audience, [AttachmentAudience::Native, AttachmentAudience::Internal], true)
            || $type === 'expense' || ! AttachmentSharingGate::available() ? null
            : app(AttachmentSharingPresenter::class)->view($actor, $workspace, new AttachmentParentReference($type, $id), $audience);
    }

    /** @param Collection<int, ClientAttachment> $attachments
     * @return list<array{id:string,filename:string,media_type:string,bytes:int,uploaded_at:string|null,download_href:string,delete_href:string|null,sharing:array<string,mixed>|null}>
     */
    private static function present(Workspace $workspace, Collection $attachments, ?AttachmentSharingView $sharing, bool $manages): array
    {
        $base = "/workspaces/{$workspace->public_id}/attachments";

        return array_values($attachments->map(fn ($attachment): array => [
            'id' => $attachment->public_id,
            'filename' => $attachment->original_filename,
            'media_type' => $attachment->media_type,
            'bytes' => $attachment->bytes,
            'uploaded_at' => $attachment->available_at?->toISOString(),
            'download_href' => "{$base}/{$attachment->public_id}",
            'delete_href' => $manages ? "{$base}/{$attachment->public_id}" : null,
            'sharing' => $sharing === null ? null : app(AttachmentSharingPresenter::class)->nativeControl($attachment, $sharing,
                route('svc.files.visibility.update', [$workspace, $attachment->public_id], absolute: false), AttachmentAudience::Internal),
        ])->all());
    }
}
