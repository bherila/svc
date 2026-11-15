<?php

namespace App\Support\Billing;

use DomainException;

/**
 * A cadence draft the ledger engine priced before its pricing basis was
 * recorded (#718) - it carries a held-work basis but no pricing basis: what it
 * was priced from cannot be compared with now, so it is regenerated first.
 */
final class PricingBasisMissing extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'This draft was generated before SVC recorded what it was priced from, so its figures cannot be checked '
            .'and it was not issued. Regenerate the draft, review it, then issue it.',
        );
    }
}
