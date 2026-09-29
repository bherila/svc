<?php

namespace App\Support\Billing;

use DomainException;

/**
 * An interim overage draft cannot be issued, because its overage figure would
 * charge more than the cycle's cumulative excess once the claims already
 * charged are counted.
 *
 * Refused rather than recomputed at issue: the amount on the draft is the one
 * an operator reviewed, and silently changing it at the moment it becomes a
 * charge would issue something nobody looked at. Nothing has been written when
 * this is thrown.
 *
 * `$regenerate` says whether regenerating the draft resolves it - true when the
 * draft is merely stale (a claim before it was charged after it was generated),
 * false when a claim for a *later* month of the cycle has already charged the
 * overage this one would bill, which no regeneration of this month can undo.
 */
final class InterimClaimRefused extends DomainException
{
    public function __construct(string $message, public readonly bool $regenerate)
    {
        parent::__construct($message);
    }
}
