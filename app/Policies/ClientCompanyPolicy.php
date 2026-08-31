<?php

namespace App\Policies;

use App\Models\ClientCompany;
use App\Models\User;

class ClientCompanyPolicy
{
    /**
     * Who may open a client's portal.
     *
     * Internal preview is owners and admins only. Any workspace membership
     * used to admit anyone here, which made the portal a way around #157
     * entirely: a member holding one project could paste any company's public
     * id and read that client's whole client-facing record - projects, tasks,
     * approved time, proposals, agreements, invoices and attachments - with no
     * project scoping applied anywhere on the path.
     *
     * Owners and admins already reach every project, so the preview discloses
     * them nothing new. Anyone else has the operator screens for the projects
     * they actually hold.
     */
    public function viewPortal(User $user, ClientCompany $clientCompany): bool
    {
        $workspaceRole = $clientCompany->workspace->memberships()
            ->where('user_id', $user->id)
            ->value('role');

        return in_array((string) $workspaceRole, ['owner', 'admin'], true)
            || $clientCompany->portalUsers()->whereKey($user->id)->exists();
    }
}
