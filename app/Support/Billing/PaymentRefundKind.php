<?php

namespace App\Support\Billing;

/**
 * What one row of a hand-recorded payment's refund history records (#472).
 *
 * A `refund` is money returned to the client outside SVC. A `reversal` answers
 * one refund that was recorded in error - the money never left - and names it;
 * the refund it reverses is never edited or deleted, so the history keeps both.
 */
enum PaymentRefundKind: string
{
    case Refund = 'refund';
    case Reversal = 'reversal';
}
