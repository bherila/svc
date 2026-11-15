<?php

namespace App\Support\Billing;

use DomainException;

/**
 * A time entry billing was about to split or merge changed after billing read
 * it (#718).
 *
 * Generation prices time it reads through its transaction's snapshot and then
 * rewrites the rows - a split into the part a pool absorbs and the overflow, a
 * merge of fragments back into one - from those figures. A time writer takes
 * the row's lock, not the agreement's, so it can commit in between; rewriting
 * from the snapshot would put back the minutes it replaced and charge them.
 * Nothing has been written; retrying prices the time as it is now.
 */
final class TimeChangedDuringBilling extends DomainException implements RetryableConflict
{
    public function __construct()
    {
        parent::__construct(
            'Time on this client changed while its invoice was being generated, so nothing was generated. '
            .'Retry to generate it from the current time.',
        );
    }
}
