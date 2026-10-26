<?php

namespace App\Services\Files;

use App\Models\AgentPrincipal;
use App\Models\ClientAttachment;
use App\Models\ClientCollaborationTopic;
use App\Models\ClientComment;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Collaboration\CollaborationAccess;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Collaboration\CollaborationRepository;
use App\Support\AgentApi\AgentApiVersion;

final class AttachmentSharingPresenter
{
    public function __construct(private readonly AttachmentRecordResolver $records, private readonly AttachmentReader $reader,
        private readonly AgentAccess $agents, private readonly CollaborationRepository $collaboration,
        private readonly CollaborationAccess $collaborationAccess) {}

    public function view(User|AgentPrincipal $actor, Workspace $workspace, AttachmentParentReference $reference,
        AttachmentAudience $audience): AttachmentSharingView
    {
        $parent = $this->records->resolve($workspace, $reference->type, $reference->publicId);
        $this->reader->assertParentAccess($actor, $workspace, $reference->type, $reference->publicId, $audience);
        if (! $reference->collaboration()) {
            return new AttachmentSharingView($reference, $parent,
                $this->reader->parentVisibleToClient($actor, $workspace, $reference->type, $reference->publicId, $audience),
                $reference->type !== 'expense' && $this->agents->isWorkspaceManager($actor, $workspace));
        }
        $company = $this->collaboration->company($workspace, $reference->companyId ?? '');
        abort_unless(($parent instanceof ClientComment || $parent instanceof ClientCollaborationTopic)
            && $parent->client_company_id === $company->id, 404);
        $ancestor = $parent instanceof ClientComment ? $this->collaboration->commentParent($workspace, $company, $parent) : $parent;
        $project = $ancestor instanceof ClientTask ? $ancestor->project : $this->collaboration->topicProject($workspace, $company, $ancestor);
        $visible = $this->collaborationAccess->effectiveVisible($workspace, $company, $ancestor)
            && (! $parent instanceof ClientComment || ($parent->deleted_at === null && $parent->is_visible_to_client));
        $publisher = $parent->deleted_at === null
            && $this->collaborationAccess->canPublish($actor, $workspace, $company, $project, CollaborationAudience::Internal);

        return new AttachmentSharingView($reference, $parent, $visible, $publisher);
    }

    /** Only called after current parent AND file authorization.
     * @return array<string,mixed> */
    public function state(ClientAttachment $file, AttachmentSharingView $view): array
    {
        return ['id' => $file->public_id, 'is_visible_to_client' => $file->is_visible_to_client,
            'effective_visible_to_client' => $file->is_visible_to_client && $view->parentVisibleToClient,
            'sharing_note' => $view->parentVisibleToClient ? null : 'The parent is not currently visible to clients.',
            'version' => AgentApiVersion::for($file), 'parent_version' => AgentApiVersion::for($view->parent)];
    }

    /** New collaboration schema only; existing legacy Attachment contracts stay unchanged.
     * @return array<string,mixed> */
    public function collaborationFile(ClientAttachment $file, AttachmentSharingView $view): array
    {
        $state = $this->state($file, $view);

        return [...AttachmentMetadata::for($file),
            'is_visible_to_client' => $state['is_visible_to_client'], 'effective_visible_to_client' => $state['effective_visible_to_client'],
            'sharing_note' => $state['sharing_note']];
    }

    /** Native controls are capabilities, separate from safe read metadata.
     * @return array<string,mixed>|null */
    public function nativeControl(ClientAttachment $file, AttachmentSharingView $view, string $updateHref, AttachmentAudience $audience): ?array
    {
        if (! in_array($audience, [AttachmentAudience::Internal, AttachmentAudience::Native], true) || ! $view->canPublish || ! AttachmentSharingGate::available()
            || $file->lifecycle_state !== ClientAttachment::STATE_AVAILABLE || $view->reference->type === 'expense') {
            return null;
        }
        $state = $this->state($file, $view);
        unset($state['id']);

        return [...$state, 'update_href' => $updateHref];
    }
}
