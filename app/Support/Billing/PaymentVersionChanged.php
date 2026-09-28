<?php

namespace App\Support\Billing;

use DomainException;

/**
 * The payment changed after the caller read it, so a correction written
 * against that reading could silently overwrite someone else's.
 *
 * Raised under the payment's row lock, before anything is written. The caller
 * re-reads the payment, looks at what changed, and decides again - which is
 * what the agent contract means by a 409.
 */
final class PaymentVersionChanged extends DomainException implements RetryableConflict
{
    public function __construct()
    {
        parent::__construct('The payment changed since it was read. Read it again and retry the correction against its current version. Nothing has been changed.');
    }
}
