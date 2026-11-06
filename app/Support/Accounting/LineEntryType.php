<?php

declare(strict_types=1);

namespace App\Support\Accounting;

/**
 * What an entry of the invoice-line feed records. Issued lines are never
 * edited: a correction or a void is a new entry that refers to the entry it
 * reverses.
 */
enum LineEntryType: string
{
    /** A line of an issued invoice, as it was issued. */
    case InvoiceLine = 'invoice_line';

    /** The change an in-place correction made to a line's money, as a signed delta. */
    case Correction = 'correction';

    /** The whole of a line, reversed by voiding its invoice. */
    case Void = 'void';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    public function label(): string
    {
        return StoredValueLabel::of($this->value);
    }

    /** The fixed rank of this entry type's source in a feed's ordering. */
    public function rank(): int
    {
        return match ($this) {
            self::InvoiceLine => 1,
            self::Correction => 2,
            self::Void => 3,
        };
    }
}
