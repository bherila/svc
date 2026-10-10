<?php

namespace App\Support\Access;

use App\Models\ClientCompanyMembership;

/**
 * How much of a client company's portal one portal membership reaches.
 *
 * The values are the ones {@see ClientCompanyMembership} already stores in
 * `access_scope`.
 */
enum PortalAccessScope: string
{
    /** Every client-visible project the company owns. */
    case Company = 'company';

    /** Only the projects listed in `client_portal_project_access`. */
    case Projects = 'projects';
}
