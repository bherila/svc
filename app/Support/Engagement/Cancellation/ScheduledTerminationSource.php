<?php

namespace App\Support\Engagement\Cancellation;

/** Who scheduled an agreement's end. */
enum ScheduledTerminationSource: string
{
    /** A company contact, in `self_serve` mode (CAN-3). */
    case SelfServe = 'self_serve';
    /** An owner or admin approving a contact's request (CAN-4). */
    case Request = 'request';
    /** An owner or admin, directly. */
    case Operator = 'operator';
}
