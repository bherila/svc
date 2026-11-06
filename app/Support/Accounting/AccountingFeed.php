<?php

declare(strict_types=1);

namespace App\Support\Accounting;

/** The two accounting feeds, each a cursor listing of immutable entries. */
enum AccountingFeed: string
{
    case InvoiceLines = 'invoice_lines';
    case CashIncome = 'cash_income';

    /** @return list<string> the entry types the feed's `entry_type` filter names */
    public function entryTypes(): array
    {
        return match ($this) {
            self::InvoiceLines => LineEntryType::values(),
            self::CashIncome => CashEntryType::values(),
        };
    }

    /**
     * Whether the date an entry is filtered by can still change after the
     * entry is delivered. A payment's received date can be corrected, so a
     * date filter on the cash feed could drop the very change that moves a
     * payment out of the range; an invoice's issue date, a correction's and a
     * void's recording time cannot.
     */
    public function datesCanChange(): bool
    {
        return match ($this) {
            self::InvoiceLines => false,
            self::CashIncome => true,
        };
    }
}
