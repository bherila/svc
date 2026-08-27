<?php

namespace App\Services\Authorization;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\User;
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

        // Internal staff see the company's whole portal.
        if ($company->workspace->memberships()->where('user_id', $viewer->id)->exists()) {
            return null;
        }

        $membership = ClientCompanyMembership::query()
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
