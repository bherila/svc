<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ProcessorMovement;
use App\Support\AgentApi\AgentApiVersion;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProcessorMovement */
class FinanceProcessorMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'version' => AgentApiVersion::for($this->resource),
            'payment_id' => $this->payment->public_id,
            'provider' => $this->provider,
            'kind' => $this->kind->value,
            'principal_minor' => $this->principal_minor,
            'fee_minor' => $this->fee_minor,
            'net_minor' => $this->net_minor,
            'currency' => $this->currency,
            'provider_occurred_at' => self::utc($this->resource->getRawOriginal('provider_occurred_at')),
            'occurred_on' => $this->occurred_on->toDateString(),
            'occurred_timezone' => $this->occurred_timezone,
            'date_basis' => $this->date_basis,
            'available_on' => self::utc($this->resource->getRawOriginal('available_on')),
            'provider_payout_identifier' => $this->provider_payout_identifier,
            'needs_review' => $this->needs_review,
            'review_reason' => $this->review_reason,
        ];
    }

    private static function utc(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d\TH:i:s.uP');
    }
}
