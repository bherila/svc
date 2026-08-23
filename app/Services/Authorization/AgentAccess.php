<?php

namespace App\Services\Authorization;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\ProjectRole;

final class AgentAccess
{
    public function __construct(private readonly ProjectAccess $projects) {}

    public function canViewWorkspace(User|AgentPrincipal $user, Workspace $workspace): bool
    {
        return $this->projects->workspaceRole($user, $workspace) !== null
            || $user->clientCompanies()->where('workspace_id', $workspace->id)->exists();
    }

    public function isWorkspaceManager(User|AgentPrincipal $user, Workspace $workspace): bool
    {
        return $this->projects->isWorkspaceManager($user, $workspace);
    }

    public function canViewProject(User|AgentPrincipal $user, ClientProject $project): bool
    {
        return $this->projects->canView($user, $project)
            || ($project->is_visible_to_client && $this->isCompanyMember($user, $project->clientCompany));
    }

    public function canViewTask(User|AgentPrincipal $user, ClientTask $task): bool
    {
        return $this->canViewProject($user, $task->project)
            && (! $this->isCompanyMember($user, $task->project->clientCompany) || $task->is_visible_to_client);
    }

    public function canViewTime(User|AgentPrincipal $user, ClientTimeEntry $entry): bool
    {
        if ($this->isWorkspaceManager($user, $entry->workspace)) {
            return true;
        }
        $role = $this->projects->projectRole($user, $entry->project);
        if (in_array($role, [ProjectRole::Owner, ProjectRole::Manager], true)) {
            return true;
        }
        if ($role === ProjectRole::Contributor && $entry->user_id === $user->id) {
            return true;
        }

        return $entry->status === 'approved'
            && $entry->is_visible_to_client
            && $this->isCompanyMember($user, $entry->clientCompany);
    }

    public function canViewInvoice(User|AgentPrincipal $user, ClientInvoice $invoice): bool
    {
        if ($this->isWorkspaceManager($user, $invoice->workspace)) {
            return true;
        }

        return $invoice->is_visible_to_client
            && in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true)
            && $this->isCompanyMember($user, $invoice->clientCompany);
    }

    public function isWorkspaceClient(User|AgentPrincipal $user, Workspace $workspace): bool
    {
        return $user->clientCompanies()->where('workspace_id', $workspace->id)->exists();
    }

    public function isCompanyMember(User|AgentPrincipal $user, ClientCompany $company): bool
    {
        return $company->portalUsers()->whereKey($user->id)->exists();
    }
}
