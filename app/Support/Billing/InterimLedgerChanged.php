<?php

namespace App\Support\Billing;

use DomainException;

/**
 * The time an interim claim is checked against, or where that time was placed,
 * changed after this transaction's snapshot was taken.
 *
 * The cumulative excess is built from ordinary reads of time entries and of
 * the other invoices, lines and allocations that place it (#718), and a
 * caller whose transaction already read something - the agent API's receipt
 * transaction - reads them through a snapshot `issue()` cannot refresh. When
 * that snapshot disagrees with a current read of the same rows, the check
 * would be answering against time that no longer exists, so the issue is
 * refused instead. Nothing has been written; retrying in a fresh transaction
 * checks the claim against current time.
 */
final class InterimLedgerChanged extends DomainException implements RetryableConflict
{
    public function __construct()
    {
        parent::__construct(
            'Time on this agreement, or the invoices it was billed on, changed while the interim invoice was being '
            .'issued, so its claim could not be checked against current time and it was not issued. Nothing was '
            .'changed. Retry to issue it.',
        );
    }
}
