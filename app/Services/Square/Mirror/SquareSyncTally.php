<?php

namespace App\Services\Square\Mirror;

/** What one mirror sync run saw and changed (#438). */
final class SquareSyncTally
{
    public int $locationsSeen = 0;

    public int $customersSeen = 0;

    public int $customersChanged = 0;

    public int $invoicesSeen = 0;

    public int $invoicesChanged = 0;

    public int $paymentsResolved = 0;

    public bool $truncated = false;

    /** The newest Square `updated_at` seen, as a UTC database timestamp. */
    public ?string $highWaterMark = null;

    /** @param  list<mixed>  $timestamps */
    public function observe(array $timestamps): void
    {
        foreach ($timestamps as $timestamp) {
            if (is_string($timestamp) && ($this->highWaterMark === null || $timestamp > $this->highWaterMark)) {
                $this->highWaterMark = $timestamp;
            }
        }
    }
}
