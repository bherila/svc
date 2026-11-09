<?php

namespace App\Services\Authorization;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\User;

/**
 * Who speaks for a client company: its portal contacts with company-wide
 * access.
 *
 * The one rule behind every act a client takes for the whole company - saving
 * a card and authorizing autopay, adding a service package and accepting one
 * (ACC-1, ACC-8). Workspace staff previewing the portal are not the client and
 * never qualify, and a contact limited to some projects does not speak for the
 * company. Read fresh every time, so a caller holding a lock re-checks it
 * under that lock (EDGE-3).
 */
final class CompanyContacts
{
    public function isCompanyContact(ClientCompany $company, User $user): bool
    {
        return ClientCompanyMembership::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('user_id', $user->id)
            ->where('access_scope', ClientCompanyMembership::SCOPE_COMPANY)
            ->exists();
    }
}
