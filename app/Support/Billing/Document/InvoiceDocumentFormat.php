<?php

declare(strict_types=1);

namespace App\Support\Billing\Document;

/** How the document prints a figure, so the template holds no arithmetic. */
final class InvoiceDocumentFormat
{
    /** Minor units as a decimal amount with no symbol: 150000 prints "1,500.00". */
    public static function money(int $minor): string
    {
        return number_format($minor / 100, 2);
    }

    /** Appendix minutes as hours to two places. */
    public static function minutesAsHours(int $minutes): string
    {
        return number_format($minutes / 60, 2);
    }

    /** "1 entry" or "3 entries". */
    public static function entries(int $count): string
    {
        return $count.' '.($count === 1 ? 'entry' : 'entries');
    }
}
