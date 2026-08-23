<?php

namespace App\Services\Mcp;

use App\Services\AgentApi\Client\InternalAgentApiTransport;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

final class AgentMcpWriteTools
{
    public function __construct(private readonly InternalAgentApiTransport $api) {}

    /** @param list<array<string, mixed>> $entries
     * @return array<string, mixed> */
    public function timeEntriesLog(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(minItems: 1, maxItems: 20)] array $entries, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->send('POST', "workspaces/{$workspace_id}/time-entries", ['entries' => $entries], $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function timeEntriesUpdate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $entry_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, ?string $worked_on = null, ?int $minutes = null, ?string $description = null): array
    {
        return $this->send('PATCH', "workspaces/{$workspace_id}/time-entries/{$entry_id}", array_filter(compact('expected_version', 'worked_on', 'minutes', 'description'), static fn (mixed $value): bool => $value !== null));
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
    public function tasksUpdate(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $task_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version, ?string $title = null, ?string $description = null, #[Schema(enum: ['open', 'in_progress', 'completed'])] ?string $status = null): array
    {
        return $this->send('PATCH', "workspaces/{$workspace_id}/tasks/{$task_id}", array_filter(compact('expected_version', 'title', 'description', 'status'), static fn (mixed $value): bool => $value !== null));
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
