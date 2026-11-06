<?php

declare(strict_types=1);

namespace App\Support\Submission;

/** Why a submission cannot be withdrawn. */
enum WithdrawRefusal: string
{
    case NotYours = 'not_yours';

    case NotSubmitted = 'not_submitted';

    /** A reviewer or client has approved it. That statement is theirs to change, not the author's. */
    case Approved = 'approved';

    /** Marked ready to bill, so it stays on offer whether or not it was submitted. */
    case ReadyToBill = 'ready_to_bill';

    public function message(): string
    {
        return match ($this) {
            self::NotYours => 'Only the person who submitted this withdraws it.',
            self::NotSubmitted => 'This is not submitted.',
            self::Approved => 'This has been approved, so it can no longer be withdrawn.',
            self::ReadyToBill => 'This is already marked ready to bill, so it stays with the reviewer. A manager can return it to draft.',
        };
    }
}
