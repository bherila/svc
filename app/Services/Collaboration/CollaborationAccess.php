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
        if ($audience === CollaborationAudience::Internal) {
            return $this->projects->isWorkspaceManager($actor, $workspace);
        }
        $membership = ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->where('user_id', $actor->id)->first();

        return $membership !== null && $membership->access_scope !== ClientCompanyMembership::SCOPE_PROJECTS;
    }

    public function hasPortalMembership(User|AgentPrincipal $actor, Workspace $workspace, ClientCompany $company): bool
    {
        return ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->where('user_id', $actor->id)->exists();
    }

    /** @return Builder<ClientProject> */
    public function visibleProjects(User|AgentPrincipal $actor, Workspace $workspace, ClientCompany $company, CollaborationAudience $audience): Builder
    {
        abort_unless((int) $company->workspace_id === $workspace->id, 404);
        $query = ClientProject::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id);
        if ($audience === CollaborationAudience::Portal) {
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
        abort_unless((int) $company->workspace_id === $workspace->id, 404);

        return $this->visibleTopicsInWorkspace($actor, $workspace, $audience)->where('client_company_id', $company->id);
    }

    /** Shared SQL authority: apply before search matching, counts or limits.
     * @return Builder<ClientCollaborationTopic>
     */
    public function visibleTopicsInWorkspace(User|AgentPrincipal $actor, Workspace $workspace, CollaborationAudience $audience): Builder
    {
        $projects = $this->visibleProjectsInWorkspace($actor, $workspace, $audience)
            ->whereColumn('client_projects.client_company_id', 'client_collaboration_topics.client_company_id')->select('id');
        $companyWide = $this->companyWideIds($actor, $workspace, $audience);

        return ClientCollaborationTopic::query()->where('workspace_id', $workspace->id)
            ->whereIn('client_company_id', ClientCompany::query()->where('workspace_id', $workspace->id)->select('id'))
            ->whereNull('deleted_at')
            ->when($audience === CollaborationAudience::Portal, fn (Builder $query) => $query->where('is_visible_to_client', true))
            ->where(fn (Builder $query) => $query->whereIn('client_project_id', $projects)
                ->orWhere(fn (Builder $company) => $company->whereNull('client_project_id')->whereIn('client_company_id', $companyWide)));
    }

    /** @return Builder<ClientComment> */
    public function visibleCommentsInWorkspace(User|AgentPrincipal $actor, Workspace $workspace, CollaborationAudience $audience): Builder
    {
        $topics = $this->visibleTopicsInWorkspace($actor, $workspace, $audience)
            ->whereColumn('client_collaboration_topics.client_company_id', 'client_comments.client_company_id')
            ->where(fn (Builder $lineage) => $lineage->whereColumn('client_collaboration_topics.client_project_id', 'client_comments.client_project_id')
                ->orWhere(fn (Builder $companyWide) => $companyWide->whereNull('client_collaboration_topics.client_project_id')->whereNull('client_comments.client_project_id')))
            ->select('id');
        $projects = $this->visibleProjectsInWorkspace($actor, $workspace, $audience)
            ->whereColumn('client_projects.client_company_id', 'client_comments.client_company_id')->select('id');
        $tasks = ClientTask::query()->where('workspace_id', $workspace->id)
            ->whereColumn('client_tasks.client_project_id', 'client_comments.client_project_id')
            ->whereIn('client_project_id', $projects)
            ->when($audience === CollaborationAudience::Portal, fn (Builder $query) => $query->where('is_visible_to_client', true))->select('id');

        return ClientComment::query()->where('workspace_id', $workspace->id)
            ->whereIn('client_company_id', ClientCompany::query()->where('workspace_id', $workspace->id)->select('id'))
            ->whereNull('deleted_at')
            ->when($audience === CollaborationAudience::Portal, fn (Builder $query) => $query->where('is_visible_to_client', true))
            ->where(fn (Builder $parents) => $parents
                ->where(fn (Builder $topic) => $topic->whereNull('client_task_id')->whereIn('client_collaboration_topic_id', $topics))
                ->orWhere(fn (Builder $task) => $task->whereNull('client_collaboration_topic_id')->whereIn('client_task_id', $tasks)));
    }

    /** @return Builder<ClientProject> */
    private function visibleProjectsInWorkspace(User|AgentPrincipal $actor, Workspace $workspace, CollaborationAudience $audience): Builder
    {
        $query = ClientProject::query()->where('workspace_id', $workspace->id)
            ->whereIn('client_company_id', ClientCompany::query()->where('workspace_id', $workspace->id)->select('id'));
        if ($audience === CollaborationAudience::Portal) {
            return $this->portal->constrainProjectQuery($query, $actor)->where('is_visible_to_client', true);
        }
        $ids = $this->projects->viewableProjectIds($actor, $workspace);

        return $query->when($ids !== null, fn (Builder $query) => $query->whereIn('id', $ids ?? []));
    }

    /** @return Builder<ClientCompany> */
    private function companyWideIds(User|AgentPrincipal $actor, Workspace $workspace, CollaborationAudience $audience): Builder
    {
        $companies = ClientCompany::query()->where('workspace_id', $workspace->id)->select('id');
        if ($audience === CollaborationAudience::Internal) {
            return $this->projects->isWorkspaceManager($actor, $workspace) ? $companies : $companies->whereRaw('1 = 0');
        }

        return $companies->whereIn('id', ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)
            ->where(fn (Builder $scope) => $scope->whereNull('access_scope')->orWhere('access_scope', '<>', ClientCompanyMembership::SCOPE_PROJECTS))
            ->select('client_company_id'));
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
