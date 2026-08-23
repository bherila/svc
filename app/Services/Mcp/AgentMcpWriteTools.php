<?php

namespace App\Services\Mcp;

use App\Services\AgentApi\Client\InternalAgentApiTransport;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;

final class AgentMcpWriteTools
{
    public function __construct(
        private readonly InternalAgentApiTransport $api,
        private readonly AgentMcpRequestArguments $requestArguments,
    ) {}

    /** @param list<array<string, mixed>> $entries
     * @return array<string, mixed> */
    public function timeEntriesLog(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(minItems: 1, maxItems: 20)] array $entries, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->send('POST', "workspaces/{$workspace_id}/time-entries", ['entries' => $entries], $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function timeEntriesUpdate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $entry_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        RequestContext $context,
        #[Schema(format: 'date')] ?string $worked_on = null,
        #[Schema(minimum: 1, maximum: 1440)] ?int $minutes = null,
        #[Schema(maxLength: 10000)] ?string $description = null,
        ?bool $is_billable = null,
        ?bool $is_deferred = null,
        ?bool $is_visible_to_client = null,
        #[Schema(maxLength: 10000)] ?string $client_visible_description = null,
    ): array {
        $body = compact('expected_version');
        foreach (compact('worked_on', 'minutes', 'description', 'is_billable', 'is_deferred', 'is_visible_to_client', 'client_visible_description') as $name => $value) {
            if ($this->requestArguments->has($context, $name)) {
                $body[$name] = $value;
            }
        }

        return $this->send('PATCH', "workspaces/{$workspace_id}/time-entries/{$entry_id}", $body);
    }

    /** @return array<string, mixed> */
    public function timeEntriesDelete(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $entry_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version): array
    {
        return $this->send('DELETE', "workspaces/{$workspace_id}/time-entries/{$entry_id}", compact('expected_version'));
    }

    /** @param list<array{id: string, expected_version: string}> $entries
     * @return array<string, mixed> */
    public function timeEntriesApprove(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(minItems: 1, maxItems: 100)] array $entries): array
    {
        return $this->send('POST', "workspaces/{$workspace_id}/time-entries/approve", compact('entries'));
    }

    /** @return array<string, mixed> */
    public function tasksCreate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id, #[Schema(minLength: 1, maxLength: 255)] string $title, ?string $description = null, ?bool $is_visible_to_client = null): array
    {
        return $this->send('POST', "workspaces/{$workspace_id}/projects/{$project_id}/tasks", array_filter(compact('title', 'description', 'is_visible_to_client'), static fn (mixed $value): bool => $value !== null));
    }

    /** @return array<string, mixed> */
    public function tasksUpdate(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $task_id,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        RequestContext $context,
        #[Schema(minLength: 1, maxLength: 255)] ?string $title = null,
        #[Schema(maxLength: 10000)] ?string $description = null,
        #[Schema(enum: ['open', 'in_progress', 'completed'])] ?string $status = null,
        ?bool $is_visible_to_client = null,
    ): array {
        $body = compact('expected_version');
        foreach (compact('title', 'description', 'status', 'is_visible_to_client') as $name => $value) {
            if ($this->requestArguments->has($context, $name)) {
                $body[$name] = $value;
            }
        }

        return $this->send('PATCH', "workspaces/{$workspace_id}/tasks/{$task_id}", $body);
    }

    /** @param list<string> $time_entry_ids
     * @param list<array<string, mixed>> $manual_lines
     * @return array<string, mixed> */
    public function invoicesCreateDraft(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $company_id, #[Schema(maxItems: 100)] array $time_entry_ids = [], #[Schema(maxItems: 100)] array $manual_lines = [], ?string $currency = null, ?string $due_date = null, ?string $notes = null): array
    {
        return $this->send('POST', "workspaces/{$workspace_id}/invoices", array_filter(compact('company_id', 'time_entry_ids', 'manual_lines', 'currency', 'due_date', 'notes'), static fn (mixed $value): bool => $value !== null));
    }

    /** @return array<string, mixed> */
    public function invoicesIssue(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id, #[Schema] bool $confirm, #[Schema(minLength: 64, maxLength: 64)] string $expected_version): array
    {
        return $this->send('POST', "workspaces/{$workspace_id}/invoices/{$invoice_id}/issue", compact('expected_version', 'confirm'));
    }

    /** @param list<string> $recipients
     * @return array<string, mixed> */
    public function invoicesSend(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id, #[Schema] bool $confirm, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minItems: 1, maxItems: 10)] array $recipients): array
    {
        return $this->send('POST', "workspaces/{$workspace_id}/invoices/{$invoice_id}/send", compact('expected_version', 'recipients', 'confirm'));
    }

    /** @return array<string, mixed> */
    public function invoicesVoid(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id, #[Schema] bool $confirm, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, #[Schema(minLength: 1, maxLength: 1000)] string $reason): array
    {
        return $this->send('POST', "workspaces/{$workspace_id}/invoices/{$invoice_id}/void", compact('expected_version', 'reason', 'confirm'));
    }

    /** @param array<string, mixed> $body
     * @return array<string, mixed> */
    private function send(string $method, string $path, array $body, ?string $idempotencyKey = null): array
    {
        // The internal transport copies only bearer auth. Header-derived idempotency
        // is passed as a request field on this adapter's explicit REST boundary.
        $response = $this->api->send($method, $path, json: $body, idempotencyKey: $idempotencyKey);
        if ($response->status >= 200 && $response->status < 300 && $response->json !== null) {
            return $response->json;
        }
        throw new ToolCallException($response->status === 403 ? 'This connection lacks the required permission.' : 'The SVC API request could not be completed.');
    }
}
