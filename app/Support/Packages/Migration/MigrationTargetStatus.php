<?php

namespace App\Support\Packages\Migration;

/** Where one subscriber of a scheduled move stands (MIG-3). Values are part of the agent API. */
enum MigrationTargetStatus: string
{
    /** Not yet applied: before the date, or waiting on something an owner settles (`outcome_code`). */
    case Scheduled = 'scheduled';
    /** The new terms apply from the effective date. */
    case Applied = 'applied';
    /** Not moved, and never will be: `outcome_code` says why. */
    case Skipped = 'skipped';
    /** The move was cancelled before its date (MIG-5). */
    case Cancelled = 'cancelled';
}
