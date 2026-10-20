<?php

namespace App\Support\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientTimeEntry;
use App\Services\Billing\MoneyService;
use DomainException;

/** Whole entries in FIFO order, priced through termination's canonical rule. */
final readonly class DeferredBuyDownSelection
{
    /** @param list<ClientTimeEntry> $entries */
    private function __construct(
        public array $entries,
        public int $minutes,
        public DeferredTerminationPricing $pricing,
        public DeferredBuyDownRequest $request,
    ) {}

    /** @param iterable<ClientTimeEntry> $candidates FIFO by worked_on, id */
    public static function select(ClientAgreement $agreement, iterable $candidates, DeferredBuyDownRequest $request): self
    {
        $ordered = [];
        foreach ($candidates as $entry) {
            if ($entry->minutes_worked < 1) {
                throw new DomainException('Deferred work must state a positive whole number of minutes.');
            }
            $ordered[] = $entry;
        }
        $full = DeferredTerminationPricing::price($ordered, $agreement, $agreement->currency, 'the deferred buy-down');
        // Canonical pricing supplies the groups and rates. Maintain each
        // group's integer-minute total, so amount targets remain linear and
        // preserve aggregate rounding rather than rounding each entry's charge.
        $groups = [];
        foreach ($full->agreementRateEntries as $entry) {
            $groups[$entry->id] = ['agreement', $agreement->hourlyRateAmountOrFail()];
        }
        foreach ($full->flatHourlyGroups as $index => $group) {
            foreach ($group->entries as $entry) {
                $groups[$entry->id] = [$index, $group->unitAmount];
            }
        }
        $groupMinutes = [];
        $groupAmounts = [];
        $selected = [];
        $minutes = 0;
        $amount = 0;
        foreach ($ordered as $entry) {
            if (! isset($groups[$entry->id])) {
                continue;
            }
            [$group, $rate] = $groups[$entry->id];
            $priorMinutes = $groupMinutes[$group] ?? 0;
            $nextGroupMinutes = $priorMinutes + $entry->minutes_worked;
            $nextGroupAmount = MoneyService::hourlyAmount($nextGroupMinutes, $rate);
            $nextAmount = MoneyService::sum($amount, $nextGroupAmount, -($groupAmounts[$group] ?? 0));
            $nextMinutes = $minutes + $entry->minutes_worked;
            $within = $request->minutes !== null ? $nextMinutes <= $request->minutes : $nextAmount <= $request->amount;
            if (! $within && ! $request->roundUp) {
                continue;
            }
            $selected[] = $entry;
            $minutes = $nextMinutes;
            $amount = $nextAmount;
            $groupMinutes[$group] = $nextGroupMinutes;
            $groupAmounts[$group] = $nextGroupAmount;
            if (! $within || ($request->minutes !== null ? $minutes === $request->minutes : $amount === $request->amount)) {
                break;
            }
        }
        $pricing = DeferredTerminationPricing::price($selected, $agreement, $agreement->currency, 'the deferred buy-down');
        if ($request->discount > $pricing->totalAmount()) {
            throw new DomainException('The discount cannot exceed the selected deferred work charge.');
        }

        return new self($selected, $minutes, $pricing, $request);
    }

    public function total(): int
    {
        return MoneyService::sum($this->pricing->totalAmount(), -$this->request->discount);
    }
}
