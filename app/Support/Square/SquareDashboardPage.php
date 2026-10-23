<?php

namespace App\Support\Square;

use App\Services\Square\SquareConfiguration;

/** Operator navigation is separate from the mirrored customer payment URL. */
final class SquareDashboardPage
{
    public static function invoice(string $environment, string $invoiceId): ?string
    {
        $host = match ($environment) {
            SquareConfiguration::PRODUCTION => 'squareup.com',
            SquareConfiguration::SANDBOX => 'squareupsandbox.com',
            default => null,
        };
        if ($host === null || ! SquareIdentifiers::isInvoiceId($invoiceId)) {
            return null;
        }

        return 'https://'.$host.'/dashboard/invoices/'.rawurlencode($invoiceId);
    }
}
