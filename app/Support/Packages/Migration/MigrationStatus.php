<?php

namespace App\Support\Packages\Migration;

/** Where a scheduled move stands (MIG-1, MIG-3, MIG-5). Values are part of the agent API. */
enum MigrationStatus: string
{
    /** Waiting for its effective date, or for a subscriber it holds. */
    case Scheduled = 'scheduled';
    /** Every subscriber in it was moved or skipped. */
    case Completed = 'completed';
    /** Cancelled before its effective date (MIG-5). */
    case Cancelled = 'cancelled';
}
