<?php

namespace App\Support\Billing;

final readonly class DeferredBuyDownSnapshot
{
    public function __construct(public string $version, public DeferredBuyDownSelection $selection) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['backlog_version' => $this->version, 'currency' => $this->selection->pricing->currency,
            'entry_ids' => array_map(static fn ($entry): string => $entry->public_id, $this->selection->entries),
            'minutes' => $this->selection->minutes, 'hours' => round($this->selection->minutes / 60, 4),
            'amount_before_discount' => $this->selection->pricing->totalAmount(),
            'discount_amount' => $this->selection->request->discount, 'discount_reason' => $this->selection->request->discountReason,
            'total_amount' => $this->selection->total(), 'round_up' => $this->selection->request->roundUp];
    }
}
