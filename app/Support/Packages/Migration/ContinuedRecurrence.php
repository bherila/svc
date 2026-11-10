<?php

namespace App\Support\Packages\Migration;

use App\Services\Billing\RecurringItemBiller;
use App\Services\Packages\PackageAgreementTerms;
use App\Support\Billing\BillingCadence;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The charge schedule a move's successor continues (MIG-3): the recurrence
 * of the agreement it follows, so each recurring item on the new agreement
 * charges on the dates the old one's would have, from its next one on.
 *
 * The billing engine places a recurring item's charges one of two ways
 * ({@see RecurringItemBiller::followsBillingCycles()}). An item with no anchor
 * on its quarterly, semi-annual or annual agreement's cadence - every package
 * component a sign-up writes - charges on the first day of each of the
 * agreement's billing periods (#685). A move takes effect on one of those
 * days ({@see MigrationDates}), and the engine steps each period from the one
 * before, so the successor's periods continue the old ones exactly: its items
 * follow them too, with no anchor ({@see self::$followsBillingCycles}).
 *
 * Any other item - on a monthly agreement, or carrying an anchor (an older
 * successor's) - is placed by its anchor day and month, when unset the 1st and the month the
 * item starts, falling back in the item's own first cycle to a charge on its
 * start date when the anchor has already passed. A successor's items started
 * fresh on the effective date would restart that schedule (Codex P1 on #674),
 * so they carry the old anchors explicitly.
 *
 * Either way the continued items start when the old items did - before the
 * successor itself, whose cycles bound every charge - so no first-charge
 * fallback ever applies.
 *
 * Read from the old agreement's open recurring items on its own cadence
 * (all of them, since a move refuses any off the cycle: the engine places
 * those on the periods too since #695, but counts a longer one's periods
 * from the agreement it is on, so a successor would restart an annual
 * licence's year). Ambiguous - null -
 * when they do not share one schedule, or one has not started by the
 * effective date. With no such item, the schedule a package item started
 * with the agreement has.
 *
 * Pure: the items' facts in, the schedule out. {@see PackageAgreementTerms}
 * writes it.
 */
final readonly class ContinuedRecurrence
{
    private function __construct(
        /** The day the continued items start: the old items' first, before the effective date. */
        public CarbonImmutable $itemsStartOn,
        /** Null when the items follow the billing periods. */
        public ?int $anchorDay,
        /** Null for a monthly schedule, where only the day places a charge, and when the items follow the billing periods. */
        public ?int $anchorMonth,
        /** The old items charge on each billing period's first day, and the continued ones do too. */
        public bool $followsBillingCycles = false,
    ) {}

    /**
     * @param  list<array{cadence: string, effective_on: CarbonInterface|null, anchor_day: int|null, anchor_month: int|null}>  $items  the old agreement's open, active items on its own cadence
     */
    public static function fromItems(BillingCadence $cadence, CarbonInterface $agreementStartsOn, CarbonInterface $effectiveOn, array $items): ?self
    {
        $period = $cadence->monthsInCycle();
        $key = null;
        $anchorDay = null;
        $anchorMonth = null;
        $starts = [];
        foreach ($items === [] ? [['cadence' => $cadence->value, 'effective_on' => null, 'anchor_day' => null, 'anchor_month' => null]] : $items as $item) {
            $start = CarbonImmutable::parse(($item['effective_on'] ?? $agreementStartsOn)->toDateString(), 'UTC');
            if ($item['cadence'] !== $cadence->value || $start->toDateString() >= $effectiveOn->toDateString()) {
                return null;
            }
            if ($period !== 1 && $item['anchor_day'] === null && $item['anchor_month'] === null) {
                // On the billing periods, as the engine places an unanchored
                // item on its agreement's cadence (the cadence is checked above).
                [$day, $month, $itemKey] = [null, null, 'periods'];
            } else {
                // As the engine reads them: the day clamped to 1-28, the month
                // defaulting to the item's start, and only its place in the cycle mattering.
                $day = max(1, min(28, $item['anchor_day'] ?? 1));
                $month = $period === 1 ? null : max(1, min(12, $item['anchor_month'] ?? $start->month));
                $itemKey = $day.'|'.($month === null ? '' : ($month - 1) % $period);
            }
            if ($key !== null && $key !== $itemKey) {
                return null;
            }
            // The first item's month names the place in the cycle; any of them would.
            if ($key === null) {
                [$anchorDay, $anchorMonth, $key] = [$day, $month, $itemKey];
            }
            $starts[] = $start;
        }
        usort($starts, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return new self($starts[0], $anchorDay, $anchorMonth, $key === 'periods');
    }

    /**
     * The columns each continued item carries.
     *
     * @return array{effective_on: string, anchor_day: int|null, anchor_month: int|null}
     */
    public function itemColumns(): array
    {
        return ['effective_on' => $this->itemsStartOn->toDateString(), 'anchor_day' => $this->anchorDay, 'anchor_month' => $this->anchorMonth];
    }
}
