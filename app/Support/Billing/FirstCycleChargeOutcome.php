<?php

namespace App\Support\Billing;

/**
 * What an agreement's first billing period was charged for its recurring
 * items, as {@see FirstCycleChargeFinding} reads it (#685).
 */
enum FirstCycleChargeOutcome: string
{
    /** Issued (or paid, or disputed) charging an item twice: an overcharge a client was sent. */
    case IssuedWithDuplicate = 'issued_with_duplicate';

    /** A draft charging an item twice. Regenerating it charges once; issuing it as it stands would not. */
    case DraftWithDuplicate = 'draft_with_duplicate';

    /** Each item charged at most once. */
    case ChargedOnce = 'charged_once';

    /** No invoice claims the first period. */
    case NotInvoiced = 'not_invoiced';
}
