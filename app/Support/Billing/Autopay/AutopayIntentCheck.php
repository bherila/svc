<?php

declare(strict_types=1);

namespace App\Support\Billing\Autopay;

use App\Support\Billing\InvoicePaymentStatus;
use DomainException;

/**
 * Whether a PaymentIntent Stripe returned is the charge a held autopay attempt
 * would have made (#641). Pure and database-free: it takes the intent as the
 * array Stripe sent and the facts of the attempt, and either returns what to
 * record or refuses with the reason an operator can act on.
 *
 * The intent has to name this workspace and this invoice in its metadata, and
 * (when it names an attempt, as every autopay charge does) this attempt; it has
 * to be for the attempt's amount and currency; and Stripe must have charged it
 * or be processing it. Anything else is not the charge the attempt held.
 */
final class AutopayIntentCheck
{
    /** The shape of a PaymentIntent id. */
    public const string ID_PATTERN = '/^pi_[A-Za-z0-9_]{1,252}$/D';

    /**
     * @param  array<string, mixed>  $intent
     *
     * @throws DomainException when the intent is not this attempt's charge
     */
    public static function verify(array $intent, string $requestedId, string $workspacePublicId, string $invoicePublicId, string $attemptPublicId, int $amount, string $currency): AutopayVerifiedIntent
    {
        $id = $intent['id'] ?? null;
        if ($id !== $requestedId) {
            throw new DomainException('Stripe returned a different PaymentIntent than the one asked for.');
        }
        $metadata = is_array($intent['metadata'] ?? null) ? $intent['metadata'] : [];
        if (($metadata['workspace_public_id'] ?? null) !== $workspacePublicId) {
            throw new DomainException('That PaymentIntent does not belong to this workspace.');
        }
        if (($metadata['invoice_public_id'] ?? null) !== $invoicePublicId) {
            throw new DomainException('That PaymentIntent was not created for this invoice.');
        }
        $named = $metadata['autopay_attempt_public_id'] ?? null;
        if ($named !== null && $named !== $attemptPublicId) {
            throw new DomainException('That PaymentIntent belongs to a different autopay attempt.');
        }
        $stripeAmount = $intent['amount'] ?? null;
        if (! is_int($stripeAmount) || $stripeAmount !== $amount) {
            throw new DomainException('That PaymentIntent is not for the amount autopay attempted.');
        }
        $stripeCurrency = $intent['currency'] ?? null;
        if (! is_string($stripeCurrency) || strtoupper($stripeCurrency) !== strtoupper($currency)) {
            throw new DomainException('That PaymentIntent is not in the currency autopay attempted.');
        }
        $status = $intent['status'] ?? null;
        $recorded = match ($status) {
            'succeeded' => InvoicePaymentStatus::Succeeded,
            'processing' => InvoicePaymentStatus::Pending,
            default => throw new DomainException('That PaymentIntent has not charged the client (Stripe says it is '.(is_string($status) ? $status : 'unreadable').'). If nothing was charged, record that instead.'),
        };

        return new AutopayVerifiedIntent($requestedId, $amount, strtoupper($currency), $recorded);
    }
}
