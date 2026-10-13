<?php

namespace App\Support\Access;

/** Why an invitation met at sign-in gave nothing. */
enum PortalInvitationNotApplied: string
{
    /** The manager who invited is no longer an owner or admin of the workspace. */
    case InviterLostAuthority = 'inviter_lost_authority';

    /** Every project a project-limited invitation named now belongs to another client, or is gone. */
    case ProjectsMoved = 'projects_moved';

    public function message(): string
    {
        return match ($this) {
            self::InviterLostAuthority => 'The person who sent this invitation can no longer manage access, so nothing was given. Add them again if they should have access.',
            self::ProjectsMoved => 'None of the invited projects belong to this client any more, so nothing was given. Add them again with the projects they should reach.',
        };
    }
}
