<?php

namespace App\Support\Billing;

use DomainException;

/**
 * The invoice moved while a hand-recorded payment change waited for its lock,
 * after the request had already read from an older view of it.
 *
 * Raised under the invoice lock, before anything is written. Retrying reads
 * the invoice afresh - which is what the agent contract means by a 409.
 */
final class InvoiceChangedDuringPaymentChange extends DomainException implements RetryableConflict
{
    public function __construct()
    {
        parent::__construct('The invoice changed while this payment change was waiting. Read the payment again and retry. Nothing has been changed.');
    }
}
