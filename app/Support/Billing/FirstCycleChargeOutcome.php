<?php

namespace App\Support\Billing;

/**
 * What an agreement's first billing periods were charged for its recurring
 * items, against what the engine places in them, as
 * {@see FirstCycleChargeFinding} reads it (#685, #695).
 */
enum FirstCycleChargeOutcome: string
{
    /** Issued (or paid, or disputed) charging an item an extra time: an overcharge a client was sent. */
    case IssuedWithDuplicate = 'issued_with_duplicate';

    /** A draft charging an item an extra time. Regenerating it charges as the engine places it; issuing it as it stands would not. */
    case DraftWithDuplicate = 'draft_with_duplicate';

    /** Each item charged no more often than the engine places it. */
    case ChargedOnce = 'charged_once';

    /** No invoice claims the first periods. */
    case NotInvoiced = 'not_invoiced';
}
