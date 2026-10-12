<?php

namespace App\Services\Square;

/**
 * The recurring series a Square invoice belongs to, from its number (#438).
 *
 * Square's Invoice object carries no series or subscription id. Recurring
 * series created in the Square dashboard number their invoices
 * `NNNNNN-R-NNNN`: the six-digit prefix identifies the series and the suffix
 * counts its occurrences. One-off invoices are numbered `NNNNNN` and belong
 * to no series.
 */
final class SquareInvoiceSeries
{
    private const string RECURRING = '/^(\d{6})-R-(\d{4})\z/';

    /** @return array{series_key: string|null, series_occurrence: int|null} */
    public static function of(?string $invoiceNumber): array
    {
        if ($invoiceNumber === null || preg_match(self::RECURRING, $invoiceNumber, $match) !== 1) {
            return ['series_key' => null, 'series_occurrence' => null];
        }

        return ['series_key' => $match[1], 'series_occurrence' => (int) $match[2]];
    }
}
