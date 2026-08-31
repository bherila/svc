<?php

namespace App\Services\Authorization;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * What a portal user is allowed to see inside one client company.
 *
 * This has to be one decision used everywhere, not a filter applied while
 * rendering. Scoping only the project list still leaves every direct resource
 * route company-wide, so a held or guessed attachment URL keeps working for a
 * project the user was never granted.
 *
 * `ClientProjectMembership` cannot express this: it carries a composite foreign
 * key into `workspace_memberships`, which is what stops orphaned project rows
 * granting access, and it means that table describes internal staff. An
 * external portal user is never a workspace member and can never appear in it.
 */
final class PortalAccess
{
    /**
     * Project ids this viewer may see, or null when nothing narrows them.
     *
     * Null means unrestricted, which is different from an empty list: a
     * project-scoped user granted nothing sees nothing.
     *
     * @return list<int>|null
     */
    public function visibleProjectIds(ClientCompany $company, ?User $viewer): ?array
    {
        if (! $viewer instanceof User) {
            return [];
        }

        // Owners and admins see the whole portal, and only they. Any workspace
        // membership used to unlock it, which combined with the policy made
        // the portal a way past every project scope the operator screens apply.
        $workspaceRole = $company->workspace->memberships()
            ->where('user_id', $viewer->id)
            ->value('role');

        if (in_array((string) $workspaceRole, ['owner', 'admin'], true)) {
            return null;
        }

        // Scoped on the workspace as well as the company. The composite key
        // added in #113 makes a membership naming a company of another tenant
        // unstorable, but a database migrated from before it can still hold
        // one, and this read is what would consume it.
        $membership = ClientCompanyMembership::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('user_id', $viewer->id)
            ->first();

        if (! $membership instanceof ClientCompanyMembership) {
            return [];
        }

        if ($membership->access_scope !== ClientCompanyMembership::SCOPE_PROJECTS) {
            return null;
        }

        $ids = DB::table('client_portal_project_access')
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_membership_id', $membership->id)
            ->whereIn('client_project_id', ClientProject::query()
                ->where('client_company_id', $company->id)
                ->select('id'))
            ->pluck('client_project_id')
            ->all();

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }

    /**
     * Narrow a project query to what a portal user may see.
     *
     * The same decision as {@see canViewProject()}, expressed where a list is
     * built rather than where one record is checked. Without it the read API
     * authorised portal users company-wide - a user narrowed to one project
     * could list every client-visible project, task and time entry the company
     * had, which is the hole the narrowing exists to close, on another surface.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $projects  a query over `client_projects`
     * @param  User|AgentPrincipal|null  $viewer  null sees nothing
     * @return Builder<TModel>
     */
    public function constrainProjectQuery(Builder $projects, User|AgentPrincipal|null $viewer): Builder
    {
        if ($viewer === null) {
            return $projects->whereRaw('1 = 0');
        }

        // Portal users reach the read API through an agent principal, which
        // carries the acting user's key. Matching on the key rather than the
        // class is what the surrounding authorization already does; requiring a
        // User here returned nothing for every real portal caller.
        $viewerId = $viewer->getAuthIdentifier();

        // Deliberately not a visibility check. This answers "which projects has
        // this portal user been granted", and each caller adds the visibility
        // rule that belongs to what it is listing - a project or task is shown
        // when the project is client-visible, a time entry when the entry is.
        // Folding visibility in here silently narrowed time entries to
        // client-visible projects, which was never the rule.
        return $projects
            ->whereHas('clientCompany.portalUsers', fn (Builder $users): Builder => $users->whereKey($viewerId))
            ->where(function (Builder $scope) use ($viewerId): void {
                // Unrestricted membership: the whole company's portal.
                // Both subqueries match the membership on its workspace as well
                // as its company. Company ids are globally unique, so joining on
                // the company alone reads a membership that was migrated in
                // before the composite key and now names another tenant.
                $scope->whereExists(function (QueryBuilder $sub) use ($viewerId): void {
                    $sub->selectRaw('1')
                        ->from('client_company_memberships')
                        ->whereColumn('client_company_memberships.client_company_id', 'client_projects.client_company_id')
                        ->whereColumn('client_company_memberships.workspace_id', 'client_projects.workspace_id')
                        ->where('client_company_memberships.user_id', $viewerId)
                        ->where(function (QueryBuilder $unrestricted): void {
                            $unrestricted->whereNull('client_company_memberships.access_scope')
                                ->orWhere('client_company_memberships.access_scope', '!=', ClientCompanyMembership::SCOPE_PROJECTS);
                        });
                })
                    // Or narrowed, and this project is one of the grants.
                    ->orWhereExists(function (QueryBuilder $sub) use ($viewerId): void {
                        $sub->selectRaw('1')
                            ->from('client_portal_project_access')
                            ->join(
                                'client_company_memberships',
                                'client_company_memberships.id',
                                '=',
                                'client_portal_project_access.client_company_membership_id',
                            )
                            ->whereColumn('client_portal_project_access.client_project_id', 'client_projects.id')
                            ->whereColumn('client_portal_project_access.workspace_id', 'client_projects.workspace_id')
                            ->whereColumn('client_company_memberships.workspace_id', 'client_projects.workspace_id')
                            ->where('client_company_memberships.user_id', $viewerId);
                    });
            });
    }

    /**
     * Whether this viewer may reach a specific project at all.
     */
    public function canViewProject(?User $viewer, ClientProject $project): bool
    {
        $company = $project->clientCompany;
        if ($company === null) {
            return false;
        }

        $allowed = $this->visibleProjectIds($company, $viewer);

        return $allowed === null || in_array($project->id, $allowed, true);
    }
}
