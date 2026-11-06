<?php

declare(strict_types=1);

namespace App\Support\Billing;

/**
 * What a client may be offered to pay an invoice with, decided from facts the
 * caller has already read. Pure and database-free.
 *
 * Both options are finished values or null, so the browser interprets no
 * boolean and assembles no URL:
 *
 * - **Square**: the hosted payment page of the linked Square invoice. Offered
 *   whenever there is one, including for an invoice Square collects.
 * - **Stripe**: the route that creates a payment intent, and the publishable
 *   key the browser confirms it with. Offered only when the workspace's own
 *   Stripe connection is active, the deployment can confirm a payment in the
 *   browser, and Square does not collect this invoice - SVC never asks for
 *   money Square is already collecting.
 *
 * Nothing is offered for an invoice that is not payable: a draft, a dispute, a
 * void, a paid one, or one the client may not see.
 */
final class InvoicePaymentOptions
{
    /**
     * @return array{stripe: array{intent_href: string, publishable_key: string}|null, square: array{href: string}|null}
     */
    public static function compose(
        bool $payable,
        ?string $squareUrl,
        bool $squareCollects,
        bool $stripeConnected,
        ?string $publishableKey,
        string $intentHref,
    ): array {
        if (! $payable) {
            return ['stripe' => null, 'square' => null];
        }

        return [
            'stripe' => ! $squareCollects && $stripeConnected && $publishableKey !== null && $publishableKey !== ''
                ? ['intent_href' => $intentHref, 'publishable_key' => $publishableKey]
                : null,
            'square' => $squareUrl === null ? null : ['href' => $squareUrl],
        ];
    }
}
