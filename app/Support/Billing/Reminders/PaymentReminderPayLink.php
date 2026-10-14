<?php

namespace App\Support\Billing\Reminders;

/**
 * Where a reminder sends the client to pay (#438).
 *
 * - `square`: Square's own view-and-pay page for the linked Square invoice,
 *   from the mirror's copy of Square's data. An invoice Square collects gets
 *   this or no reminder at all.
 * - `svc_portal`: the invoice in the client portal. It is a sign-in page with
 *   no bearer token, offered only when the invoice is visible to the client and
 *   the client has a portal user. SVC has no hosted payment page of its own, so
 *   this is where the client reads the invoice, not a checkout.
 * - `none`: no link; the reminder carries the invoice PDF instead.
 */
enum PaymentReminderPayLink: string
{
    case Square = 'square';
    case SvcPortal = 'svc_portal';
    case None = 'none';
}
