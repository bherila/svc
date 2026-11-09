<?php

namespace App\Support\Packages;

use App\Support\Billing\InvoiceStatus;

/**
 * Where a sign-up's own payment stands, for the screens and the agent API.
 * Values are part of the agent API.
 *
 * Read from the sign-up invoice's status, matched exhaustively with no
 * default arm (X-3): a new invoice status fails static analysis here until
 * someone decides what it means for a sign-up.
 */
enum SignupPaymentState: string
{
    /** The version charges nothing at sign-up. */
    case NotCharged = 'not_charged';

    /** It charges, and nothing has been invoiced yet: the offer is not accepted. */
    case NotInvoiced = 'not_invoiced';

    /** The sign-up invoice is issued and something is still owed on it. */
    case Due = 'due';

    case Paid = 'paid';

    /** The sign-up invoice was voided: the enrollment lapsed unpaid (ACC-6). */
    case Voided = 'voided';

    public static function of(bool $chargesAtSignup, ?InvoiceStatus $invoice): self
    {
        if ($invoice === null) {
            return $chargesAtSignup ? self::NotInvoiced : self::NotCharged;
        }

        return match ($invoice) {
            // A sign-up invoice is issued in the transaction that drafts it,
            // so a draft is one that has not been asked for yet.
            InvoiceStatus::Draft => self::NotInvoiced,
            InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Disputed => self::Due,
            InvoiceStatus::Paid => self::Paid,
            InvoiceStatus::Void => self::Voided,
        };
    }
}
