<?php

namespace App\Support\Billing;

use DomainException;

/** A caller's older snapshot cannot safely replace and total current lines. */
final class InvoiceChangedDuringGeneration extends DomainException implements RetryableConflict
{
    public function __construct()
    {
        parent::__construct('This invoice changed while its draft was being generated, so nothing was generated. Retry from the current invoice.');
    }
}
