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
        $portal = in_array($audience, [AttachmentAudience::Portal, AttachmentAudience::PortalPreview], true);
        $readParameters = $portal ? ['clientCompany' => $reference->companyId, 'recordType' => $reference->type,
            'recordPublicId' => $reference->publicId] : $parameters;
        $files = [];
        foreach ($ids as $id) {
            $file = $rows->get($id);
            if (! $file instanceof ClientAttachment) {
                continue;
            }
            $sharing = $portal ? null : $this->presenter->nativeControl($file, $view,
                route('svc.files.collaboration.visibility.update', [...$parameters, 'attachment' => $id], absolute: false), $audience);
            $files[] = AttachmentMetadata::native($file,
                $portal ? route('portal.files.collaboration.download', [...$readParameters, 'attachment' => $id], absolute: false)
                    : route('svc.files.download', [$workspace, $id], absolute: false),
                $sharing === null ? null : route('svc.files.collaboration.destroy', [...$parameters, 'attachment' => $id], absolute: false), $sharing);
        }
        $canWrite = $audience === AttachmentAudience::Internal && $view->canPublish && AttachmentSharingGate::available();
        $cursor = $result['meta']['next_cursor'];

        return ['files' => $files, 'parent_version' => AgentApiVersion::for($view->parent),
            'upload' => $canWrite ? ['href' => route('svc.files.collaboration.upload', $parameters, absolute: false),
                'parent_version' => AgentApiVersion::for($view->parent)] : null,
            'next_href' => $cursor === null ? null : route($portal ? 'portal.files.collaboration.index' : 'svc.files.collaboration.index', [...$readParameters,
                'limit' => $page?->limit ?? 100, 'cursor' => $cursor], absolute: false)];
    }

    /** Legacy native forms retain their existing upload/deletion contracts.
     * @return array<string,mixed> */
    public function legacy(User $actor, Workspace $workspace, AttachmentParentReference $reference, int $page = 1): array
    {
        abort_unless(in_array($reference->type, ['company', 'project', 'task'], true), 404);
        $view = $this->presenter->view($actor, $workspace, $reference, AttachmentAudience::Internal);
        $rows = $this->reader->forParent($actor, $workspace, $reference->type, $reference->publicId, AttachmentAudience::Internal)
            ->orderByDesc('id')->simplePaginate(100, ['*'], 'page', $page);
        $files = [];
        foreach ($rows->items() as $file) {
            $files[] = AttachmentMetadata::native($file, route('svc.files.download', [$workspace, $file->public_id], absolute: false),
                $view->canPublish ? route('svc.files.destroy', [$workspace, $file->public_id], absolute: false) : null,
                $this->presenter->nativeControl($file, $view, route('svc.files.visibility.update', [
                    'workspace' => $workspace, 'attachment' => $file->public_id], absolute: false), AttachmentAudience::Internal));
        }
        $parameters = ['workspace' => $workspace, 'recordType' => $reference->type, 'recordPublicId' => $reference->publicId];

        return ['files' => $files, 'upload' => null, 'parent_version' => AgentApiVersion::for($view->parent),
            'next_href' => $rows->hasMorePages() ? route('svc.files.parent.index', [...$parameters, 'page' => $page + 1], absolute: false) : null,
            'legacy_upload_href' => $view->canPublish ? route('svc.files.store', $parameters, absolute: false) : null];
    }
}
