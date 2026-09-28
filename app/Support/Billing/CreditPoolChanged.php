<?php

namespace App\Support\Billing;

use DomainException;

/**
 * The client's overpayment-credit pool changed after this transaction's
 * snapshot was taken, so the credit it was about to spend cannot be trusted.
 *
 * Raised by `issue()` when the company's `credit_revision` read through the
 * snapshot differs from the one read under the company lock. Nothing has been
 * written when it is thrown and the draft is exactly as the operator reviewed
 * it. Retrying in a fresh transaction is always safe: the retry's snapshot
 * starts after the lock, sees the spend or refund that caused this, and caps
 * the draft's credit line accordingly.
 */
final class CreditPoolChanged extends DomainException implements RetryableConflict
{
    public function __construct(public readonly int $snapshotRevision, public readonly int $currentRevision)
    {
        parent::__construct(
            'This client\'s available credit changed while the invoice was being issued, so it was not issued '
            .'and nothing was changed. Retry to issue it against the current credit.',
        );
    }
}
