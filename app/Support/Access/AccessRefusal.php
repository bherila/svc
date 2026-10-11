<?php

namespace App\Support\Access;

/**
 * Why an access mutation was refused, as a stable code a caller can map to its
 * own response without parsing a message.
 */
enum AccessRefusal: string
{
    /** The actor may not make this change, or is no longer allowed to. */
    case NotAuthorized = 'not_authorized';

    /** Workspace ownership is never created, changed or removed here. */
    case OwnerProtected = 'owner_protected';

    /** An actor may not change or remove their own staff membership. */
    case SelfChange = 'self_change';

    /** The person already has a staff membership; change its role instead. */
    case AlreadyStaff = 'already_staff';

    /** The person has no staff membership in this workspace. */
    case NotStaff = 'not_staff';

    /** The stored staff role is not one this boundary knows how to reason about. */
    case UnsupportedRole = 'unsupported_role';

    /** The person already has a portal membership for this company; change its scope instead. */
    case AlreadyPortalMember = 'already_portal_member';

    /** The person has no portal membership for this company. */
    case NotPortalMember = 'not_portal_member';

    /** The portal contact changed since the agent read it. */
    case PortalRevisionConflict = 'portal_revision_conflict';

    /** A project limit names a project outside the company or workspace. */
    case ProjectOutsideCompany = 'project_outside_company';

    /** Company-wide portal access carries no project limits. */
    case ProjectsWithCompanyScope = 'projects_with_company_scope';

    public function message(): string
    {
        return match ($this) {
            self::NotAuthorized => 'You are not allowed to make this access change.',
            self::OwnerProtected => 'Workspace ownership cannot be granted, changed or removed here.',
            self::SelfChange => 'You cannot change or remove your own staff access.',
            self::AlreadyStaff => 'That person already has staff access; change their role instead.',
            self::NotStaff => 'That person has no staff access in this workspace.',
            self::UnsupportedRole => 'That staff membership has a role this change cannot reason about.',
            self::AlreadyPortalMember => 'That person already has portal access to this client; change its scope instead.',
            self::NotPortalMember => 'That person has no portal access to this client.',
            self::PortalRevisionConflict => 'Portal access changed. Read it again before confirming.',
            self::ProjectOutsideCompany => 'One or more projects do not belong to that client.',
            self::ProjectsWithCompanyScope => 'Company-wide portal access cannot also be limited to projects.',
        };
    }
}
