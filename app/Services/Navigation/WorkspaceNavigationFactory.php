<?php

namespace App\Services\Navigation;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Support\Navigation\ClientModuleDestinations;
use App\Support\Navigation\ClientNavigationOption;
use App\Support\Navigation\WorkspaceNavigation;
use App\Support\Navigation\WorkspaceNavigationPermissions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Which clients this viewer may enter, and where each of their modules lives.
 *
 * Two populations reach the same companies by different doors. An operator is a
 * workspace member and reaches a client through the projects they hold (#157);
 * an external portal user is never a workspace member at all and reaches one
 * client company through a portal membership. Both see the same navbar, and
 * that is the point of building the options here: the browser is handed
 * finished URLs, so it never has to know which door was used - and cannot pick
 * the wrong one by assembling a path out of two ids.
 *
 * Where one person holds both, the operator family wins. It is the strictly
 * larger view of the same client, and sending an operator to the client-facing
 * copy of their own workspace would hide work they are responsible for.
 *
 * Cost is bounded by the workspace rather than by what hangs under it: one
 * membership lookup, one project-scope lookup, one portal-membership lookup and
 * one companies query, whatever the number of projects, invoices or tasks.
 */
final class WorkspaceNavigationFactory
{
    public function __construct(private readonly ProjectAccess $projectAccess) {}

    public function for(Workspace $workspace, User $user, ?ClientCompany $current): WorkspaceNavigation
    {
        $isMember = Gate::forUser($user)->allows('view', $workspace);
        $manages = $isMember && Gate::forUser($user)->allows('manage', $workspace);

        // Null means "every company in the workspace" - an owner or admin, who
        // reaches even a company with no projects. An empty list is a different
        // answer, and the two must not collapse: a scoped member granted
        // nothing sees nothing.
        $operatorIds = $isMember
            ? $this->projectAccess->reachableCompanyIds($user, $workspace)
            : [];

        $portalIds = $this->portalCompanyIds($workspace, $user);

        $companies = $this->companies($workspace, $operatorIds, $portalIds);

        $options = [];

        foreach ($companies as $company) {
            $isOperator = $operatorIds === null || in_array((int) $company->id, $operatorIds, true);

            $options[] = new ClientNavigationOption(
                id: (string) $company->public_id,
                name: (string) $company->name,
                destinations: $isOperator
                    ? $this->operatorDestinations($workspace, $company)
                    : $this->portalDestinations($company),
            );
        }

        // The route's company only counts as the current one once it survived
        // the options query. A company the viewer cannot enter must not be
        // echoed back as selected - the switcher would name it, which is the
        // disclosure the scoping exists to prevent.
        $currentId = $current instanceof ClientCompany ? (string) $current->public_id : null;
        $currentIsAnOption = false;

        foreach ($options as $option) {
            if ($option->id === $currentId) {
                $currentIsAnOption = true;
            }
        }

        $currentIsOperated = $currentIsAnOption
            && $current instanceof ClientCompany
            && ($operatorIds === null || in_array((int) $current->id, $operatorIds, true));

        return new WorkspaceNavigation(
            workspaceId: (string) $workspace->public_id,
            workspaceName: (string) $workspace->name,
            currentClientId: $currentIsAnOption ? $currentId : null,
            clients: $options,
            permissions: new WorkspaceNavigationPermissions(
                manageWorkspace: $manages,
                createClient: $manages,
                // Managing a client is an operator act. Holding the client's
                // own portal login is not authority over it, so this stays
                // false for a portal user standing on their own company.
                manageCurrentClient: $manages && $currentIsOperated,
                // The same membership `WorkspaceSearch` searches by, so the
                // trigger appears exactly when the palette can answer.
                search: $isMember,
            ),
            // No workspace settings screen exists yet. Kept in the contract so
            // the account menu has one authorized place to put it, rather than
            // growing a second navigation hierarchy when it lands.
            workspaceSettingsHref: null,
        );
    }

    /**
     * Companies this viewer holds a portal membership for, in this workspace.
     *
     * Matched on the workspace as well as the user. Company ids are globally
     * unique, so a membership migrated in before the composite key added in
     * `2026_08_31_000200_add_composite_tenant_foreign_keys` can name another
     * tenant's company, and this read is what would put its name in a switcher.
     *
     * @return list<int>
     */
    private function portalCompanyIds(Workspace $workspace, User $user): array
    {
        return array_values(array_unique(ClientCompanyMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->pluck('client_company_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all()));
    }

    /**
     * The companies behind both doors, as one alphabetical list.
     *
     * @param  list<int>|null  $operatorIds  null for a viewer who reaches every company
     * @param  list<int>  $portalIds
     * @return Collection<int, ClientCompany>
     */
    private function companies(Workspace $workspace, ?array $operatorIds, array $portalIds): Collection
    {
        $query = $workspace->clientCompanies()->orderBy('name')->orderBy('id');

        if ($operatorIds === null) {
            return $query->get();
        }

        $ids = array_values(array_unique([...$operatorIds, ...$portalIds]));

        if ($ids === []) {
            return $query->whereRaw('1 = 0')->get();
        }

        return $query->whereIn('id', $ids)->get();
    }

    private function operatorDestinations(Workspace $workspace, ClientCompany $company): ClientModuleDestinations
    {
        $parameters = [$workspace, $company];

        return new ClientModuleDestinations(
            home: route('clients.show', $parameters, absolute: false),
            invoices: route('clients.invoices', $parameters, absolute: false),
            time: route('clients.time', $parameters, absolute: false),
            // #75: there is no expense record in the schema yet, so there is no
            // page to link to. Null hides the tab; the tab appears the day this
            // becomes a string, in the commit that gives it something to show.
            expenses: null,
            tasks: route('clients.tasks', $parameters, absolute: false),
        );
    }

    /**
     * The client-facing family.
     *
     * Only the modules the portal serves. A module it does not is null rather
     * than pointed at the operator route: a portal user is not a workspace
     * member, so that link would 403 - and offering it at all tells them a
     * screen exists that is not theirs.
     */
    private function portalDestinations(ClientCompany $company): ClientModuleDestinations
    {
        return new ClientModuleDestinations(
            home: route('portal.show', $company, absolute: false),
            invoices: route('portal.invoices', $company, absolute: false),
            time: route('portal.time', $company, absolute: false),
            expenses: null,
            tasks: route('portal.tasks', $company, absolute: false),
        );
    }
}
