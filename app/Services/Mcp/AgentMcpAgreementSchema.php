<?php

namespace App\Services\Mcp;

use App\Support\Billing\BillingCadence;
use App\Support\Billing\FirstCycleProration;

/** The agreement DTO contract, shared by every MCP tool that returns one. */
final class AgentMcpAgreementSchema
{
    /** @return array<string, mixed> */
    public static function dto(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['id', 'title', 'status', 'currency', 'billing_cadence', 'effective_billing_cadence', 'effective_first_cycle_proration', 'is_recurring', 'starts_on', 'ends_on', 'signed_at', 'retainer_minutes_per_period', 'retainer_minutes_per_month', 'retainer_amount_per_period', 'hourly_rate_amount', 'rollover_months', 'project', 'client_id', 'client_name', 'project_id'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'title' => ['type' => 'string', 'maxLength' => 255],
                'status' => ['type' => 'string', 'maxLength' => 64],
                'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3],
                'billing_cadence' => ['type' => ['string', 'null'], 'maxLength' => 32],
                'effective_billing_cadence' => ['type' => ['string', 'null'], 'enum' => [...array_column(BillingCadence::cases(), 'value'), null]],
                'effective_first_cycle_proration' => ['type' => ['string', 'null'], 'enum' => [...array_column(FirstCycleProration::cases(), 'value'), null]],
                'is_recurring' => ['type' => 'boolean'],
                'starts_on' => ['type' => 'string', 'format' => 'date'],
                'ends_on' => ['type' => ['string', 'null'], 'format' => 'date'],
                'signed_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'retainer_minutes_per_period' => ['type' => ['integer', 'null'], 'minimum' => 0],
                'retainer_minutes_per_month' => ['type' => ['integer', 'null'], 'minimum' => 0],
                'retainer_amount_per_period' => ['type' => ['integer', 'null'], 'minimum' => 0],
                'hourly_rate_amount' => ['type' => ['integer', 'null'], 'minimum' => 0],
                'rollover_months' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 120],
                'project' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'client_id' => ['type' => 'string', 'format' => 'uuid'],
                'client_name' => ['type' => 'string', 'maxLength' => 160],
                'project_id' => ['type' => ['string', 'null'], 'format' => 'uuid'],
            ],
        ];
    }
}
