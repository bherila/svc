<?php

namespace App\Services\Billing\Autopay;

use App\Models\ClientCompany;
use App\Models\User;
use App\Services\Authorization\CompanyContacts;

/**
 * May this person authorize charges to the client's card, or withdraw that
 * authorization, from the portal?
 *
 * Only a contact of the client, with company-wide portal access
 * ({@see CompanyContacts}). Workspace staff who are previewing the portal are
 * not the client and cannot consent for them - the provider has its own,
 * attested route - and a contact limited to some projects does not speak for
 * the company's money.
 */
final class PortalAutopayAuthority
{
    public function __construct(private readonly CompanyContacts $contacts = new CompanyContacts) {}

    public function may(ClientCompany $company, User $viewer): bool
    {
        return $this->contacts->isCompanyContact($company, $viewer);
    }
}
