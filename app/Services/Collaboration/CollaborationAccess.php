<?php

namespace App\Services\Collaboration;

use App\Models\AgentPrincipal;
use App\Models\ClientCollaborationTopic;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\PortalAccess;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\ProjectRole;
use Illuminate\Database\Eloquent\Builder;

/** One audience-aware policy shared by presentation, search and transport adapters. */
final class CollaborationAccess
{
    public function __construct(private readonly ProjectAccess $projects, private readonly PortalAccess $portal, private readonly CollaborationRepository $records) {}

    public function companyWide(User|AgentPrincipal $actor, Workspace $workspace, ClientCompany $company, CollaborationAudience $audience): bool
    {
        abort_unless((int) $company->workspace_id === $workspace->id, 404);
        if ($this->projects->isWorkspaceManager($actor, $workspace)) {
            return true;
        }
        if ($audience === CollaborationAudience::Internal) {
            return false;
        }
        $membership = ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->where('user_id', $actor->id)->first();

        return $membership !== null && $membership->access_scope !== ClientCompanyMembership::SCOPE_PROJECTS;
    }

    /** @return Builder<ClientProject> */
    public function visibleProjects(User|AgentPrincipal $actor, Workspace $workspace, ClientCompany $company, CollaborationAudience $audience): Builder
    {
        abort_unless((int) $company->workspace_id === $workspace->id, 404);
        $query = ClientProject::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id);
        if ($audience === CollaborationAudience::Portal) {
            if ($this->projects->isWorkspaceManager($actor, $workspace)) {
                return $query->where('is_visible_to_client', true);
            }

            return $this->portal->constrainProjectQuery($query, $actor)->where('is_visible_to_client', true);
        }
        $ids = $this->projects->viewableProjectIds($actor, $workspace);

        return $query->when($ids !== null, fn (Builder $query) => $query->whereIn('id', $ids ?? []));
    }

    /** @return Builder<ClientProject> */
    public function managedProjects(User|AgentPrincipal $actor, Workspace $workspace, ClientCompany $company): Builder
    {
        $query = $this->visibleProjects($actor, $workspace, $company, CollaborationAudience::Internal);
        if ($this->projects->isWorkspaceManager($actor, $workspace)) {
            return $query;
        }

        return $query->whereIn('id', ClientProjectMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)
            ->whereIn('role', [ProjectRole::Owner->value, ProjectRole::Manager->value])->select('client_project_id'));
    }

    /** @return Builder<ClientCollaborationTopic> */
    public function visibleTopics(User|AgentPrincipal $actor, Workspace $workspace, ClientCompany $company, CollaborationAudience $audience): Builder
    {
        $projects = $this->visibleProjects($actor, $workspace, $company, $audience)->select('id');
        $companyWide = $this->companyWide($actor, $workspace, $company, $audience);

        return $this->records->topics($workspace, $company)->whereNull('deleted_at')
            ->when($audience === CollaborationAudience::Portal, fn (Builder $query) => $query->where('is_visible_to_client', true))
            ->where(function (Builder $query) use ($projects, $companyWide): void {
                $query->whereIn('client_project_id', $projects);
                if ($companyWide) {
                    $query->orWhereNull('client_project_id');
                }
            });
    }

    public function canViewParent(User|AgentPrincipal $actor, Workspace $workspace, ClientCompany $company, ClientTask|ClientCollaborationTopic $parent, CollaborationAudience $audience): bool
    {
        $project = $parent instanceof ClientTask ? $parent->project : $this->records->topicProject($workspace, $company, $parent);
        if ($project !== null && ($project->workspace_id !== $workspace->id || $project->client_company_id !== $company->id)) {
            return false;
        }
        if ($parent->workspace_id !== $workspace->id) {
            return false;
        }
        if ($audience === CollaborationAudience::Internal) {
            return $project === null ? $this->companyWide($actor, $workspace, $company, $audience) : $this->projects->canView($actor, $project);
        }
        if (! $this->effectiveVisible($workspace, $company, $parent)) {
            return false;
        }

        return $project === null
            ? $this->companyWide($actor, $workspace, $company, $audience)
            : $this->visibleProjects($actor, $workspace, $company, $audience)->whereKey($project->id)->exists();
    }

    public function canPublish(User|AgentPrincipal $actor, Workspace $workspace, ClientCompany $company, ?ClientProject $project, CollaborationAudience $audience): bool
    {
        if ($audience !== CollaborationAudience::Internal) {
            return false;
        }

        return $project === null ? $this->companyWide($actor, $workspace, $company, $audience) : $this->projects->canManageTasks($actor, $project);
    }

    public function canComment(User|AgentPrincipal $actor, Workspace $workspace, ClientCompany $company, ClientTask|ClientCollaborationTopic $parent, CollaborationAudience $audience): bool
    {
        if ($audience !== CollaborationAudience::Internal || ! $this->canViewParent($actor, $workspace, $company, $parent, $audience)
            || ($parent instanceof ClientCollaborationTopic && $parent->deleted_at !== null)) {
            return false;
        }
        $project = $parent instanceof ClientTask ? $parent->project : $this->records->topicProject($workspace, $company, $parent);

        return $project === null ? $this->companyWide($actor, $workspace, $company, $audience) : in_array($this->projects->projectRole($actor, $project), [ProjectRole::Owner, ProjectRole::Manager, ProjectRole::Contributor], true);
    }

    public function canEditTopic(User|AgentPrincipal $actor, Workspace $workspace, ClientCompany $company, ClientCollaborationTopic $topic, CollaborationAudience $audience): bool
    {
        return $topic->deleted_at === null && $topic->author_user_id === $actor->id
            && $this->canPublish($actor, $workspace, $company, $this->records->topicProject($workspace, $company, $topic), $audience);
    }

    public function canEditComment(User|AgentPrincipal $actor, Workspace $workspace, ClientCompany $company, ClientComment $comment, ClientTask|ClientCollaborationTopic $parent, CollaborationAudience $audience): bool
    {
        return $comment->deleted_at === null && $comment->author_user_id === $actor->id && $this->canComment($actor, $workspace, $company, $parent, $audience);
    }

    public function effectiveVisible(Workspace $workspace, ClientCompany $company, ClientTask|ClientCollaborationTopic $parent): bool
    {
        $project = $parent instanceof ClientTask ? $parent->project : $this->records->topicProject($workspace, $company, $parent);

        return $parent->is_visible_to_client && ($project === null || $project->is_visible_to_client)
            && (! $parent instanceof ClientCollaborationTopic || $parent->deleted_at === null);
    }
}
