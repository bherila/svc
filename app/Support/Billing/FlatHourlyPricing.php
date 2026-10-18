<?php

namespace App\Support\Billing;

use App\Models\ClientTimeEntry;
use App\Services\Billing\MoneyService;
use RuntimeException;

/**
 * Database-free pricing of flat-hourly subcontractor work at its snapshot.
 *
 * Shared by the period's subcontractor lines, the termination invoice and the
 * deferred-backlog quote, so the figure a reader is shown is the figure the
 * invoice charges.
 */
final class FlatHourlyPricing
{
    /**
     * @param  iterable<ClientTimeEntry>  $entries
     * @return list<FlatHourlyGroup>
     *
     * @throws RuntimeException when an entry has no snapshotted rate, or is
     *                          costed in a currency other than the invoice's
     */
    public static function groups(iterable $entries, string $invoiceCurrency, string $invoiceLabel): array
    {
        /** @var array<string, array{rate: int, currency: string, entries: non-empty-list<ClientTimeEntry>}> $grouped */
        $grouped = [];
        foreach ($entries as $entry) {
            $rate = $entry->subcontractor_cost_amount;
            $currency = $entry->subcontractor_cost_currency ?? '';
            if ($rate === null || trim($currency) === '') {
                throw new RuntimeException('Flat-hourly subcontractor time requires a snapshotted amount and currency.');
            }

            // Group by (user, project, snapshot rate, rate currency) so a
            // mid-period rate change produces correctly-priced separate lines
            // rather than one blended line. The currency belongs in the key
            // rather than only in the refusal below: without it, two entries
            // costed at the same number in different currencies share a group,
            // and the check sees only the first one - so the other currency's
            // minutes bill at this one's rate.
            $key = implode('|', [$entry->user_id, $entry->client_project_id, $rate, $currency]);
            $grouped[$key]['rate'] = $rate;
            $grouped[$key]['currency'] = $currency;
            $grouped[$key]['entries'][] = $entry;
        }

        $groups = [];
        foreach ($grouped as ['rate' => $rateAmount, 'currency' => $costCurrency, 'entries' => $groupEntries]) {
            $totalMinutes = 0;
            foreach ($groupEntries as $entry) {
                $totalMinutes += $entry->minutes;
            }
            if ($totalMinutes <= 0) {
                continue;
            }

            // The cost is stored in minor units of its own currency, so billing
            // it unconverted turns an EUR rate into that many USD cents.
            if ($costCurrency !== $invoiceCurrency) {
                throw new RuntimeException(sprintf(
                    'Subcontractor time is costed in %s but %s is in %s; convert it before billing.',
                    $costCurrency,
                    $invoiceLabel,
                    $invoiceCurrency,
                ));
            }

            $groups[] = new FlatHourlyGroup(
                entries: $groupEntries,
                minutes: $totalMinutes,
                unitAmount: $rateAmount,
                currency: $costCurrency,
                totalAmount: MoneyService::hourlyAmount($totalMinutes, $rateAmount),
            );
        }

        return $groups;
    }
}
