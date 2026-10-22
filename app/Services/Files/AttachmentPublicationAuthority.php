<?php

namespace App\Services\Files;

use App\Contracts\WorkspaceOwned;
use App\Models\ClientCollaborationTopic;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Collaboration\CollaborationRepository;
use App\Support\Concurrency\Locks;
use Illuminate\Database\Eloquent\Model;

/** All callers already hold BillingMaintenance; file locks always follow these facts. */
final class AttachmentPublicationAuthority
{
    public function __construct(private readonly AttachmentRecordResolver $records, private readonly AttachmentWriteAccess $workspaceAccess,
        private readonly CollaborationRepository $collaboration) {}

    /** @return Model&WorkspaceOwned */
    public function lock(User $actor, Workspace $workspace, AttachmentParentReference $reference, AttachmentMutationOrigin $origin): Model
    {
        if ($reference->collaboration()) {
            return $this->collaborationParent($actor, $workspace, $reference, $origin);
        }
        abort_if($reference->type === 'expense', 422, 'Expense receipts have no client-sharing audience.');
        $early = in_array($reference->type, ['proposal', 'agreement', 'invoice'], true);
        if (! $early) {
            $this->workspaceAccess->lockWorkspace($workspace);
        }
        $initial = $this->records->resolve($workspace, $reference->type, $reference->publicId);
        /** @var Model&WorkspaceOwned $parent */
        $parent = $initial->newQuery()->where('workspace_id', $workspace->id)->where('public_id', $reference->publicId)
            ->tap(Locks::forUpdate())->firstOrFail();
        // A stale ordinary resolver must never validate another current lineage.
        $this->sameLineage($initial, $parent);
        if ($early) {
            $this->workspaceAccess->lockWorkspace($workspace);
        }
        $facts = $this->collaboration->currentAccess($workspace, null, $actor);
        abort_unless($facts->canPublish(), 403);
        abort_if($origin === AttachmentMutationOrigin::Agent && $facts->portalMember, 403);

        return $parent;
    }

    /** @return Model&WorkspaceOwned */
    private function collaborationParent(User $actor, Workspace $workspace, AttachmentParentReference $reference, AttachmentMutationOrigin $origin): Model
    {
        $this->workspaceAccess->lockWorkspace($workspace);
        abort_unless($reference->companyId !== null, 404);
        $company = $this->collaboration->company($workspace, $reference->companyId);
        if ($reference->type === 'topic') {
            $parent = $this->collaboration->topic($workspace, $company, $reference->publicId, true);
            abort_unless($parent->deleted_at === null, 404);
            $project = $this->collaboration->lockParentProject($workspace, $company, $parent);
        } else {
            $initial = $this->collaboration->comment($workspace, $company, $reference->publicId);
            $ancestor = $this->collaboration->commentParent($workspace, $company, $initial, true);
            $parent = $this->collaboration->comment($workspace, $company, $reference->publicId, true);
            $this->sameLineage($initial, $parent);
            abort_unless($parent->deleted_at === null, 404);
            abort_if($ancestor instanceof ClientCollaborationTopic && $ancestor->deleted_at !== null, 404);
            abort_unless($parent->client_project_id === $ancestor->client_project_id, 404);
            $project = $this->collaboration->lockParentProject($workspace, $company, $ancestor);
        }
        $facts = $this->collaboration->currentAccess($workspace, $project, $actor);
        abort_unless($facts->canPublish(), 403);
        abort_if($origin === AttachmentMutationOrigin::Agent && $facts->portalMember, 403);

        return $parent;
    }

    private function sameLineage(Model $initial, Model $current): void
    {
        foreach (['workspace_id', 'client_company_id', 'client_project_id', 'client_task_id', 'client_collaboration_topic_id'] as $key) {
            abort_unless($initial->getAttribute($key) === $current->getAttribute($key), 404);
        }
    }
}
