<?php

namespace App\Services\Engagement;

use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;

class EngagementAuthorization
{
    public function isWorkspaceMember(User $user, Workspace $workspace): bool
    {
        return $workspace->memberships()->where('user_id', $user->id)->exists();
    }

    public function canManage(User $user, Workspace $workspace): bool
    {
        return $workspace->memberships()
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'admin'])
            ->exists();
    }

    public function canViewCompany(User $user, Workspace $workspace, ClientCompany $company): bool
    {
        if ($company->workspace_id !== $workspace->id) {
            return false;
        }

        return $this->isWorkspaceMember($user, $workspace)
            || $company->portalUsers()->whereKey($user->id)->exists();
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
        if ($company->workspace_id !== $workspace->id) {
            throw new EngagementException('The client company does not belong to this workspace.');
        }
    }
}
