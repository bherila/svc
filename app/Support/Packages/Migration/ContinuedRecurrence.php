<?php

namespace App\Support\Packages\Migration;

use App\Services\Packages\PackageAgreementTerms;
use App\Support\Billing\BillingCadence;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The charge schedule a move's successor continues (MIG-3): the recurrence
 * of the agreement it follows, so each recurring item on the new agreement
 * charges on the dates the old one's would have, from its next one on.
 *
 * The billing engine places a recurring item's charges by its anchor day and
 * month - when unset, the 1st and the month the item starts - and, in the
 * item's own first cycle, falls back to a charge on its start date when the
 * anchor has already passed. A successor's items started fresh on the
 * effective date would therefore restart the schedule: a quarterly service
 * started Nov 9 charges Nov 9, Feb 1, May 1; moved on Feb 9, a fresh item
 * charged Feb 9 and May 1 in the first new quarter (Codex P1 on #674). So the
 * successor's items carry the old schedule's anchors explicitly and start
 * when the old items did - before the successor itself, whose cycles bound
 * every charge - so no first-charge fallback ever applies.
 *
 * Read from the old agreement's open recurring items on its own cadence
 * (all of them, since a move refuses any off the cycle). Ambiguous - null -
 * when they do not share one schedule, or one has not started by the
 * effective date. With no such item, the schedule a package item started
 * with the agreement would have had.
 *
 * Pure: the items' facts in, the schedule out. {@see PackageAgreementTerms}
 * writes it.
 */
final readonly class ContinuedRecurrence
{
    private function __construct(
        /** The day the continued items start: the old items' first, before the effective date. */
        public CarbonImmutable $itemsStartOn,
        public int $anchorDay,
        /** Null for a monthly schedule, where only the day places a charge. */
        public ?int $anchorMonth,
    ) {}

    /**
     * @param  list<array{cadence: string, effective_on: CarbonInterface|null, anchor_day: int|null, anchor_month: int|null}>  $items  the old agreement's open, active items on its own cadence
     */
    public static function fromItems(BillingCadence $cadence, CarbonInterface $agreementStartsOn, CarbonInterface $effectiveOn, array $items): ?self
    {
        $period = $cadence->monthsInCycle();
        $key = null;
        $anchorMonth = null;
        $starts = [];
        foreach ($items === [] ? [['cadence' => $cadence->value, 'effective_on' => null, 'anchor_day' => null, 'anchor_month' => null]] : $items as $item) {
            $start = CarbonImmutable::parse(($item['effective_on'] ?? $agreementStartsOn)->toDateString(), 'UTC');
            if ($item['cadence'] !== $cadence->value || $start->toDateString() >= $effectiveOn->toDateString()) {
                return null;
            }
            // As the engine reads them: the day clamped to 1-28, the month
            // defaulting to the item's start, and only its place in the cycle mattering.
            $day = max(1, min(28, $item['anchor_day'] ?? 1));
            $month = $period === 1 ? null : max(1, min(12, $item['anchor_month'] ?? $start->month));
            $phase = $month === null ? null : ($month - 1) % $period;
            if ($key !== null && $key !== [$day, $phase]) {
                return null;
            }
            // The first item's month names the place in the cycle; any of them would.
            $anchorMonth = $key === null ? $month : $anchorMonth;
            $key = [$day, $phase];
            $starts[] = $start;
        }
        usort($starts, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return new self($starts[0], $key[0], $anchorMonth);
    }

    /**
     * The columns each continued item carries.
     *
     * @return array{effective_on: string, anchor_day: int, anchor_month: int|null}
     */
    public function itemColumns(): array
    {
        return ['effective_on' => $this->itemsStartOn->toDateString(), 'anchor_day' => $this->anchorDay, 'anchor_month' => $this->anchorMonth];
    }
}
