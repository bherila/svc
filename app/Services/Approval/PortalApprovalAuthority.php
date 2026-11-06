<?php

namespace App\Services\Approval;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\User;
use App\Support\Approval\ApprovalPermission;

/**
 * May this person approve hours and expenses for this client?
 *
 * A portal membership of this client, in this client's workspace, is the
 * ground: workspace staff who are not also the client's contacts are not the
 * ones paying, and approval is the payer's. On top of it, the membership's own
 * grant decides, and without one the client's billing contact may
 * ({@see ApprovalPermission}).
 *
 * Whether a particular entry may be approved by this person is a second,
 * separate question: nobody approves their own work, however they got in.
 */
final class PortalApprovalAuthority
{
    public function may(ClientCompany $company, User $viewer): bool
    {
        $membership = ClientCompanyMembership::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('user_id', $viewer->id)
            ->first();

        if (! $membership instanceof ClientCompanyMembership) {
            return false;
        }

        return ApprovalPermission::allows(
            $membership->can_approve_time_and_expenses,
            ApprovalPermission::isBillingContact((string) $viewer->email, $company->billing_email),
        );
    }
}
