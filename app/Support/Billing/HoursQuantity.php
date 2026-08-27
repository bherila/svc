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
    public static function format(float $hours): string
    {
        $totalMinutes = (int) round($hours * 60);
        $sign = $totalMinutes < 0 ? '-' : '';
        $totalMinutes = abs($totalMinutes);

        return sprintf('%s%d:%02d', $sign, intdiv($totalMinutes, 60), $totalMinutes % 60);
    }
}
