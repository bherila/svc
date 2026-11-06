<?php

declare(strict_types=1);

namespace App\Support\Portal;

use App\Support\Billing\InvoicePaymentOptions;

/**
 * Where the portal home's Pay button goes, decided from the same
 * {@see InvoicePaymentOptions} the invoice page offers.
 * Pure and database-free; the result is a finished URL or null, so the browser
 * assembles and interprets nothing.
 *
 * - Card payment (Stripe) is taken on the invoice page, so Pay goes to that
 *   page's pay section. It wins when both are offered, as it is the primary
 *   action there and Square is offered beside it.
 * - Square alone goes straight to its hosted payment page.
 * - Neither: no Pay button. "View invoice" is offered either way.
 */
final class PortalPayTarget
{
    /** The fragment of the invoice page that holds its pay actions. */
    public const string SECTION = 'pay';

    /**
     * @param  array{stripe: array{intent_href: string, publishable_key: string}|null, square: array{href: string}|null}  $options
     */
    public static function href(array $options, string $invoiceHref): ?string
    {
        if ($options['stripe'] !== null) {
            return $invoiceHref.'#'.self::SECTION;
        }

        return $options['square']['href'] ?? null;
    }
}
