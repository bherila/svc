<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PaymentReconciliation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentReconciliation */
class PaymentReconciliationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'external_system' => $this->external_system_slug,
            'external_transaction_id' => $this->external_transaction_uuid,
            'allocated_amount' => $this->allocated_amount,
            'currency' => $this->currency,
            'reconciled_on' => $this->reconciled_on?->toDateString(),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
