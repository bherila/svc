<?php

namespace App\Services\Files;

use App\Models\AgentPrincipal;
use App\Models\ClientAttachment;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Support\Collection;

/** One authorized window query for the bounded comment page, never one query per comment. */
final class CollaborationAttachmentPreview
{
    public function __construct(private readonly AttachmentReader $reader, private readonly AttachmentSharingPresenter $presenter) {}

    /** @param list<ClientComment> $comments
     * @return array<string,array<string,mixed>> keyed by comment UUID */
    public function comments(User|AgentPrincipal $actor, Workspace $workspace, ClientCompany $company, array $comments,
        AttachmentAudience $audience, bool $publisher, bool $parentVisible, bool $agent = false): array
    {
        abort_unless($company->workspace_id === $workspace->id, 404);
        $ids = [];
        foreach ($comments as $comment) {
            abort_unless($comment->workspace_id === $workspace->id && $comment->client_company_id === $company->id, 404);
            if ($comment->deleted_at === null) {
                $ids[] = $comment->public_id;
            }
        }
        /** @var Collection<string,Collection<int,ClientAttachment>> $groups */
        $groups = collect();
        if ($ids !== []) {
            // The reader constrains audience/tenant/lineage before ranking, counts or names.
            $authorized = $this->reader->query($actor, $workspace, $audience, 'comment')->whereIn('record_public_id', $ids)
                ->where('lifecycle_state', ClientAttachment::STATE_AVAILABLE)->select('client_attachments.*')
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY record_public_id ORDER BY id DESC) AS preview_rank');
            $groups = ClientAttachment::query()->fromSub($authorized, 'client_attachments')->where('preview_rank', '<=', 4)
                ->orderByDesc('id')->get()->groupBy('record_public_id');
        }
        $panels = [];
        foreach ($comments as $comment) {
            if ($comment->deleted_at !== null) {
                continue;
            }
            $reference = new AttachmentParentReference('comment', $comment->public_id, $company->public_id);
            $view = new AttachmentSharingView($reference, $comment, $parentVisible && $comment->is_visible_to_client, $publisher);
            $parameters = ['workspace' => $workspace, 'client' => $company->public_id, 'recordType' => 'comment', 'recordPublicId' => $comment->public_id];
            $nativeParameters = ['workspace' => $workspace, 'clientCompany' => $company, 'recordType' => 'comment', 'recordPublicId' => $comment->public_id];
            $portal = in_array($audience, [AttachmentAudience::Portal, AttachmentAudience::PortalPreview], true);
            $readParameters = $portal ? ['clientCompany' => $company, 'recordType' => 'comment', 'recordPublicId' => $comment->public_id] : $nativeParameters;
            $rows = $groups->get($comment->public_id, collect());
            $files = [];
            foreach ($rows->take(3) as $file) {
                if ($agent) {
                    $files[] = $this->presenter->collaborationFile($file, $view);

                    continue;
                }
                $sharing = $portal ? null : $this->presenter->nativeControl($file, $view,
                    route('agent-api.v1.collaboration.attachments.visibility.update', [...$parameters, 'attachment' => $file->public_id], absolute: false), $audience);
                $files[] = AttachmentMetadata::native($file,
                    $portal ? route('portal.files.collaboration.download', [...$readParameters, 'attachment' => $file->public_id], absolute: false)
                        : route('svc.files.download', [$workspace, $file->public_id], absolute: false),
                    $sharing === null ? null : route('agent-api.v1.collaboration.attachments.destroy', [...$parameters, 'attachment' => $file->public_id], absolute: false), $sharing);
            }
            $canWrite = ! $agent && $audience === AttachmentAudience::Internal && $publisher && AttachmentSharingGate::available();
            $page = $agent ? route('agent-api.v1.collaboration.attachments.index', $parameters, absolute: false)
                : route($portal ? 'portal.files.collaboration.index' : 'svc.files.collaboration.index', $readParameters, absolute: false);
            if ($agent) {
                $panels[$comment->public_id] = ['data' => $files, 'parent_version' => AgentApiVersion::for($comment),
                    'next_href' => $rows->count() > 3 ? $page : null];

                continue;
            }
            $panels[$comment->public_id] = ['files' => $files, 'upload' => $canWrite ? [
                'href' => route('agent-api.v1.collaboration.attachments.upload-url', $parameters, absolute: false), 'parent_version' => AgentApiVersion::for($comment)] : null,
                'parent_version' => AgentApiVersion::for($comment), 'next_href' => $rows->count() > 3 ? $page : null];
        }

        return $panels;
    }
}
