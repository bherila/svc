<?php

namespace App\Services\Mcp;

/** Closed response envelopes shared with the public Agent API serializers. */
final class AgentMcpOutputSchemaFactory
{
    /** @return array<string, mixed> */
    public function for(AgentMcpToolDefinition $definition): array
    {
        return match ($definition->operationId) {
            'context.get' => $this->object(['data' => $this->context()], ['data']),
            'operations.summary' => $this->object(['data' => $this->summary()], ['data']),
            'projects.get' => $this->object(['data' => $this->project(true)], ['data']),
            'tasks.get' => $this->object(['data' => $this->task()], ['data']),
            'invoices.get' => $this->object(['data' => $this->invoice(true)], ['data']),
            'projects.list' => $this->page($this->project()),
            'tasks.list' => $this->page($this->task()),
            'time_entries.list' => $this->page($this->timeEntry()),
            'invoices.list' => $this->page($this->invoice()),
            default => throw new \InvalidArgumentException("Unknown MCP operation [{$definition->operationId}]."),
        };
    }

    /** @param array<string, mixed> $item
     *  @return array<string, mixed> */
    private function page(array $item): array
    {
        return $this->object(['data' => ['type' => 'array', 'items' => $item], 'meta' => $this->object(['next_cursor' => ['type' => ['string', 'null']]], ['next_cursor'])], ['data', 'meta']);
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        $workspace = $this->object([
            'id' => $this->id(), 'name' => ['type' => 'string'], 'timezone' => ['type' => 'string'],
            'default_currency' => ['type' => 'string'], 'workspace_role' => ['type' => ['string', 'null']],
            'capabilities' => ['type' => 'array', 'items' => ['type' => 'string']],
            'web_url' => ['type' => 'string', 'format' => 'uri'],
        ], ['id', 'name', 'timezone', 'default_currency', 'workspace_role', 'capabilities', 'web_url']);

        return $this->object([
            'id' => $this->id(), 'name' => ['type' => 'string'],
            'workspaces' => ['type' => 'array', 'items' => $workspace],
        ], ['id', 'name', 'workspaces']);
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        return $this->object(['workspace_id' => $this->id(), 'active_projects' => ['type' => 'integer'], 'time' => $this->object(['draft_minutes' => ['type' => 'integer'], 'approved_minutes' => ['type' => 'integer'], 'unbilled_minutes' => ['type' => 'integer']], ['draft_minutes', 'approved_minutes', 'unbilled_minutes']), 'invoices' => $this->object(['draft_count' => ['type' => 'integer'], 'overdue_count' => ['type' => 'integer'], 'outstanding_amount' => ['type' => 'integer'], 'currency' => ['type' => 'string']], ['draft_count', 'overdue_count', 'outstanding_amount', 'currency'])], ['workspace_id', 'active_projects', 'time', 'invoices']);
    }

    /** @return array<string, mixed> */
    private function project(bool $detail = false): array
    {
        $schema = $this->object(['id' => $this->id(), 'company_id' => $this->id(), 'name' => ['type' => 'string'], 'description' => ['type' => ['string', 'null']], 'status' => ['type' => 'string'], 'is_visible_to_client' => ['type' => 'boolean'], 'version' => ['type' => 'string'], 'web_url' => ['type' => 'string', 'format' => 'uri']], ['id', 'company_id', 'name', 'description', 'status', 'is_visible_to_client', 'version', 'web_url']);
        if ($detail) {
            $schema['properties']['tasks'] = ['type' => 'array', 'items' => $this->task()];
            $schema['required'][] = 'tasks';
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private function task(): array
    {
        return $this->object(['id' => $this->id(), 'project_id' => $this->id(), 'title' => ['type' => 'string'], 'description' => ['type' => ['string', 'null']], 'status' => ['type' => 'string'], 'is_visible_to_client' => ['type' => 'boolean'], 'completed_at' => ['type' => ['string', 'null'], 'format' => 'date-time'], 'version' => ['type' => 'string'], 'web_url' => ['type' => 'string', 'format' => 'uri']], ['id', 'project_id', 'title', 'description', 'status', 'is_visible_to_client', 'completed_at', 'version', 'web_url']);
    }

    /** @return array<string, mixed> */
    private function timeEntry(): array
    {
        return $this->object(['id' => $this->id(), 'project_id' => $this->id(), 'task_id' => ['type' => ['string', 'null'], 'format' => 'uuid'], 'worked_on' => ['type' => 'string', 'format' => 'date'], 'minutes' => ['type' => 'integer'], 'description' => ['type' => ['string', 'null']], 'is_billable' => ['type' => 'boolean'], 'is_deferred' => ['type' => 'boolean'], 'status' => ['type' => 'string'], 'version' => ['type' => 'string'], 'web_url' => ['type' => 'string', 'format' => 'uri'], 'billing_rate_amount' => ['type' => 'integer'], 'currency' => ['type' => 'string']], ['id', 'project_id', 'task_id', 'worked_on', 'minutes', 'description', 'is_billable', 'is_deferred', 'status', 'version', 'web_url']);
    }

    /** @return array<string, mixed> */
    private function invoice(bool $detail = false): array
    {
        $schema = $this->object(['id' => $this->id(), 'company_id' => $this->id(), 'invoice_number' => ['type' => 'string'], 'status' => ['type' => 'string'], 'currency' => ['type' => 'string'], 'total_amount' => ['type' => 'integer'], 'paid_amount' => ['type' => 'integer'], 'balance_amount' => ['type' => 'integer'], 'issue_date' => ['type' => ['string', 'null'], 'format' => 'date'], 'due_date' => ['type' => ['string', 'null'], 'format' => 'date'], 'version' => ['type' => 'string'], 'web_url' => ['type' => 'string', 'format' => 'uri'], 'pdf_url' => ['type' => 'string', 'format' => 'uri'], 'notes' => ['type' => ['string', 'null']]], ['id', 'company_id', 'invoice_number', 'status', 'currency', 'total_amount', 'paid_amount', 'balance_amount', 'issue_date', 'due_date', 'version', 'web_url', 'pdf_url']);
        if ($detail) {
            $schema['properties']['lines'] = ['type' => 'array', 'items' => $this->object(['id' => $this->id(), 'type' => ['type' => 'string'], 'description' => ['type' => 'string'], 'quantity' => ['type' => 'string'], 'unit_amount' => ['type' => 'integer'], 'tax_amount' => ['type' => 'integer'], 'total_amount' => ['type' => 'integer']], ['id', 'type', 'description', 'quantity', 'unit_amount', 'tax_amount', 'total_amount'])];
            $schema['required'][] = 'lines';
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private function id(): array
    {
        return ['type' => 'string', 'format' => 'uuid'];
    }

    /** @param array<string, mixed> $properties
     * @param list<string> $required
     * @return array<string, mixed> */
    private function object(array $properties, array $required): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => $required, 'properties' => $properties];
    }
}
