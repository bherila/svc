<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProcessorPayout;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A processor payout as the finance listing shows it (S7). Its member
 * payments are the workspace's payments whose `provider_payout_identifier`
 * names it, listed by `finance.invoice_payments.list`.
 *
 * @mixin ProcessorPayout
 */
class FinancePayoutResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'version' => AgentApiVersion::for($this->resource),
            'provider' => $this->provider,
            'provider_payout_identifier' => $this->provider_payout_identifier,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'arrival_date' => $this->arrival_date?->toDateString(),
            'type' => $this->type,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
