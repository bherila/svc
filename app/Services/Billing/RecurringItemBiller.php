<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientInvoiceLine;
use App\Support\Billing\BillingCadence;
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
 *
 * An item's charges are a property of the item and its agreement, never of the
 * window a caller asks about: a window only selects which of them it sees, so
 * an invoice, a monthly projection and the sign-up estimate agree.
 */
class RecurringItemBiller
{
    public function __construct(private readonly BillingCycleResolver $cycles = new BillingCycleResolver) {}

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
        // An item ends with its agreement, whether or not it carries an expiry
        // of its own: past the termination date there is nothing left to sell,
        // as there is no retainer. Asked here, not by each caller, so the
        // closing invoice on either cadence path, the cycle a correction
        // reaches, and every projection all stop on the same day - including a
        // cycle that only opens after the end, which then sells nothing.
        $agreementEnd = $agreement->ends_on === null ? null : Carbon::instance($agreement->ends_on)->startOfDay();
        if ($agreementEnd !== null && $agreementEnd->lt($cycleEnd)) {
            $cycleEnd = $agreementEnd;
        }
        if ($cycleEnd->lt($cycleStart)) {
            return [];
        }
        $lines = [];

        /** @var ClientAgreementRecurringItem $item */
        foreach ($agreement->recurringItems as $item) {
            // A deactivated item stops charging. The predecessor soft-deleted
            // instead, so it had nothing equivalent to check.
            if ($item->is_active === false) {
                continue;
            }

            // An item accepted from a proposal carries no effective date; it
            // starts when the agreement it belongs to starts.
            $itemStart = Carbon::instance($item->start_date ?? $agreement->starts_on)->startOfDay();
            $itemEnd = $item->end_date !== null ? Carbon::instance($item->end_date)->startOfDay() : null;

            // Skip if item is entirely outside the cycle
            if ($itemStart->gt($cycleEnd)) {
                continue;
            }
            if ($itemEnd !== null && $itemEnd->lt($cycleStart)) {
                continue;
            }

            $incidences = $this->incidencesInRange($agreement, $item, $cycleStart, $cycleEnd, $itemStart);

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
        ClientAgreement $agreement,
        ClientAgreementRecurringItem $item,
        Carbon $rangeStart,
        Carbon $rangeEnd,
        Carbon $itemStart
    ): array {
        $anchorDay = max(1, min(28, $item->anchor_day ?? 1));
        $itemEnd = $item->end_date !== null ? Carbon::instance($item->end_date)->startOfDay() : null;

        $effectiveStart = $rangeStart->gt($itemStart) ? $rangeStart : $itemStart;
        $effectiveEnd = ($itemEnd !== null && $itemEnd->lt($rangeEnd)) ? $itemEnd : $rangeEnd;
        $onTheCycles = self::followsBillingCycles($agreement, $item);

        return match ($item->charge_cadence) {
            ChargeCadence::Monthly => $this->monthlyIncidences($anchorDay, $effectiveStart, $effectiveEnd, $itemStart),
            ChargeCadence::Quarterly => $onTheCycles
                ? $this->billingCycleIncidences($agreement, $effectiveStart, $effectiveEnd, $itemStart)
                : $this->periodicIncidences(3, $anchorDay, $item->anchor_month, $effectiveStart, $effectiveEnd, $itemStart),
            ChargeCadence::SemiAnnual => $onTheCycles
                ? $this->billingCycleIncidences($agreement, $effectiveStart, $effectiveEnd, $itemStart)
                : $this->periodicIncidences(6, $anchorDay, $item->anchor_month, $effectiveStart, $effectiveEnd, $itemStart),
            ChargeCadence::Annual => $onTheCycles
                ? $this->billingCycleIncidences($agreement, $effectiveStart, $effectiveEnd, $itemStart)
                : $this->periodicIncidences(12, $anchorDay, $item->anchor_month, $effectiveStart, $effectiveEnd, $itemStart),
            ChargeCadence::OneTime => $this->oneTimeIncidence($itemStart, $effectiveStart, $effectiveEnd),
            // An unrecognised cadence must stop here. Silently billing nothing
            // would look identical to an item that simply had no incidence.
            null => throw new \DomainException("Recurring item {$item->id} has an unrecognised charge cadence."),
        };
    }

    /**
     * Does this item charge on its agreement's own billing periods (#685)?
     *
     * An item with no anchor of its own, on the quarterly, semi-annual or
     * annual cadence its agreement bills on - every component of such a
     * package, and every proposal item on its agreement's cadence. Those
     * periods are anchored on the agreement's start, so anchoring the item on
     * the 1st of its start month, with a first charge on its start date, put a
     * second charge inside the first period whenever the start was not the
     * 1st: quarterly from 9 January, 9 January and 1 April. Such an item
     * charges once per period instead, on the day the period starts, exactly
     * as the agreement's price is sold. From the 1st the two rules give the
     * same dates.
     *
     * Not a monthly item, whose periods are calendar months whatever the
     * agreement's start, nor an item on another cadence than its agreement's
     * (an annual licence in a quarterly service), nor one carrying an anchor -
     * a move's successor continuing an older schedule (`ContinuedRecurrence`).
     * Those keep the anchor rule below.
     */
    public static function followsBillingCycles(ClientAgreement $agreement, ClientAgreementRecurringItem $item): bool
    {
        $cadence = $agreement->effectiveBillingCadence();

        return $item->anchor_day === null
            && $item->anchor_month === null
            && $cadence !== BillingCadence::Monthly
            && $item->charge_cadence?->value === $cadence->value;
    }

    /**
     * One charge per agreement billing period, on the day it starts - or, in a
     * period the item starts inside, on the item's start - read from the
     * engine's own period grid ({@see BillingCycleResolver}), so every start
     * day, the 29th to the 31st included, lands where the price does.
     *
     * @return Carbon[]
     */
    private function billingCycleIncidences(ClientAgreement $agreement, Carbon $start, Carbon $end, Carbon $itemStart): array
    {
        $agreementStart = Carbon::instance($agreement->retainerStartsOn())->startOfDay();
        $from = $start->gt($agreementStart) ? $start->copy() : $agreementStart;
        $incidences = [];
        if ($from->gt($end)) {
            return $incidences;
        }

        $cycle = $this->cycles->cycleContaining($agreement, $from);
        while ($cycle->start->lte($end)) {
            $date = ($cycle->start->gt($itemStart) ? $cycle->start : $itemStart)->copy()->startOfDay();
            if ($date->gte($start) && $date->lte($end)) {
                $incidences[] = $date;
            }
            $cycle = $this->cycles->cycleContaining($agreement, $cycle->end->copy()->addDay());
        }

        return $incidences;
    }

    /**
     * Monthly incidences: one per month where the anchor day falls in range.
     *
     * The item's own opening month is special. If it begins on the 10th with an
     * anchor of the 1st, there is no 1st left to bill, so the start date stands
     * in for it and the first charge lands on the 10th.
     *
     * That only applies to the item's genuine first month. Applying it whenever
     * the *window* opens mid-month re-bills an anchor an earlier cycle already
     * covered: an item running since January, billed on a 15 May - 14 August
     * cycle, would charge 15 May for a 1 May incidence that the previous cycle
     * had already issued.
     *
     * @return Carbon[]
     */
    private function monthlyIncidences(int $anchorDay, Carbon $start, Carbon $end, Carbon $itemStart): array
    {
        $incidences = [];
        $cursor = $start->copy()->startOfMonth();
        $isFirst = true;

        while ($cursor->lte($end)) {
            $day = min($anchorDay, (int) $cursor->daysInMonth);
            $date = $cursor->copy()->setDay($day)->startOfDay();

            // Only when this window opens on the item's own start date is the
            // fallback the item's first charge rather than a repeat of one.
            if ($isFirst && $date->lt($start) && $start->isSameDay($itemStart)) {
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
        $unitAmount = (int) round($lineData['amount'] * 100);
        // A three-unit charge must bill three units. The predecessor's items had
        // no quantity, so it hard-coded one.
        $quantity = (float) ($item->quantity ?? 1);
        $totalAmount = (int) round($unitAmount * $quantity);

        $line = new ClientInvoiceLine;
        $line->setRawAttributes([
            'workspace_id' => $item->workspace_id,
            'client_agreement_id' => $item->client_agreement_id,
            'client_agreement_recurring_item_id' => $item->id,
            'description' => $lineData['description'],
            'type' => 'recurring_item',
            'quantity' => number_format($quantity, 3, '.', ''),
            'hours' => null,
            'unit_amount' => $unitAmount,
            'tax_amount' => 0,
            'total_amount' => $totalAmount,
            'line_date' => $lineData['line_date']->toDateString(),
            'sort_order' => $sortOrder,
        ]);

        return $line;
    }
}
