<?php

namespace App\Services\Files;

use App\Models\AgentPrincipal;
use App\Models\ClientAgreement;
use App\Models\ClientAttachment;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\BillingRecordAccess;
use App\Services\Authorization\PortalAccess;
use App\Services\Authorization\PortalInvoiceQuery;
use App\Services\Authorization\ProjectAccess;
use App\Services\Collaboration\CollaborationAccess;
use App\Services\Collaboration\CollaborationAudience;
use App\Services\Tasks\TaskVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Tenant-scoped persistence and current parent authority, applied before limits or metadata. */
final class AttachmentReader
{
    public function __construct(
        private readonly ProjectAccess $projects,
        private readonly PortalAccess $portal,
        private readonly PortalInvoiceQuery $invoices,
        private readonly BillingRecordAccess $billing,
        private readonly CollaborationAccess $collaboration,
        private readonly AgentAccess $agents,
        private readonly TaskVisibility $tasks,
    ) {}

    public function nativePortalAudience(User $actor, Workspace $workspace): AttachmentAudience
    {
        return $this->projects->isWorkspaceManager($actor, $workspace) ? AttachmentAudience::PortalPreview : AttachmentAudience::Portal;
    }

    private function assertPreviewAuthority(User|AgentPrincipal $actor, Workspace $workspace, AttachmentAudience $audience): void
    {
        if ($audience === AttachmentAudience::PortalPreview) {
            abort_unless($actor instanceof User && $this->projects->isWorkspaceManager($actor, $workspace), 403);
        }
    }

    public function agentAudience(User|AgentPrincipal $actor, Workspace $workspace): AttachmentAudience
    {
        return $this->agents->isWorkspaceClient($actor, $workspace) ? AttachmentAudience::Portal : AttachmentAudience::Internal;
    }

    /** Lifecycle filtering is explicit: mutation responses may describe an authorized deleted row.
     * @return Builder<ClientAttachment>
     */
    public function query(User|AgentPrincipal $actor, Workspace $workspace, AttachmentAudience $audience, ?string $type = null): Builder
    {
        return $this->queryForTypes($actor, $workspace, $audience, $type === null ? AttachmentRecordResolver::readableTypes() : [$type]);
    }

    /** Construct only the parent policies this surface actually presents.
     * @param  list<string>  $types
     * @return Builder<ClientAttachment>
     */
    public function queryForTypes(User|AgentPrincipal $actor, Workspace $workspace, AttachmentAudience $audience, array $types): Builder
    {
        $this->assertPreviewAuthority($actor, $workspace, $audience);
        abort_unless(array_diff($types, AttachmentRecordResolver::readableTypes()) === [], 422);
        $viewable = in_array($audience, [AttachmentAudience::Portal, AttachmentAudience::PortalPreview], true) ? [] : $this->projects->viewableProjectIds($actor, $workspace);
        $staff = ! in_array($audience, [AttachmentAudience::Portal, AttachmentAudience::PortalPreview], true) && $this->projects->workspaceRole($actor, $workspace) !== null;

        return ClientAttachment::query()->where('workspace_id', $workspace->id)
            ->where(function (Builder $files) use ($actor, $workspace, $audience, $types, $viewable, $staff): void {
                $files->whereRaw('1 = 0');
                foreach ($types as $parentType) {
                    if (! in_array($parentType, AttachmentRecordResolver::readableTypes(), true)) {
                        continue;
                    }
                    if ($staff) {
                        $parents = $this->internalParents($actor, $workspace, $parentType, $viewable);
                        $files->orWhere(fn (Builder $branch) => $branch->where('record_type', $parentType)
                            ->whereIn('record_public_id', $parents->select('public_id')));
                    }
                    if ($audience !== AttachmentAudience::Internal) {
                        $parents = $this->portalParents($actor, $workspace, $parentType, $audience === AttachmentAudience::PortalPreview);
                        $files->orWhere(fn (Builder $branch) => $branch->where('record_type', $parentType)
                            ->where('is_visible_to_client', true)->whereIn('record_public_id', $parents->select('public_id')));
                    }
                }
            });
    }

    public function canView(User|AgentPrincipal $actor, Workspace $workspace, ClientAttachment $file, AttachmentAudience $audience): bool
    {
        return $file->workspace_id === $workspace->id && $this->query($actor, $workspace, $audience, $file->record_type)->whereKey($file->id)->exists();
    }

    /** A single parent is authorized before listing; the file query still rechecks it.
     * @return Builder<ClientAttachment>
     */
    public function forParent(User|AgentPrincipal $actor, Workspace $workspace, string $type, string $id, AttachmentAudience $audience): Builder
    {
        app(AttachmentRecordResolver::class)->resolve($workspace, $type, $id);
        $this->assertParentAccess($actor, $workspace, $type, $id, $audience);

        return $this->query($actor, $workspace, $audience, $type)->where('record_public_id', $id)
            ->where('lifecycle_state', ClientAttachment::STATE_AVAILABLE);
    }

    public function assertParentAccess(User|AgentPrincipal $actor, Workspace $workspace, string $type, string $id, AttachmentAudience $audience): void
    {
        $this->assertPreviewAuthority($actor, $workspace, $audience);
        if (! in_array($audience, [AttachmentAudience::Portal, AttachmentAudience::PortalPreview], true) && $this->projects->workspaceRole($actor, $workspace) !== null) {
            $viewable = $this->projects->viewableProjectIds($actor, $workspace);
            if ($this->internalParents($actor, $workspace, $type, $viewable)->where('public_id', $id)->exists()) {
                return;
            }
        }
        abort_unless($audience !== AttachmentAudience::Internal
            && $this->portalParents($actor, $workspace, $type, $audience === AttachmentAudience::PortalPreview)->where('public_id', $id)->exists(), 404);
    }

    /** Structural disclosure fact only, after the caller's current parent read authority. */
    public function parentVisibleToClient(User|AgentPrincipal $actor, Workspace $workspace, string $type, string $id, AttachmentAudience $audience): bool
    {
        $this->assertParentAccess($actor, $workspace, $type, $id, $audience);
        abort_if(in_array($type, ['topic', 'comment'], true), 422);

        return $this->portalParents($actor, $workspace, $type, true)->where('public_id', $id)->exists();
    }

    /** @return Builder<covariant Model> */
    private function base(Workspace $workspace, string $type): Builder
    {
        $query = match ($type) {
            'company' => ClientCompany::query(), 'project' => ClientProject::query(), 'task' => ClientTask::query(),
            'proposal' => ClientProposal::query(), 'agreement' => ClientAgreement::query(),
            'invoice' => ClientInvoice::query(), 'expense' => ClientExpense::query(),
            default => abort(404),
        };
        $query->where('workspace_id', $workspace->id);
        if ($type === 'company') {
            return $query;
        }
        $projects = ClientProject::query()->where('workspace_id', $workspace->id)
            ->whereIn('client_company_id', ClientCompany::query()->where('workspace_id', $workspace->id)->select('id'));
        if ($type === 'task') {
            return $query->whereIn('client_project_id', $projects->select('id'));
        }
        $query->whereIn('client_company_id', ClientCompany::query()->where('workspace_id', $workspace->id)->select('id'));
        if (in_array($type, ['proposal', 'agreement'], true)) {
            $table = $query->getModel()->getTable();
            $projects->whereColumn('client_projects.client_company_id', $table.'.client_company_id');
            $query->where(fn (Builder $parent) => $parent->whereNull('client_project_id')->orWhereIn('client_project_id', $projects->select('id')));
        }

        return $query;
    }

    /** @param list<int>|null $viewable
     * @return Builder<covariant Model>
     */
    private function internalParents(User|AgentPrincipal $actor, Workspace $workspace, string $type, ?array $viewable): Builder
    {
        if ($type === 'topic') {
            return $this->collaboration->visibleTopicsInWorkspace($actor, $workspace, CollaborationAudience::Internal);
        }
        if ($type === 'comment') {
            return $this->collaboration->visibleCommentsInWorkspace($actor, $workspace, CollaborationAudience::Internal);
        }
        $query = $this->base($workspace, $type);
        if ($viewable === null) {
            return $query;
        }
        if (in_array($type, ['company', 'expense'], true)) {
            return $query->whereRaw('1 = 0');
        }
        if ($type === 'project') {
            return $query->whereIn('id', $viewable);
        }
        if ($type === 'invoice') {
            // Native adapters carry User. Agent callers reaching this branch
            // remain manager-only and therefore returned above.
            abort_unless($actor instanceof User, 403);

            return $this->billing->constrainInvoices(ClientInvoice::query()->whereIn('id', $query->select('id')), $actor, $workspace);
        }
        if ($type === 'proposal') {
            $companies = ClientProject::query()->where('workspace_id', $workspace->id)->whereIn('id', $viewable)->select('client_company_id');

            return $query->where(fn (Builder $parent) => $parent->whereIn('client_project_id', $viewable)
                ->orWhere(fn (Builder $company) => $company->whereNull('client_project_id')->whereIn('client_company_id', $companies)));
        }

        return $query->whereIn('client_project_id', $viewable);
    }

    /** @return Builder<covariant Model> */
    private function portalParents(User|AgentPrincipal $actor, Workspace $workspace, string $type, bool $preview): Builder
    {
        if ($type === 'topic') {
            return $this->collaboration->visibleTopicsInWorkspace($actor, $workspace, CollaborationAudience::Portal);
        }
        if ($type === 'comment') {
            return $this->collaboration->visibleCommentsInWorkspace($actor, $workspace, CollaborationAudience::Portal);
        }
        $query = $this->base($workspace, $type);
        if (in_array($type, ['company', 'expense'], true)) {
            return $query->whereRaw('1 = 0');
        }
        if ($type === 'task') {
            $tasks = ClientTask::query()->whereIn('id', $query->select('id'));

            return $preview ? $this->tasks->constrainVisibleStructure($tasks, $workspace) : $this->tasks->constrainPortal($tasks, $workspace, $actor);
        }
        if ($type === 'project') {
            return $preview ? $query->where('is_visible_to_client', true)
                : $this->portal->constrainProjectQuery(ClientProject::query()->whereIn('id', $query->select('id')), $actor)->where('is_visible_to_client', true);
        }
        if ($type === 'invoice') {
            return $query->whereIn('id', ($preview ? $this->invoices->visibleInWorkspace($workspace, $actor) : $this->invoices->strictlyVisibleInWorkspace($workspace, $actor))->select('id'));
        }
        $query->where('is_visible_to_client', true)
            ->whereIn('status', $type === 'proposal' ? ['sent', 'accepted'] : ['active', 'paused', 'terminated', 'expired']);
        if ($preview) {
            return $query;
        }
        $memberships = ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->select('client_company_id');
        $projects = $this->portal->constrainProjectQuery(ClientProject::query()->where('workspace_id', $workspace->id), $actor);
        $table = $query->getModel()->getTable();
        $projects->whereColumn('client_projects.client_company_id', $table.'.client_company_id');

        return $query->whereIn('client_company_id', $memberships)
            ->where(fn (Builder $parent) => $parent->whereNull('client_project_id')->orWhereIn('client_project_id', $projects->select('id')));
    }
}
