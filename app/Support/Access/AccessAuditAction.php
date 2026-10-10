<?php

namespace App\Support\Access;

/** What an access audit event records. */
enum AccessAuditAction: string
{
    case StaffGranted = 'staff.granted';
    case StaffRoleChanged = 'staff.role_changed';
    case StaffRemoved = 'staff.removed';
    case StaffAssignmentRemoved = 'staff.assignment_removed';
    case ProjectAssignmentChanged = 'project.assignment_changed';
    case PortalGranted = 'portal.granted';
    case PortalScopeChanged = 'portal.scope_changed';
    case PortalRevoked = 'portal.revoked';
}
