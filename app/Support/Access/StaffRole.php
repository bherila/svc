<?php

namespace App\Support\Access;

/**
 * A person's role on a workspace's staff, as `workspace_memberships.role`
 * stores it.
 *
 * Owners and admins reach every client and project in the workspace; a member
 * reaches only the projects they are assigned to.
 */
enum StaffRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    /** Whether this role reaches every client and project, and may manage access. */
    public function managesWorkspace(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }
}
