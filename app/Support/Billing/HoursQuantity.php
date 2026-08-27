<?php

namespace App\Support\Billing;

/**
 * Decimal hours rendered as h:mm for an invoice line quantity.
 *
 * Clients read invoices in hours and minutes, so 1.25 has to reach the page as
 * 1:15. Kept in one place because three generators produce these lines and a
 * client comparing two of them would notice the difference immediately.
 */
final class HoursQuantity
{
    /**
     * Hours as a decimal string for the `quantity` column.
     *
     * The predecessor stored `quantity` as a varchar and put `h:mm` straight in
     * it - there is a migration in that codebase changing the column from
     * decimal to varchar for exactly that purpose. This schema kept it decimal,
     * so writing `1:30` here is rejected by MySQL in strict mode. SQLite accepts
     * it, which is why a full green test suite proved nothing about production.
     *
     * The readable form belongs in the description, where it already appears.
     */
    public static function decimal(float $hours): string
    {
        return number_format(round($hours, 4), 4, '.', '');
    }

    /** Hours as `h:mm`, for descriptions and anything a person reads. */
    public static function format(float $hours): string
    {
        $totalMinutes = (int) round($hours * 60);
        $sign = $totalMinutes < 0 ? '-' : '';
        $totalMinutes = abs($totalMinutes);

        return sprintf('%s%d:%02d', $sign, intdiv($totalMinutes, 60), $totalMinutes % 60);
    }
}
