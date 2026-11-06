<?php

declare(strict_types=1);

namespace App\Support\Review;

/** Why a provider may not write a grant, in words the person writing it can act on. */
enum GrantRefusal: string
{
    case OwnWorkspace = 'own_workspace';
    case NoSharedProject = 'no_shared_project';
    case OwnStaff = 'own_staff';
    case HasPortalAccess = 'has_portal_access';

    public function message(): string
    {
        return match ($this) {
            self::OwnWorkspace => 'A workspace cannot review its own hours.',
            self::NoSharedProject => 'Choose a workspace that takes part in one of your shared projects.',
            self::OwnStaff => 'That address belongs to someone on your own team. A client approves, not your staff.',
            self::HasPortalAccess => 'That person already has portal access to this client. Give them the permission to approve there instead.',
        };
    }
}
