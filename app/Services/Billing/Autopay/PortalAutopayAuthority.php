<?php

namespace App\Services\Billing\Autopay;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\User;

/**
 * May this person authorize charges to the client's card, or withdraw that
 * authorization, from the portal?
 *
 * Only a contact of the client, with company-wide portal access. Workspace
 * staff who are previewing the portal are not the client and cannot consent
 * for them - the provider has its own, attested route - and a contact limited
 * to some projects does not speak for the company's money.
 */
final class PortalAutopayAuthority
{
    public function may(ClientCompany $company, User $viewer): bool
    {
        return ClientCompanyMembership::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('user_id', $viewer->id)
            ->where('access_scope', ClientCompanyMembership::SCOPE_COMPANY)
            ->exists();
    }
}
