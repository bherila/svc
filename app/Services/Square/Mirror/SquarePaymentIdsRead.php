<?php

namespace App\Services\Square\Mirror;

use Illuminate\Database\Query\Builder;

/**
 * What a queued payment-id lookup saw of its snapshot row (#438): when it
 * was last synced, and the basis and ids it held. A write for that lookup
 * applies only while all three are unchanged, so a slower, older lookup never
 * overwrites a newer one - even one written within the same second.
 */
final readonly class SquarePaymentIdsRead
{
    public function __construct(
        public string $syncedAt,
        public ?string $basis,
        public ?string $paymentIds,
    ) {}

    public function guard(Builder $query): Builder
    {
        return $query->where('synced_at', $this->syncedAt)
            ->where(fn (Builder $basis) => $this->basis === null ? $basis->whereNull('payment_ids_basis') : $basis->where('payment_ids_basis', $this->basis))
            ->where(fn (Builder $ids) => $this->paymentIds === null ? $ids->whereNull('payment_ids') : $ids->where('payment_ids', $this->paymentIds));
    }
}
