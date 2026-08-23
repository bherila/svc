<?php

namespace App\Services\Mcp;

use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/** Thin adapters over the canonical versioned REST Agent API. */
final class AgentMcpReadTools
{
    public function __construct(private readonly InternalAgentApiTransport $api) {}

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->get('context');
    }

    /** @return array<string, mixed> */
    public function summary(#[Schema(format: 'uuid')] string $workspace_id): array
    {
        return $this->get("workspaces/{$workspace_id}/summary");
    }

    /** @return array<string, mixed> */
    public function projects(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(enum: ['active', 'archived', 'completed'])] ?string $status = null,
        #[Schema(maxLength: 200)] ?string $query = null,
    ): array {
        return $this->get("workspaces/{$workspace_id}/projects", compact('limit', 'cursor', 'status', 'query'));
    }

    /** @return array<string, mixed> */
    public function project(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $project_id): array
    {
        return $this->get("workspaces/{$workspace_id}/projects/{$project_id}");
    }

    /** @return array<string, mixed> */
    public function tasks(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] ?string $project_id = null,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        return $this->get("workspaces/{$workspace_id}/tasks", compact('project_id', 'limit', 'cursor'));
    }

    /** @return array<string, mixed> */
    public function task(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $task_id): array
    {
        return $this->get("workspaces/{$workspace_id}/tasks/{$task_id}");
    }

    /** @return array<string, mixed> */
    public function timeEntries(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] ?string $project_id = null,
        #[Schema(enum: ['draft', 'approved', 'invoiced'])] ?string $status = null,
        #[Schema(format: 'date')] ?string $from = null,
        #[Schema(format: 'date')] ?string $to = null,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        return $this->get("workspaces/{$workspace_id}/time-entries", compact('project_id', 'status', 'from', 'to', 'limit', 'cursor'));
    }

    /** @return array<string, mixed> */
    public function invoices(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(enum: ['draft', 'issued', 'partially_paid', 'paid', 'void'])] ?string $status = null,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        return $this->get("workspaces/{$workspace_id}/invoices", compact('status', 'limit', 'cursor'));
    }

    /** @return array<string, mixed> */
    public function invoice(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $invoice_id): array
    {
        return $this->get("workspaces/{$workspace_id}/invoices/{$invoice_id}");
    }

    /** @param array<string, scalar|null> $query
     *  @return array<string, mixed> */
    private function get(string $path, array $query = []): array
    {
        $response = $this->api->send('GET', $path, $query);
        if ($response->status >= 200 && $response->status < 300 && $response->json !== null) {
            return $response->json;
        }

        throw new ToolCallException(match ($response->status) {
            401 => 'The SVC authorization is no longer valid.',
            403 => 'This connection lacks the required permission.',
            404 => 'The requested SVC resource was not found.',
            429 => 'The SVC API rate limit was reached. Retry later.',
            default => 'The SVC API request could not be completed.',
        });
    }
}
