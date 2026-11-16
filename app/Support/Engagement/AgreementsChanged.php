<?php

namespace App\Support\Engagement;

use App\Support\Billing\RetryableConflict;
use DomainException;

/**
 * A company's agreements changed after this transaction's snapshot was taken,
 * so a decision about which of them are active or overlap cannot be trusted
 * (#718).
 *
 * Raised under the company lock when the company's `agreement_revision` read
 * through the snapshot differs from the one read through the lock: an
 * activation, an active agreement's date edit, an undone scheduled end or an
 * accepted proposal committed in between, and the overlap check would not see
 * it. Nothing has been written. Retrying in a fresh transaction reads the
 * agreements as they are now.
 */
final class AgreementsChanged extends DomainException implements RetryableConflict
{
    public function __construct()
    {
        parent::__construct(
            'This client\'s agreements changed while this was being decided, so nothing was changed. Retry to decide it against the current agreements.',
        );
    }
}
