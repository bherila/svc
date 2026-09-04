<?php

namespace App\Support\Billing;

enum InvoiceDeliveryStatusOutcome: string
{
    case Recorded = 'recorded';
    case Superseded = 'superseded';
    case Unmatched = 'unmatched';
    case Ambiguous = 'ambiguous';
    case Ignored = 'ignored';
}
