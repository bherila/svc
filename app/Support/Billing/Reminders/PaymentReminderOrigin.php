<?php

namespace App\Support\Billing\Reminders;

/** Whether a person asked for a reminder or the cadence sent it (#438). */
enum PaymentReminderOrigin: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
}
