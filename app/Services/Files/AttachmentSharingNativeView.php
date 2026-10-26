<?php

namespace App\Services\Files;

use App\Models\ClientAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;

/** Initial bounded data is embedded in Inertia props; continuation is a finished URL. */
final class AttachmentSharingNativeView
{
    public function __construct(private readonly AttachmentSharingTransport $files, private readonly AttachmentReader $reader,
        private readonly AttachmentSharingPresenter $presenter) {}

    /** @return array<string,mixed> */
    public function collaboration(User $actor, Workspace $workspace, AttachmentParentReference $reference,
        AttachmentAudience $audience, ?AttachmentPageQuery $page = null): array
    {
        $result = $this->files->listing($actor, $workspace, $reference, $audience, $page);
        $view = $this->presenter->view($actor, $workspace, $reference, $audience);
        $ids = array_column($result['data'], 'id');
        $rows = $this->reader->forParent($actor, $workspace, $reference->type, $reference->publicId, $audience)
            ->whereIn('public_id', $ids)->get()->keyBy('public_id');
        $parameters = ['workspace' => $workspace, 'client' => $reference->companyId,
            'recordType' => $reference->type, 'recordPublicId' => $reference->publicId];
        $files = [];
        foreach ($ids as $id) {
            $file = $rows->get($id);
            if (! $file instanceof ClientAttachment) {
                continue;
            }
            $sharing = $this->presenter->nativeControl($file, $view,
                route('svc.files.collaboration.visibility.update', [...$parameters, 'attachment' => $id], absolute: false), $audience);
            $files[] = ['id' => $id, 'filename' => $file->original_filename, 'media_type' => $file->media_type, 'bytes' => $file->bytes,
                'uploaded_at' => $file->created_at?->toIso8601String(),
                'download_href' => route('svc.files.download', [$workspace, $id], absolute: false),
                'delete_href' => $sharing === null ? null : route('svc.files.collaboration.destroy', [...$parameters, 'attachment' => $id], absolute: false),
                'sharing' => $sharing];
        }
        $canWrite = $audience === AttachmentAudience::Internal && $view->canPublish && AttachmentSharingGate::available();
        $cursor = $result['meta']['next_cursor'];

        return ['files' => $files, 'parent_version' => AgentApiVersion::for($view->parent),
            'upload' => $canWrite ? ['href' => route('svc.files.collaboration.upload', $parameters, absolute: false),
                'parent_version' => AgentApiVersion::for($view->parent)] : null,
            'next_href' => $cursor === null ? null : route('svc.files.collaboration.index', [...$parameters,
                'limit' => $page?->limit ?? 100, 'cursor' => $cursor], absolute: false)];
    }
}
