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

    /**
     * The client companies this user is a portal member of *in this workspace*.
     *
     * One definition, because three callers used to ask this question and answer
     * it locally - and every one of them answered it with half the condition.
     *
     * Both halves are needed. `client_companies.workspace_id` alone lets a
     * membership row whose own `workspace_id` is another tenant's grant access
     * here on the strength of the company it names;
     * `client_company_memberships.workspace_id` alone lets a membership of this
     * tenant reach a company that belongs elsewhere. The composite key added in
     * #113 makes both rows unstorable, which is exactly why the reads that would
     * consume a row migrated in before it are the second line of defence.
     *
     * The company column is also qualified because the pivot now carries a
     * `workspace_id` too, so an unqualified name is ambiguous - errno 1052 on
     * MariaDB, and an error on SQLite.
     *
     * Returns ids rather than the relation so a caller cannot widen the query it
     * was handed and quietly answer a different question.
     *
     * @return list<int>
     */
    public function portalCompanyIdsIn(User|AgentPrincipal $user, Workspace $workspace): array
    {
        $ids = $user->clientCompanies()
            ->where('client_companies.workspace_id', $workspace->id)
            ->wherePivot('workspace_id', $workspace->id)
            ->pluck('client_companies.id')
            ->all();

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }

    public function canViewWorkspace(User|AgentPrincipal $user, Workspace $workspace): bool
    {
        return $this->projects->workspaceRole($user, $workspace) !== null
            || $this->isWorkspaceClient($user, $workspace);
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
        return $this->portalCompanyIdsIn($user, $workspace) !== [];
    }

    public function isCompanyMember(User|AgentPrincipal $user, ClientCompany $company): bool
    {
        return $company->portalUsers()->whereKey($user->id)->exists();
    }
}
