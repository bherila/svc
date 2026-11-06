<?php

namespace App\Support\SharedProjects;

use App\Support\Access\StaffRole;

/**
 * Which people of a participant workspace the host may hand a shared task to.
 *
 * The workspace's owners and admins, who answer for its work, and the members
 * it has put on its own local project for this share. A member it has not
 * named is not offered: the host learns nothing about the rest of the
 * workspace's people.
 */
final class AssigneeEligibility
{
    public static function eligible(?StaffRole $role, bool $onLocalProject): bool
    {
        if ($role === null) {
            return false;
        }

        return $role->managesWorkspace() || $onLocalProject;
    }
}
