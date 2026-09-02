<?php

namespace App\Services\Authorization;

use App\Models\AgentPrincipal;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\ProjectRole;
use Closure;

final class AgentCapabilities
{
    private const array ORDER = [
        'projects:read', 'tasks:read', 'tasks:write', 'time:read', 'time:write',
        'time:approve', 'billing:read', 'billing:write', 'billing:deliver',
    ];

    public function __construct(
        private readonly AgentAccess $access,
        private readonly ProjectAccess $projects,
    ) {}

    /**
     * @return array{capabilities:list<string>,project_capabilities:list<array{project_id:string,role:string,capabilities:list<string>}>}
     */
    public function forWorkspace(User|AgentPrincipal $user, Workspace $workspace, Closure $allowsScope): array
    {
        $projectEntries = [];
        $workspaceCapabilities = [];
        $canDiscloseProjects = $allowsScope(AgentApiScopes::PROJECTS_READ);

        $projects = ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->with(['workspace', 'clientCompany'])
            ->orderBy('id')
            ->get()
            ->filter(fn (ClientProject $project): bool => $this->access->canViewProject($user, $project));

        foreach ($projects as $project) {
            $capabilities = $this->forProject($user, $project, $allowsScope);
            $workspaceCapabilities = [...$workspaceCapabilities, ...$capabilities];
            if ($canDiscloseProjects) {
                $role = $this->projects->projectRole($user, $project)?->value;
                $projectEntries[] = [
                    'project_id' => $project->public_id,
                    'role' => $role ?? 'client',
                    'capabilities' => $capabilities,
                ];
            }
        }

        if ($this->access->isWorkspaceManager($user, $workspace)) {
            $workspaceCapabilities = [...$workspaceCapabilities, ...$this->managerCapabilities($allowsScope)];
        }
        if ($allowsScope(AgentApiScopes::BILLING_READ)
            && ($this->access->isWorkspaceManager($user, $workspace) || $this->access->isWorkspaceClient($user, $workspace))) {
            $workspaceCapabilities[] = 'billing:read';
        }

        return [
            'capabilities' => $this->ordered($workspaceCapabilities),
            'project_capabilities' => $projectEntries,
        ];
    }

    /** @return list<string> */
    private function forProject(User|AgentPrincipal $user, ClientProject $project, Closure $allowsScope): array
    {
        $capabilities = [];
        $role = $this->projects->projectRole($user, $project);
        $client = $this->access->isCompanyMember($user, $project->clientCompany);

        if ($allowsScope(AgentApiScopes::PROJECTS_READ)) {
            $capabilities[] = 'projects:read';
        }
        if ($allowsScope(AgentApiScopes::TASKS_READ)) {
            $capabilities[] = 'tasks:read';
        }
        if ($this->writesEnabled() && $allowsScope(AgentApiScopes::TASKS_WRITE)
            && ($role?->canManageTasks() ?? false)) {
            $capabilities[] = 'tasks:write';
        }
        if ($allowsScope(AgentApiScopes::TIME_READ)
            && ($client || in_array($role, [ProjectRole::Owner, ProjectRole::Manager, ProjectRole::Contributor], true))) {
            $capabilities[] = 'time:read';
        }
        if ($this->timeEntryWritesEnabled() && $allowsScope(AgentApiScopes::TIME_WRITE)
            && in_array($role, [ProjectRole::Owner, ProjectRole::Manager, ProjectRole::Contributor], true)) {
            $capabilities[] = 'time:write';
        }
        if ($this->writesEnabled() && $allowsScope(AgentApiScopes::TIME_APPROVE)
            && ($role?->canApproveTime() ?? false)) {
            $capabilities[] = 'time:approve';
        }

        return $this->ordered($capabilities);
    }

    /** @return list<string> */
    private function managerCapabilities(Closure $allowsScope): array
    {
        $mapping = [
            AgentApiScopes::PROJECTS_READ => 'projects:read',
            AgentApiScopes::TASKS_READ => 'tasks:read',
            AgentApiScopes::TIME_READ => 'time:read',
            AgentApiScopes::BILLING_READ => 'billing:read',
        ];
        if ($this->timeEntryWritesEnabled()) {
            $mapping[AgentApiScopes::TIME_WRITE] = 'time:write';
        }
        if ($this->writesEnabled()) {
            $mapping += [
                AgentApiScopes::TASKS_WRITE => 'tasks:write',
                AgentApiScopes::TIME_APPROVE => 'time:approve',
                AgentApiScopes::BILLING_WRITE => 'billing:write',
                AgentApiScopes::BILLING_DELIVER => 'billing:deliver',
            ];
        }

        $capabilities = [];
        foreach ($mapping as $scope => $capability) {
            if ($allowsScope($scope)) {
                $capabilities[] = $capability;
            }
        }

        return $this->ordered($capabilities);
    }

    /** @param list<string> $capabilities
     * @return list<string> */
    private function ordered(array $capabilities): array
    {
        $available = array_fill_keys(array_unique($capabilities), true);

        return array_values(array_filter(self::ORDER, static fn (string $capability): bool => isset($available[$capability])));
    }

    private function writesEnabled(): bool
    {
        return (bool) config('agent_api.writes_enabled');
    }

    private function timeEntryWritesEnabled(): bool
    {
        return (bool) config('agent_api.time_entry_writes_enabled');
    }
}
