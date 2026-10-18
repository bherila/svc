<?php

namespace App\Support\Square;

use App\Services\Square\SquareInvoiceSeries;

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
    /**
     * Opaque invoice ids, including legacy leading underscores and padding.
     * 191 ASCII bytes matches link storage and the public link contract; the
     * mirror's 192-byte column is a superset. Never normalize an id.
     * Square documents no invoice-id alphabet: this is SVC's bounded input
     * contract, not a claim that other Square resources use this shape.
     */
    public const int INVOICE_ID_MAX_LENGTH = 191;

    /** Unanchored, for route constraints; every other form derives from it. */
    public const string INVOICE_ID_ROUTE_PATTERN = '[A-Za-z0-9_][A-Za-z0-9:_=\-]{0,190}';

    /** For JSON Schema (OpenAPI and MCP tool inputs). */
    public const string INVOICE_ID_SCHEMA_PATTERN = '^'.self::INVOICE_ID_ROUTE_PATTERN.'$';

    public const string INVOICE_ID_PATTERN = '/'.self::INVOICE_ID_SCHEMA_PATTERN.'/D';

    public const string CUSTOMER_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,190}$/D';

    /** Printable ASCII, no surrounding space. Square caps an invoice number at 191 characters. */
    public const string INVOICE_NUMBER_PATTERN = '/^[!-~](?:[ -~]{0,189}[!-~])?$/D';

    public const string SERIES_KEY_PATTERN = '/^[0-9]{6}$/D';

    /**
     * The one validation rule for a stated Square invoice id: the link
     * controller and the pass-through read service both use it, and MCP
     * reaches them through the same operations.
     *
     * @return list<string>
     */
    public static function invoiceIdRule(): array
    {
        return ['required', 'string', 'max:'.self::INVOICE_ID_MAX_LENGTH, 'regex:'.self::INVOICE_ID_PATTERN];
    }

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
        // One derivation for the whole application: the pass-through reads
        // and the links must never disagree about which series an invoice is in.
        return SquareInvoiceSeries::of($invoiceNumber)['series_key'];
    }
}
