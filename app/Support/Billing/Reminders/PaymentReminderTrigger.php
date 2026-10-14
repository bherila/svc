<?php

namespace App\Support\Billing\Reminders;

/**
 * What asked for a reminder (#438): a person on the website, an agent over
 * REST or MCP on a person's behalf, or the scheduled cadence.
 */
enum PaymentReminderTrigger: string
{
    case Website = 'website';
    case Agent = 'agent';
    case Scheduler = 'scheduler';
}
