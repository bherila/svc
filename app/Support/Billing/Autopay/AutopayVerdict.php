<?php

namespace App\Support\Billing\Autopay;

/** The answer to "may this invoice be charged by autopay now?". */
enum AutopayVerdict: string
{
    case Charge = 'charge';
    case NotCollectible = 'not_collectible';
    case NoBalance = 'no_balance';
    case HiddenFromClient = 'hidden_from_client';
    case AutopayOff = 'autopay_off';
    case Skipped = 'skipped';
    case AlreadyAttempted = 'already_attempted';
    case NoDueDate = 'no_due_date';
    case DueBeforeAutopay = 'due_before_autopay';
    case NotYetDue = 'not_yet_due';
    case PaymentInFlight = 'payment_in_flight';
    case SquareCollects = 'square_collects';
    case ConnectionUnavailable = 'connection_unavailable';
    case CurrencyMismatch = 'currency_mismatch';
    case NoDefaultMethod = 'no_default_method';
    case OverLimit = 'over_limit';

    public function charges(): bool
    {
        return $this === self::Charge;
    }
}
