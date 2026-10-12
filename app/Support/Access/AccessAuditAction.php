<?php

namespace App\Support\Access;

/** What an access audit event records. */
enum AccessAuditAction: string
{
    /** An account created for a provider identity, to be granted staff access. */
    case StaffAccountProvisioned = 'staff.account_provisioned';
    case StaffGranted = 'staff.granted';
    case StaffRoleChanged = 'staff.role_changed';
    case StaffRemoved = 'staff.removed';
    case StaffAssignmentRemoved = 'staff.assignment_removed';
    case ProjectAssignmentChanged = 'project.assignment_changed';
    case PortalGranted = 'portal.granted';
    case PortalScopeChanged = 'portal.scope_changed';
    case PortalRevoked = 'portal.revoked';
    /** An invitation for an address with no account here; `subject_user_id` is null. */
    case PortalInvited = 'portal.invited';
    case PortalInvitationResent = 'portal.invitation_resent';
    case PortalInvitationRevoked = 'portal.invitation_revoked';
    /** An invitation met at sign-in whose access could no longer be given. */
    case PortalInvitationNotApplied = 'portal.invitation_not_applied';
}
