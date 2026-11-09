<?php

namespace App\Support\Packages;

use App\Models\ClientInvoice;
use App\Support\Billing\InvoiceStatus;

/**
 * What an accepted sign-up still waits for before the version's activation
 * rule applies (ACC-4, ACC-5), and so where the enrollment goes now.
 *
 * Pure: the caller reads whether the sign-up invoice is paid and whether the
 * company's autopay meets the version's terms, and passes them in. A sign-up
 * that charges without `pay_before_activation` does not wait for the money:
 * the invoice is simply outstanding, as any other invoice is.
 */
final class SignupGate
{
    /** @return list<SignupWait> what the enrollment still waits for, in a stable order */
    public static function waitingFor(PackageTerms $terms, bool $invoicePaid, bool $autopayReady): array
    {
        $waits = [];
        if ($terms->payBeforeActivation && $terms->chargesAtSignup() && ! $invoicePaid) {
            $waits[] = SignupWait::Payment;
        }
        if ($terms->requireAutopay && ! $autopayReady) {
            $waits[] = SignupWait::Autopay;
        }

        return $waits;
    }

    /**
     * Whether a sign-up invoice asks for nothing more: none was issued, it is
     * paid, or it is charged with nothing left owing (credit covered it).
     */
    public static function invoiceSettled(?ClientInvoice $invoice): bool
    {
        if (! $invoice instanceof ClientInvoice) {
            return true;
        }

        return $invoice->status === InvoiceStatus::Paid->value
            || (InvoiceStatus::hasChargedValue($invoice->status) && (int) $invoice->balance_amount === 0);
    }

    /** Where an accepted enrollment goes now: `awaiting_payment` while it waits, else by its activation mode. */
    public static function next(PackageTerms $terms, bool $invoicePaid, bool $autopayReady): EnrollmentStatus
    {
        return self::waitingFor($terms, $invoicePaid, $autopayReady) === []
            ? $terms->activationMode->statusOnceAccepted()
            : EnrollmentStatus::AwaitingPayment;
    }
}
