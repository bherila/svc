<?php

namespace App\Services\Mcp;

use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/** Business contacts use the same REST reads, validation and mutation receipts. */
final class AgentMcpClientContactTools
{
    public function __construct(private readonly AgentMcpRestCall $rest, private readonly RequestArguments $arguments) {}

    /** @return array<string, mixed> */
    public function listing(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(enum: ['active', 'archived'])] ?string $status = null, #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null): array
    {
        return $this->rest->call('clients.contacts.list', ['workspace' => $workspace_id, 'client' => $client_id], query: compact('status', 'limit', 'cursor'));
    }

    /** @return array<string, mixed> */
    public function get(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id, #[Schema(format: 'uuid')] string $contact_id): array
    {
        return $this->rest->call('clients.contacts.get', ['workspace' => $workspace_id, 'client' => $client_id, 'contact' => $contact_id]);
    }

    /** @return array<string, mixed> */
    public function create(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, RequestContext $request): array
    {
        return $this->rest->call('clients.contacts.create', ['workspace' => $workspace_id, 'client' => $client_id], $this->fields($request, true), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function update(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(format: 'uuid')] string $contact_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, RequestContext $request): array
    {
        return $this->rest->call('clients.contacts.update', ['workspace' => $workspace_id, 'client' => $client_id, 'contact' => $contact_id], ['expected_version' => $expected_version, ...$this->fields($request, false)], $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function archive(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(format: 'uuid')] string $contact_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key, #[Schema] bool $confirm): array
    {
        return $this->rest->call('clients.contacts.archive', ['workspace' => $workspace_id, 'client' => $client_id, 'contact' => $contact_id], compact('expected_version', 'confirm'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function restore(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(format: 'uuid')] string $contact_id, #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('clients.contacts.restore', ['workspace' => $workspace_id, 'client' => $client_id, 'contact' => $contact_id], compact('expected_version'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    private function fields(RequestContext $request, bool $creating): array
    {
        $fields = ['first_name', 'last_name', 'title', 'email', 'phone', 'notes'];
        if ($creating) {
            $fields[] = 'is_archived';
        }
        $data = [];
        foreach ($fields as $field) {
            if ($this->arguments->has($request, $field)) {
                $data[$field] = $this->arguments->value($request, $field, null);
            }
        }

        return $data;
    }
}
