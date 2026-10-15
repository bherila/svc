<?php

namespace App\Support\Billing\Reminders;

/**
 * Where a reminder stands (#438).
 *
 * `pending` is registered and not yet claimed; `sending` is claimed and handed
 * to the mailer, and stays so when the provider accepted it but the result
 * could not be saved - an ambiguous outcome nothing resends blindly. `sent` and
 * `failed` are the provider's answer; `cancelled` was refused before anything
 * left, because the invoice or its Square link changed after it was claimed.
 */
enum PaymentReminderStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
