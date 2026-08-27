<?php

namespace App\Services\Billing\Balances;

use App\Models\ClientTimeEntry;

/**
 * A single deferred time entry being considered by the DeferredBillingAllocator.
 *
 * Wraps a {@see ClientTimeEntry} with a precomputed hours value so the
 * allocator's capacity math is explicit and testable without reaching back
 * into `$entry->minutes_worked / 60` everywhere.
 */
readonly class DeferredEntryCandidate
{
    public function __construct(
        public ClientTimeEntry $entry,
        public float $hours,
    ) {}

    public static function fromEntry(ClientTimeEntry $entry): self
    {
        return new self(
            entry: $entry,
            hours: round($entry->minutes_worked / 60, 4),
        );
    }

    public function id(): int
    {
        return (int) $this->entry->id;
    }
}
