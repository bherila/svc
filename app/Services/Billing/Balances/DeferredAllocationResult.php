<?php

namespace App\Services\Billing\Balances;

use App\Services\Billing\DeferredBillingAllocator;

/**
 * Result of the {@see DeferredBillingAllocator}.
 *
 * `$billed` are the deferred entries that fit within the remaining capacity
 * and will be attached to a single retainer line (or termination line).
 * `$skippedTimeEntryIds` are entries that exist and are deferred but do not
 * fit on this invoice — they roll forward to a future period untouched.
 *
 * @phpstan-type SkippedEntrySummary array{
 *     id: int,
 *     hours: float,
 *     date_worked: string,
 *     name: string|null
 * }
 */
readonly class DeferredAllocationResult
{
    /**
     * @param  list<DeferredEntryCandidate>  $billed
     * @param  list<SkippedEntrySummary>  $skipped
     */
    public function __construct(
        public array $billed,
        public array $skipped,
        public float $hoursBilled,
    ) {}

    public static function empty(): self
    {
        return new self(billed: [], skipped: [], hoursBilled: 0.0);
    }

    public function hasBilled(): bool
    {
        return $this->hoursBilled > 0;
    }
}
