<?php

namespace App\Services\Engagement;

use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Illuminate\Support\Facades\Gate;

class EngagementAuthorization
{
    public function __construct(private readonly WorkspaceAuthorization $workspaceAuthorization) {}

    public function isWorkspaceMember(User $user, Workspace $workspace): bool
    {
        return Gate::forUser($user)->allows('view', $workspace);
    }

    public function canManage(User $user, Workspace $workspace): bool
    {
        return Gate::forUser($user)->allows('manage', $workspace);
    }

    public function canViewCompany(User $user, Workspace $workspace, ClientCompany $company): bool
    {
        if (! $this->workspaceAuthorization->isOwnedBy($workspace, $company)) {
            return false;
        }

        return $this->isWorkspaceMember($user, $workspace)
            || $company->portalUsers()->whereKey($user->id)->exists();
    }

    /**
     * Acceptance signs on the client's behalf, so it is reserved for the client's
     * own portal users — or owner/admin staff recording an offline acceptance.
     * A plain workspace member must not be able to mint a signed agreement.
     */
    public function canActAsClient(User $user, Workspace $workspace, ClientCompany $company): bool
    {
        if (! $this->workspaceAuthorization->isOwnedBy($workspace, $company)) {
            return false;
        }

        return $company->portalUsers()->whereKey($user->id)->exists()
            || $this->canManage($user, $workspace);
    }

    public function assertWorkspace(Workspace $workspace, User $user, bool $manage = false): void
    {
        $allowed = $manage
            ? $this->canManage($user, $workspace)
            : $this->isWorkspaceMember($user, $workspace);

        if (! $allowed) {
            throw new EngagementException('The authenticated user is not authorized for this workspace.');
        }
    }

    public function assertCompany(Workspace $workspace, ClientCompany $company): void
    {
        if (! $this->workspaceAuthorization->isOwnedBy($workspace, $company)) {
            throw new EngagementException('The client company does not belong to this workspace.');
        }
    }
}
