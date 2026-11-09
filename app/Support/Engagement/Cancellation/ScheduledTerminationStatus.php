<?php

namespace App\Support\Engagement\Cancellation;

/** Where an accepted end of an agreement stands (EDGE-9). */
enum ScheduledTerminationStatus: string
{
    /** Accepted: the agreement is active with `ends_on` set to the date, until then. */
    case Scheduled = 'scheduled';
    /** Undone before its date; the agreement's previous end was restored. */
    case Undone = 'undone';
    /** Reached: the agreement was terminated on its date (CAN-5). */
    case Terminated = 'terminated';
}
