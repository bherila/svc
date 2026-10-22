<?php

namespace App\Services\Authorization;

use App\Models\AgentPrincipal;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\AgentApi\ProjectRole;
use Closure;
use Illuminate\Database\Eloquent\Builder;

final class AgentCapabilities
{
    private const array ORDER = [
        'projects:read', 'projects:write', 'tasks:read', 'tasks:write', 'time:read', 'time:write',
        'time:approve', 'billing:read', 'billing:write', 'billing:deliver', 'expenses:read', 'expenses:write', 'payments:read', 'payments:record',
        'finance:read', 'finance:processor_movements:read', 'finance:reconcile', 'clients:read', 'clients:write', 'proposals:read', 'proposals:write', 'proposals:accept', 'files:read', 'files:write',
    ];

    public function __construct(
        private readonly AgentAccess $access,
        private readonly ProjectAccess $projects,
        private readonly PortalAccess $portal,
    ) {}

    /**
     * @return array{capabilities:list<string>,project_capabilities:list<array{project_id:string,role:string,capabilities:list<string>}>}
     */
    public function forWorkspace(User|AgentPrincipal $user, Workspace $workspace, Closure $allowsScope): array
    {
        $projectEntries = [];
        $workspaceCapabilities = [];
        $canDiscloseProjects = $allowsScope(AgentApiScopes::PROJECTS_READ);
        $portalCompanyIds = $this->access->portalCompanyIdsIn($user, $workspace);
        $portalViewer = $portalCompanyIds !== [];
        // Agent context uses the portal audience when this person has portal
        // access, even when the same identity also holds an internal role.
        $viewable = $portalViewer || $this->projects->workspaceRole($user, $workspace) === null
            ? [] : $this->projects->viewableProjectIds($user, $workspace);
        $manager = $viewable === null;
        $roles = $manager || $portalViewer ? collect() : ClientProjectMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->whereIn('client_project_id', $viewable)
            ->get()->keyBy('client_project_id');

        $projects = ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->whereHas('clientCompany', fn (Builder $companies): Builder => $companies->where('workspace_id', $workspace->id))
            ->when(! $manager, fn (Builder $query): Builder => $query->where(fn (Builder $grants): Builder => $grants
                ->whereIn('id', $viewable ?? [])
                ->orWhere(fn (Builder $portal): Builder => $this->portal->constrainProjectQuery(
                    $portal->where('is_visible_to_client', true), $user))))
            ->with(['workspace', 'clientCompany'])
            ->orderBy('id')
            ->get();

        foreach ($projects as $project) {
            $role = $manager ? ProjectRole::Owner : $roles->get($project->id)?->role;
            $capabilities = $this->forProject($role, in_array($project->client_company_id, $portalCompanyIds, true), $allowsScope);
            $workspaceCapabilities = [...$workspaceCapabilities, ...$capabilities];
            if ($canDiscloseProjects) {
                $projectEntries[] = [
                    'project_id' => $project->public_id,
                    'role' => $role->value ?? 'client',
                    'capabilities' => $capabilities,
                ];
            }
        }

        if ($manager) {
            $workspaceCapabilities = [...$workspaceCapabilities, ...$this->managerCapabilities($allowsScope)];
        }
        if ($allowsScope(AgentApiScopes::BILLING_READ)
            && ($manager || $portalCompanyIds !== [])) {
            $workspaceCapabilities[] = 'billing:read';
        }

        if ($allowsScope(AgentApiScopes::PAYMENTS_READ)
            && ($manager || $portalCompanyIds !== [])) {
            $workspaceCapabilities[] = 'payments:read';
        }

        if ($manager || $portalCompanyIds !== []) {
            if ($allowsScope(AgentApiScopes::PROPOSALS_READ)) {
                $workspaceCapabilities[] = 'proposals:read';
            }
            if (AgentWriteCutover::proposals() && $allowsScope(AgentApiScopes::PROPOSALS_READ) && $allowsScope(AgentApiScopes::PROPOSALS_ACCEPT)) {
                $workspaceCapabilities[] = 'proposals:accept';
            }
        }

        return [
            'capabilities' => $this->ordered($workspaceCapabilities),
            'project_capabilities' => $projectEntries,
        ];
    }

    /** @return list<string> */
    private function forProject(?ProjectRole $role, bool $client, Closure $allowsScope): array
    {
        $capabilities = [];

        if ($allowsScope(AgentApiScopes::PROJECTS_READ)) {
            $capabilities[] = 'projects:read';
        }
        if ($allowsScope(AgentApiScopes::EXPENSES_READ) && $role !== null) {
            $capabilities[] = 'expenses:read';
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
            AgentApiScopes::EXPENSES_READ => 'expenses:read',
            AgentApiScopes::CLIENTS_READ => 'clients:read',
            AgentApiScopes::FILES_READ => 'files:read',
            // Finance reconciliation (#457) sits behind no write cutover.
            AgentApiScopes::FINANCE_READ => 'finance:read',
            AgentApiScopes::FINANCE_RECONCILE => 'finance:reconcile',
        ];
        if (AgentWriteCutover::projects()
            && ($allowsScope(AgentApiScopes::CLIENTS_READ) || $allowsScope(AgentApiScopes::PROJECTS_READ))) {
            $mapping[AgentApiScopes::PROJECTS_WRITE] = 'projects:write';
        }
        if (AgentWriteCutover::proposals()
            && ($allowsScope(AgentApiScopes::CLIENTS_READ) || $allowsScope(AgentApiScopes::PROPOSALS_READ))) {
            $mapping[AgentApiScopes::PROPOSALS_WRITE] = 'proposals:write';
        }
        if (AgentWriteCutover::files()) {
            $mapping[AgentApiScopes::FILES_WRITE] = 'files:write';
        }
        if (AgentWriteCutover::expenses()) {
            $mapping[AgentApiScopes::EXPENSES_WRITE] = 'expenses:write';
        }
        if (AgentWriteCutover::clients()) {
            $mapping[AgentApiScopes::CLIENTS_WRITE] = 'clients:write';
        }
        if ($this->timeEntryWritesEnabled()) {
            $mapping[AgentApiScopes::TIME_WRITE] = 'time:write';
        }
        if ($this->writesEnabled()) {
            $mapping += [
                AgentApiScopes::TASKS_WRITE => 'tasks:write',
                AgentApiScopes::TIME_APPROVE => 'time:approve',
            ];
        }
        // Advertised only when the surface behind them exists. A capability
        // list is what an agent plans against, so naming `billing:deliver`
        // while every invoice route answers 404 turns a deliberate cutover
        // into a confusing failure part-way through a workflow (#242).
        if ($this->invoiceWritesEnabled()) {
            $mapping += [
                AgentApiScopes::BILLING_WRITE => 'billing:write',
                AgentApiScopes::BILLING_DELIVER => 'billing:deliver',
            ];
        }

        if (AgentWriteCutover::payments()) {
            $mapping[AgentApiScopes::PAYMENTS_RECORD] = 'payments:record';
        }

        $capabilities = [];
        foreach ($mapping as $scope => $capability) {
            if ($allowsScope($scope)) {
                $capabilities[] = $capability;
            }
        }

        if ($allowsScope(AgentApiScopes::FINANCE_READ)) {
            $capabilities[] = 'finance:processor_movements:read';
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
        return AgentWriteCutover::writes();
    }

    /** Nested inside {@see self::writesEnabled()}, never independent of it. */
    private function invoiceWritesEnabled(): bool
    {
        return AgentWriteCutover::invoices();
    }

    private function timeEntryWritesEnabled(): bool
    {
        return AgentWriteCutover::timeEntries();
    }
}
