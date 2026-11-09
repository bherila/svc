<?php

namespace App\Support\Engagement\Cancellation;

/** What the scheduled termination run did with one scheduled end (CAN-5). */
enum TerminationRunOutcome: string
{
    case Terminated = 'terminated';
    /** Not due on the workspace's calendar, or already undone or carried out. */
    case NotDue = 'not_due';
}
