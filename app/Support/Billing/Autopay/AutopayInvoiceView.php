<?php

declare(strict_types=1);

namespace App\Support\Billing\Autopay;

use App\Support\Billing\InvoicePaymentStatus;

/**
 * What a reader is told about autopay on one invoice. Pure and database-free.
 *
 * An attempt, once made, says everything: a skip, a refusal, or a charge whose
 * result the payment ledger carries. Before any attempt the answer is the
 * eligibility verdict as it will stand on the due date, so "over limit" is
 * shown well ahead of the day it matters.
 */
final class AutopayInvoiceView
{
    /**
     * @param  AutopayVerdict  $onDueDate  the verdict for the invoice's facts moved to its due date
     * @param  InvoicePaymentStatus|null  $payment  the status of the payment the attempt created, when it is known and readable
     */
    public static function classify(AutopayVerdict $onDueDate, ?AutopayAttemptState $attempt, ?InvoicePaymentStatus $payment): AutopayInvoiceState
    {
        if ($attempt !== null) {
            return match ($attempt) {
                AutopayAttemptState::Skipped => AutopayInvoiceState::Skipped,
                AutopayAttemptState::Failed => AutopayInvoiceState::Failed,
                AutopayAttemptState::Claimed => AutopayInvoiceState::NeedsReview,
                AutopayAttemptState::Submitted => self::fromPayment($payment),
            };
        }

        return match ($onDueDate) {
            AutopayVerdict::Charge, AutopayVerdict::NotYetDue, AutopayVerdict::PaymentInFlight => AutopayInvoiceState::WillCharge,
            AutopayVerdict::OverLimit => AutopayInvoiceState::OverLimit,
            AutopayVerdict::NoDefaultMethod => AutopayInvoiceState::NoDefaultMethod,
            AutopayVerdict::NotCollectible,
            AutopayVerdict::NoBalance,
            AutopayVerdict::HiddenFromClient,
            AutopayVerdict::AutopayOff,
            AutopayVerdict::Skipped,
            AutopayVerdict::AlreadyAttempted,
            AutopayVerdict::NoDueDate,
            AutopayVerdict::DueBeforeAutopay,
            AutopayVerdict::SquareCollects,
            AutopayVerdict::CurrencyMismatch,
            AutopayVerdict::ConnectionUnavailable => AutopayInvoiceState::NotApplicable,
        };
    }

    private static function fromPayment(?InvoicePaymentStatus $payment): AutopayInvoiceState
    {
        if ($payment === null) {
            return AutopayInvoiceState::Processing;
        }

        return match ($payment) {
            InvoicePaymentStatus::Pending => AutopayInvoiceState::Processing,
            InvoicePaymentStatus::Succeeded, InvoicePaymentStatus::Refunded, InvoicePaymentStatus::Disputed => AutopayInvoiceState::Charged,
            InvoicePaymentStatus::Failed, InvoicePaymentStatus::Canceled => AutopayInvoiceState::Failed,
        };
    }
}
