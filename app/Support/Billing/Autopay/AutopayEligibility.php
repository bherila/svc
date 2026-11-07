<?php

declare(strict_types=1);

namespace App\Support\Billing\Autopay;

use App\Support\Billing\InvoiceStatus;

/**
 * May this invoice be charged by autopay now? Pure and database-free.
 *
 * Checked in a fixed order so a reader is told the most useful reason first:
 * the invoice itself, then what the client agreed to, then timing, then the
 * rail. An amount exactly at the limit is charged; one cent over is not.
 * A due date today or earlier, on the workspace's own calendar, is due.
 */
final class AutopayEligibility
{
    public static function decide(AutopayFacts $facts): AutopayVerdict
    {
        if (! in_array($facts->invoiceStatus, InvoiceStatus::collectible(), true)) {
            return AutopayVerdict::NotCollectible;
        }
        if ($facts->balance <= 0) {
            return AutopayVerdict::NoBalance;
        }
        if (! $facts->visibleToClient) {
            return AutopayVerdict::HiddenFromClient;
        }
        if (! $facts->autopayOn || $facts->limit === null) {
            return AutopayVerdict::AutopayOff;
        }
        if ($facts->priorAttempt !== null) {
            return match ($facts->priorAttempt) {
                AutopayAttemptState::Skipped => AutopayVerdict::Skipped,
                AutopayAttemptState::Claimed, AutopayAttemptState::Submitted, AutopayAttemptState::Failed => AutopayVerdict::AlreadyAttempted,
            };
        }
        if ($facts->dueOn === null) {
            return AutopayVerdict::NoDueDate;
        }
        // Autopay never reaches back: an invoice that fell due before it was
        // turned on is the client's to pay by hand, not charged without notice.
        if ($facts->autopayEnabledOn === null || $facts->dueOn < $facts->autopayEnabledOn) {
            return AutopayVerdict::DueBeforeAutopay;
        }
        if ($facts->dueOn > $facts->today) {
            return AutopayVerdict::NotYetDue;
        }
        if ($facts->paymentInFlight) {
            return AutopayVerdict::PaymentInFlight;
        }
        if ($facts->squareCollects) {
            return AutopayVerdict::SquareCollects;
        }
        if (! $facts->stripeCanCharge) {
            return AutopayVerdict::ConnectionUnavailable;
        }
        if (strtoupper($facts->invoiceCurrency) !== strtoupper($facts->limitCurrency)) {
            return AutopayVerdict::CurrencyMismatch;
        }
        if (! $facts->hasDefaultMethod) {
            return AutopayVerdict::NoDefaultMethod;
        }
        if ($facts->balance > $facts->limit) {
            return AutopayVerdict::OverLimit;
        }

        return AutopayVerdict::Charge;
    }
}
