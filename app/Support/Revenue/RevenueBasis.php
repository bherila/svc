<?php

namespace App\Support\Revenue;

/**
 * Which money the past months count (docs/ux-overhaul.md section 2a).
 *
 * The projection is always on the invoiced basis: a forecast of collections
 * would need a payment-lag model SVC does not have, so a collected-basis read
 * says so in its assumptions rather than mixing bases silently.
 */
enum RevenueBasis: string
{
    /** Issued, partially paid, paid and disputed invoices, by service month. */
    case Invoiced = 'invoiced';

    /** Payments net of refunds, by received date. */
    case Collected = 'collected';
}
