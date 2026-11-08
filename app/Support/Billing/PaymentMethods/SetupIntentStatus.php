<?php

declare(strict_types=1);

namespace App\Support\Billing\PaymentMethods;

/**
 * Stripe's SetupIntent statuses, as the portal reads them back after a client
 * confirms the form. Pure and database-free.
 *
 * The match is exhaustive and has no default arm: a status added here must be
 * decided before static analysis passes. A status Stripe sends that is not
 * listed is read as {@see SetupOutcome::Pending}, which saves nothing and tells
 * the client the truth - that it is not confirmed yet.
 */
enum SetupIntentStatus: string
{
    case RequiresPaymentMethod = 'requires_payment_method';
    case RequiresConfirmation = 'requires_confirmation';
    case RequiresAction = 'requires_action';
    case Processing = 'processing';
    case Canceled = 'canceled';
    case Succeeded = 'succeeded';

    public static function outcomeOf(string $stripeStatus): SetupOutcome
    {
        $status = self::tryFrom($stripeStatus);

        return $status === null ? SetupOutcome::Pending : $status->outcome();
    }

    public function outcome(): SetupOutcome
    {
        return match ($this) {
            self::Succeeded => SetupOutcome::Saved,
            self::Processing,
            self::RequiresAction,
            self::RequiresConfirmation => SetupOutcome::Pending,
            self::RequiresPaymentMethod,
            self::Canceled => SetupOutcome::Failed,
        };
    }
}
