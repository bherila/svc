<?php

namespace App\Support\Billing;

use DomainException;

/**
 * A cadence draft's ledger inputs moved after it was priced (#718): time in
 * its scope, where that time was placed, or the agreement's other invoices.
 * Its figures would charge the ledger as it was. Not retryable as is - the
 * draft has to be regenerated from the current inputs first.
 */
final class PricingBasisChanged extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'The time or invoices this draft was priced from have changed since it was generated, so its figures may '
            .'no longer be right and it was not issued. Regenerate the draft, review it, then issue it.',
        );
    }
}
