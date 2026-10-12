<?php

namespace App\Support\Square;

/**
 * What SVC accepts as a Square identifier, before it has ever asked Square.
 *
 * Links and mappings are written from what an operator or agent states, so the
 * identifiers are opaque here: checked for shape, never resolved. The shapes
 * are deliberately loose about content and strict about character set and
 * length - an identifier that could carry markup, whitespace or a path is
 * refused, and anything Square could plausibly issue is not.
 *
 * Dashboard recurring series number their invoices `NNNNNN-R-NNNN`: the six
 * digits are the series and the suffix is the occurrence. One-off invoices are
 * `NNNNNN` and belong to no series. The series key is that prefix, derived
 * here rather than taken from whoever supplied the number, so the two cannot
 * disagree.
 */
final class SquareIdentifiers
{
    /** Square object ids: letters, digits and `:`, `_`, `-`; invoices carry a colon. */
    public const string INVOICE_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9:_-]{0,190}$/D';

    public const string CUSTOMER_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,190}$/D';

    /** Printable ASCII, no surrounding space. Square caps an invoice number at 191 characters. */
    public const string INVOICE_NUMBER_PATTERN = '/^[!-~](?:[ -~]{0,189}[!-~])?$/D';

    public const string SERIES_KEY_PATTERN = '/^[0-9]{6}$/D';

    private const string SERIES_NUMBER_PATTERN = '/^([0-9]{6})-R-[0-9]{4}$/D';

    public static function isInvoiceId(string $value): bool
    {
        return preg_match(self::INVOICE_ID_PATTERN, $value) === 1;
    }

    public static function isCustomerId(string $value): bool
    {
        return preg_match(self::CUSTOMER_ID_PATTERN, $value) === 1;
    }

    public static function isInvoiceNumber(string $value): bool
    {
        return preg_match(self::INVOICE_NUMBER_PATTERN, $value) === 1;
    }

    public static function isSeriesKey(string $value): bool
    {
        return preg_match(self::SERIES_KEY_PATTERN, $value) === 1;
    }

    /**
     * The recurring series an invoice number belongs to: the six-digit prefix
     * of a series occurrence (`NNNNNN-R-NNNN`). A one-off (`NNNNNN`) and any
     * other numbering belong to no series and answer null.
     */
    public static function seriesKey(string $invoiceNumber): ?string
    {
        return preg_match(self::SERIES_NUMBER_PATTERN, $invoiceNumber, $matches) === 1 ? $matches[1] : null;
    }
}
