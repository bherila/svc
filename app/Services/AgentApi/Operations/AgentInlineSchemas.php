<?php

namespace App\Services\AgentApi\Operations;

use App\Services\Mcp\AgentMcpAgreementSchema;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;

/**
 * Schemas declared inline for the operations the OpenAPI document does not
 * describe as MCP tools: the historical agreement, billing-schedule, capacity
 * and audit reads, the context resource and the prompts. Every other
 * operation takes its schemas from the document (SchemaRef).
 */
final class AgentInlineSchemas
{
    /** @return array<string, mixed> */
    public static function agreementListInput(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['workspace_id'],
            'properties' => [
                'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
                'status' => ['type' => ['string', 'null'], 'enum' => ['draft', 'active', 'terminated', 'expired', 'paused', null]],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                'cursor' => ['type' => ['string', 'null'], 'maxLength' => 2048],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function agreementGetInput(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['workspace_id', 'agreement_id'],
            'properties' => [
                'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
                'agreement_id' => ['type' => 'string', 'format' => 'uuid'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function agreementOutput(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['data'],
            'properties' => ['data' => AgentMcpAgreementSchema::dto()],
        ];
    }

    /** @return array<string, mixed> */
    public static function billingScheduleListInput(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['workspace_id'],
            'properties' => [
                'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
                'is_active' => ['type' => ['boolean', 'null']],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                'cursor' => ['type' => ['string', 'null'], 'maxLength' => 2048],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function billingScheduleGetInput(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['workspace_id', 'schedule_id'],
            'properties' => [
                'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
                'schedule_id' => ['type' => 'string', 'format' => 'uuid'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function billingScheduleOutput(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['data'],
            'properties' => ['data' => self::billingScheduleDto()],
        ];
    }

    /** @return array<string, mixed> */
    public static function capacityLedgerInput(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['workspace_id', 'agreement_id'],
            'properties' => [
                'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
                'agreement_id' => ['type' => 'string', 'format' => 'uuid'],
                'months' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'default' => 12],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function capacityLedgerOutput(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['data'],
            'properties' => [
                'data' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['agreement_id', 'through', 'months'],
                    'properties' => [
                        'agreement_id' => ['type' => 'string', 'format' => 'uuid'],
                        'through' => ['type' => 'string', 'format' => 'date'],
                        'months' => ['type' => 'array', 'maxItems' => 60, 'items' => self::capacityLedgerMonth()],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function promptOutput(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['user'], 'properties' => ['user' => ['type' => 'string', 'maxLength' => 4096]]];
    }

    /** @return array<string, mixed> */
    public static function billingAuditInput(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['workspace_id'],
            'properties' => ['workspace_id' => ['type' => 'string', 'format' => 'uuid']],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    public static function billingAuditOutput(array $properties): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['data'],
            'properties' => [
                'data' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => array_keys($properties),
                    'properties' => $properties,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function agreementListOutput(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['type' => 'array', 'maxItems' => 100, 'items' => AgentMcpAgreementSchema::dto()],
                'meta' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['next_cursor'],
                    'properties' => ['next_cursor' => ['type' => ['string', 'null'], 'maxLength' => 2048]],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function billingScheduleListOutput(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['type' => 'array', 'maxItems' => 100, 'items' => self::billingScheduleDto()],
                'meta' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['next_cursor'], 'properties' => ['next_cursor' => ['type' => ['string', 'null'], 'maxLength' => 2048]]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function billingScheduleDto(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['id', 'agreement_id', 'version', 'cadence', 'next_run_on', 'is_active'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'agreement_id' => ['type' => 'string', 'format' => 'uuid'],
                'version' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                'cadence' => ['type' => 'string', 'maxLength' => 32],
                'next_run_on' => ['type' => 'string', 'format' => 'date'],
                'is_active' => ['type' => 'boolean'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function capacityLedgerMonth(): array
    {
        $numbers = [
            'retainer_hours', 'hours_worked', 'opening_retainer_hours', 'opening_rollover_hours',
            'opening_expired_hours', 'opening_available_hours', 'hours_used_from_retainer',
            'hours_used_from_rollover', 'unused_hours', 'excess_hours', 'negative_hours',
            'signed_available_hours', 'remaining_rollover_hours',
        ];
        $properties = [
            'period' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}$'],
            'cycle_start' => ['type' => ['string', 'null'], 'format' => 'date'],
            'bill_excess_immediately' => ['type' => 'boolean'],
        ];
        foreach ($numbers as $name) {
            $properties[$name] = ['type' => 'number'];
        }

        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($properties), 'properties' => $properties];
    }

    /**
     * The aggregate counts each billing audit reports.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function billingAuditProperties(string $audit): array
    {
        return match ($audit) {
            'billing.audit_unplaceable_invoices' => [
                'invoices' => ['type' => 'integer', 'minimum' => 0],
                'without_a_service_period' => ['type' => 'integer', 'minimum' => 0],
                'without_a_service_period_start' => ['type' => 'integer', 'minimum' => 0],
                'unplaceable_by_a_period_guard' => ['type' => 'integer', 'minimum' => 0],
                'charged_of_those' => ['type' => 'integer', 'minimum' => 0],
                'on_an_agreement_of_those' => ['type' => 'integer', 'minimum' => 0],
                'affected' => ['type' => 'integer', 'minimum' => 0],
                'overage_hours_at_stake' => ['type' => 'number', 'minimum' => 0],
                'without_a_cycle' => ['type' => 'integer', 'minimum' => 0],
                'of_a_kind_read_by_cycle' => ['type' => 'integer', 'minimum' => 0],
                'live_without_a_cycle' => ['type' => 'integer', 'minimum' => 0],
                'cycle_affected' => ['type' => 'integer', 'minimum' => 0],
                'cycle_overage_hours_at_stake' => ['type' => 'number', 'minimum' => 0],
            ],
            'billing.audit_undated_collectible_invoices' => [
                'invoices' => ['type' => 'integer', 'minimum' => 0],
                'collectible' => ['type' => 'integer', 'minimum' => 0],
                'undated' => ['type' => 'integer', 'minimum' => 0],
                'with_an_issue_date' => ['type' => 'integer', 'minimum' => 0],
                'without_an_issue_date' => ['type' => 'integer', 'minimum' => 0],
                'would_become_overdue_if_backfilled' => ['type' => 'integer', 'minimum' => 0],
                'undated_balances' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer', 'minimum' => 0], 'maxProperties' => 100],
                'would_become_overdue_balances' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer', 'minimum' => 0], 'maxProperties' => 100],
            ],
            'billing.audit_missing_billed_overage' => [
                'invoices' => ['type' => 'integer', 'minimum' => 0],
                'without_a_billed_overage' => ['type' => 'integer', 'minimum' => 0],
                'charged_of_those' => ['type' => 'integer', 'minimum' => 0],
                'on_an_agreement_of_those' => ['type' => 'integer', 'minimum' => 0],
                'agreements_affected' => ['type' => 'integer', 'minimum' => 0],
            ],
            'billing.audit_opening_rollover' => [
                'agreements' => ['type' => 'integer', 'minimum' => 0],
                'with_initial_rollover' => ['type' => 'integer', 'minimum' => 0],
                'legacy_monthly_of_those' => ['type' => 'integer', 'minimum' => 0],
                'affected' => ['type' => 'integer', 'minimum' => 0],
                'capacity_at_stake_minutes' => ['type' => 'integer', 'minimum' => 0],
                'longest_rollover_months' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 120],
            ],
            default => throw new \LogicException("Unknown billing audit: {$audit}"),
        };
    }

    /**
     * A client or agreement tool: the workspace, the declared properties and,
     * for a write, the REST request body merged over them.
     *
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    public static function clientInput(string $operationId, array $properties, array $required = [], bool $write = false): array
    {
        $constraints = [];
        if ($write) {
            $body = AgentApiResponseSchemaCatalog::requestForOperation($operationId);
            $constraints = array_intersect_key($body, ['allOf' => true]);
            $properties = [...$properties, ...$body['properties']];
            $required = [...$required, ...$body['required']];
        }

        return [
            ...$constraints,
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['workspace_id', ...$required],
            'properties' => ['workspace_id' => ['type' => 'string', 'format' => 'uuid'], ...$properties],
        ];
    }
}
