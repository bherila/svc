<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientInvoiceLine;
use App\Support\Billing\ChargeCadence;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Computes the recurring-item invoice lines that fall within a given cycle span.
 *
 * This service is intentionally pure (read-only): it returns line data but does
 * not persist anything. The caller (ClientInvoicingService) is responsible for
 * comparing against existing lines and inserting the missing ones.
 *
 * Idempotence key: (client_agreement_recurring_item_id, line_date).
 */
class RecurringItemBiller
{
    /**
     * Return the recurring-item line descriptors for the given [start, end] cycle span.
     *
     * One entry per incidence. For a monthly item on a quarterly invoice, three entries
     * are returned — one per calendar month in the cycle.
     *
     * @param  CarbonInterface  $start  Inclusive start of the invoice cycle
     * @param  CarbonInterface  $end  Inclusive end of the invoice cycle
     * @return array<int, array{item: ClientAgreementRecurringItem, line_date: Carbon, amount: float, description: string}>
     */
    public function linesForCycle(ClientAgreement $agreement, CarbonInterface $start, CarbonInterface $end): array
    {
        $cycleStart = Carbon::instance($start)->startOfDay();
        $cycleEnd = Carbon::instance($end)->startOfDay();
        $lines = [];

        /** @var ClientAgreementRecurringItem $item */
        foreach ($agreement->recurringItems as $item) {
            $itemStart = Carbon::instance($item->start_date)->startOfDay();
            $itemEnd = $item->end_date ? Carbon::instance($item->end_date)->startOfDay() : null;

            // Skip if item is entirely outside the cycle
            if ($itemStart->gt($cycleEnd)) {
                continue;
            }
            if ($itemEnd !== null && $itemEnd->lt($cycleStart)) {
                continue;
            }

            $incidences = $this->incidencesInRange($item, $cycleStart, $cycleEnd);

            foreach ($incidences as $incidenceDate) {
                $lines[] = [
                    'item' => $item,
                    'line_date' => $incidenceDate,
                    'amount' => (float) $item->charge_amount,
                    'description' => $item->description,
                ];
            }
        }

        return $lines;
    }

    /**
     * Return the incidence dates (the dates on which this item should be billed)
     * that fall within [$rangeStart, $rangeEnd] inclusive, respecting the item's
     * own [start_date, end_date] window.
     *
     * @return Carbon[]
     */
    private function incidencesInRange(
        ClientAgreementRecurringItem $item,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): array {
        $anchorDay = max(1, min(28, $item->anchor_day ?? 1));
        $itemStart = Carbon::instance($item->start_date)->startOfDay();
        $itemEnd = $item->end_date ? Carbon::instance($item->end_date)->startOfDay() : null;

        $effectiveStart = $rangeStart->gt($itemStart) ? $rangeStart : $itemStart;
        $effectiveEnd = ($itemEnd !== null && $itemEnd->lt($rangeEnd)) ? $itemEnd : $rangeEnd;

        return match ($item->charge_cadence) {
            ChargeCadence::Monthly => $this->monthlyIncidences($anchorDay, $effectiveStart, $effectiveEnd),
            ChargeCadence::Quarterly => $this->periodicIncidences(3, $anchorDay, $item->anchor_month, $effectiveStart, $effectiveEnd, $itemStart),
            ChargeCadence::SemiAnnual => $this->periodicIncidences(6, $anchorDay, $item->anchor_month, $effectiveStart, $effectiveEnd, $itemStart),
            ChargeCadence::Annual => $this->periodicIncidences(12, $anchorDay, $item->anchor_month, $effectiveStart, $effectiveEnd, $itemStart),
            ChargeCadence::OneTime => $this->oneTimeIncidence($itemStart, $effectiveStart, $effectiveEnd),
            // An unrecognised cadence must stop here. Silently billing nothing
            // would look identical to an item that simply had no incidence.
            null => throw new \DomainException("Recurring item {$item->id} has an unrecognised charge cadence."),
        };
    }

    /**
     * Monthly incidences: one per month where the anchor day falls in range.
     *
     * For the first month where the computed anchor date is before the effective
     * start, the effective start date is used as the incidence date instead, so
     * the first billing always falls on or after item start_date.
     *
     * @return Carbon[]
     */
    private function monthlyIncidences(int $anchorDay, Carbon $start, Carbon $end): array
    {
        $incidences = [];
        $cursor = $start->copy()->startOfMonth();
        $isFirst = true;

        while ($cursor->lte($end)) {
            $day = min($anchorDay, (int) $cursor->daysInMonth);
            $date = $cursor->copy()->setDay($day)->startOfDay();

            // For the first incidence, if the anchor date is before the item's
            // effective start, bill on the start date instead.
            if ($isFirst && $date->lt($start)) {
                $date = $start->copy();
            }

            if ($date->gte($start) && $date->lte($end)) {
                $incidences[] = $date;
            }

            $isFirst = false;
            $cursor->addMonth()->startOfMonth();
        }

        return $incidences;
    }

    /**
     * Periodic incidences (quarterly / semi-annual / annual).
     *
     * The anchor month determines which month within the year the incidence
     * falls. The period (in months) determines how often it repeats.
     *
     * @return Carbon[]
     */
    private function periodicIncidences(
        int $periodMonths,
        int $anchorDay,
        ?int $anchorMonth,
        Carbon $start,
        Carbon $end,
        Carbon $itemStart
    ): array {
        // Default anchor month to the item start month when not explicitly set
        $month = $anchorMonth ?? (int) $itemStart->month;
        $month = max(1, min(12, $month));

        $incidences = [];

        // Find the first incidence year (may be before $start — we'll filter)
        $year = (int) $start->year - 1;
        $hasEmitted = false;

        while (true) {
            $date = Carbon::create($year, $month, min($anchorDay, 28))->startOfDay();

            if ($date->gt($end)) {
                break;
            }

            if ($date->gte($start)) {
                $incidences[] = $date->copy();
                $hasEmitted = true;
            } elseif (! $hasEmitted && $start->eq($itemStart) && $date->year === $itemStart->year) {
                $nextDate = $date->copy()->addMonths($periodMonths);
                if ($nextDate->gt($start)) {
                    $incidences[] = $start->copy();
                    $hasEmitted = true;
                }
            }

            // Advance by the period
            $date->addMonths($periodMonths);
            $year = (int) $date->year;
            $month = (int) $date->month;

            // Prevent infinite loop (should not happen in practice)
            if ($year > (int) $end->year + 2) {
                break;
            }
        }

        return $incidences;
    }

    /**
     * One-time incidence: billed exactly once at item start_date.
     *
     * @return Carbon[]
     */
    private function oneTimeIncidence(Carbon $itemStart, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        if ($itemStart->gte($rangeStart) && $itemStart->lte($rangeEnd)) {
            return [$itemStart->copy()];
        }

        return [];
    }

    /**
     * Build a ClientInvoiceLine (unsaved) for a recurring item incidence.
     *
     * The caller is responsible for setting client_invoice_id and persisting.
     *
     * @param  array{item: ClientAgreementRecurringItem, line_date: Carbon, amount: float, description: string}  $lineData
     */
    public function buildLine(array $lineData, int $sortOrder = 0): ClientInvoiceLine
    {
        $item = $lineData['item'];

        // Column names and units differ from the predecessor here because this
        // builds a real line: amounts are integer minor units, and the type
        // column is `type`. The incidence arithmetic above is untouched.
        $amount = (int) round($lineData['amount'] * 100);

        $line = new ClientInvoiceLine;
        $line->setRawAttributes([
            'workspace_id' => $item->workspace_id,
            'client_agreement_id' => $item->client_agreement_id,
            'client_agreement_recurring_item_id' => $item->id,
            'description' => $lineData['description'],
            'type' => 'recurring_item',
            'quantity' => '1',
            'hours' => null,
            'unit_amount' => $amount,
            'tax_amount' => 0,
            'total_amount' => $amount,
            'line_date' => $lineData['line_date']->toDateString(),
            'sort_order' => $sortOrder,
        ]);

        return $line;
    }
}
