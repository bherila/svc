<?php

namespace App\Services\AgentApi\Operations;

use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\Billing\PeriodRefusalReason;
use App\Support\Billing\ScheduleDefect;

/**
 * Schemas declared inline for the operations the OpenAPI document does not
 * describe as MCP tools: the historical agreement, billing-schedule, capacity
 * and audit reads, the context resource and the prompts. Every other
 * operation takes its schemas from the document (SchemaRef).
 */
final class AgentInlineSchemas
{
    /**
     * The preview takes the creation request's selection, without its write version.
     *
     * @return array<string, mixed>
     */
    public static function deferredBuyDownPreviewInput(): array
    {
        $schema = AgentApiResponseSchemaCatalog::schema('DeferredBuyDownCreateRequest');
        unset($schema['properties']['expected_backlog_version']);
        $schema['required'] = ['workspace_id', 'agreement_id'];
        $schema['properties'] = [
            'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
            'agreement_id' => ['type' => 'string', 'format' => 'uuid'],
            ...$schema['properties'],
        ];

        return $schema;
    }

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
     * The aggregate counts each billing audit reports: the `data` of its
     * response component in the checked OpenAPI document, which
     * BillingAuditOperationContractTest holds to exactly this.
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
            'billing.preflight_schedule_generation' => [
                'schedules' => ['type' => 'integer', 'minimum' => 0],
                'schedules_due' => ['type' => 'integer', 'minimum' => 0],
                'periods_classified' => ['type' => 'integer', 'minimum' => 0],
                'complete' => ['type' => 'boolean'],
                'would_halt' => ['type' => 'integer', 'minimum' => 0],
                'halted_by_a_refusal' => ['type' => 'integer', 'minimum' => 0],
                'halted_by_a_pending_draft' => ['type' => 'integer', 'minimum' => 0],
                'halted_by_a_schedule_defect' => ['type' => 'integer', 'minimum' => 0],
                'schedules_truncated' => ['type' => 'integer', 'minimum' => 0],
                'refusals_by_reason' => self::countsByCase(array_map(static fn (PeriodRefusalReason $reason): string => $reason->value, PeriodRefusalReason::cases())),
                'defects_by_kind' => self::countsByCase(array_map(static fn (ScheduleDefect $defect): string => $defect->value, ScheduleDefect::cases())),
            ],
            'billing.audit_unlinked_proposal_agreements' => [
                'proposals' => ['type' => 'integer', 'minimum' => 0],
                'sent_without_a_linked_agreement' => ['type' => 'integer', 'minimum' => 0],
                'with_an_unlinked_agreement_on_the_company' => ['type' => 'integer', 'minimum' => 0],
                'with_an_active_unlinked_agreement' => ['type' => 'integer', 'minimum' => 0],
                'accepted_without_a_linked_agreement' => ['type' => 'integer', 'minimum' => 0],
                'unlinked_agreements' => ['type' => 'integer', 'minimum' => 0],
            ],
            default => throw new \LogicException("Unknown billing audit: {$audit}"),
        };
    }

    /**
     * A count for every case of an enum, keyed by its value.
     *
     * @param  list<string>  $cases
     * @return array<string, mixed>
     */
    private static function countsByCase(array $cases): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $cases,
            'properties' => array_fill_keys($cases, ['type' => 'integer', 'minimum' => 0]),
        ];
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
