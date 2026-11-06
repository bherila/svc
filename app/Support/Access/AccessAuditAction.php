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
    /** A contact's grant to approve hours and expenses was set, denied or returned to the default. */
    case PortalApprovalChanged = 'portal.approval_changed';
    case PortalRevoked = 'portal.revoked';
    /** An invitation for an address with no account here; `subject_user_id` is null. */
    case PortalInvited = 'portal.invited';
    case PortalInvitationResent = 'portal.invitation_resent';
    case PortalInvitationRevoked = 'portal.invitation_revoked';
    /** An invitation met at sign-in whose access could no longer be given. */
    case PortalInvitationNotApplied = 'portal.invitation_not_applied';

    /** A host invited an address to take part in one of its projects; `subject_user_id` is null. */
    case SharedProjectInvited = 'shared_project.invited';
    case SharedProjectInvitationRevoked = 'shared_project.invitation_revoked';
    case SharedProjectInvitationDeclined = 'shared_project.invitation_declined';
    /** A workspace accepted an invitation and now takes part. Recorded in the host and in the participant. */
    case SharedProjectJoined = 'shared_project.joined';
    case SharedProjectParticipantRevoked = 'shared_project.participant_revoked';
    case SharedProjectLeft = 'shared_project.left';
    /** A participant pointed (or stopped pointing) one of its own projects at the share. */
    case SharedProjectLinked = 'shared_project.linked';
    case SharedProjectUnlinked = 'shared_project.unlinked';

    /** A provider workspace let a person or a workspace review one client's hours and expenses. */
    case ReviewerGranted = 'reviewer_grant.granted';
    case ReviewerRevoked = 'reviewer_grant.revoked';
    /** A reviewer opened the queue and was shown rows through a grant. Recorded in the granting workspace. */
    case ReviewerRead = 'reviewer_grant.read';
    /** A reviewer approved rows through a grant. Recorded in the granting workspace. */
    case ReviewerApproved = 'reviewer_grant.approved';
    /** A reviewer disputed a row through a grant. Recorded in the granting workspace. */
    case ReviewerDisputed = 'reviewer_grant.disputed';
}
