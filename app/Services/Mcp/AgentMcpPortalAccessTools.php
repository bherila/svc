<?php

namespace App\Services\Mcp;

use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/** REST and MCP execute identical portal access checks and receipts. */
final class AgentMcpPortalAccessTools
{
    public function __construct(private readonly AgentMcpRestCall $rest, private readonly RequestArguments $arguments) {}

    /** @return array<string, mixed> */
    public function list(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25, #[Schema(maxLength: 2048)] ?string $cursor = null): array
    {
        return $this->rest->call('clients.portal_access.list', ['workspace' => $workspace_id, 'client' => $client_id], query: compact('limit', 'cursor'));
    }

    /** @param list<string> $projects
     * @return array<string, mixed> */
    public function grant(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(format: 'email', maxLength: 255)] string $email, #[Schema(enum: ['company', 'projects'])] string $scope,
        #[Schema] bool $confirm, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request,
        #[Schema(maxItems: 500, items: ['type' => 'string', 'format' => 'uuid'])] array $projects = []): array
    {
        return $this->rest->call('clients.portal_access.grant', ['workspace' => $workspace_id, 'client' => $client_id], compact('email', 'scope', 'confirm') + ($this->arguments->has($request, 'projects') ? ['projects' => $projects] : []), $idempotency_key);
    }

    /** @param list<string> $projects
     * @return array<string, mixed> */
    public function update(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(format: 'uuid')] string $user_id, #[Schema(enum: ['company', 'projects'])] string $scope,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema] bool $confirm, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request,
        #[Schema(maxItems: 500, items: ['type' => 'string', 'format' => 'uuid'])] array $projects = []): array
    {
        return $this->rest->call('clients.portal_access.update', ['workspace' => $workspace_id, 'client' => $client_id, 'user' => $user_id], compact('scope', 'confirm', 'expected_version') + ($this->arguments->has($request, 'projects') ? ['projects' => $projects] : []), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function revoke(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(format: 'uuid')] string $user_id, #[Schema] bool $confirm,
        #[Schema(minLength: 64, maxLength: 64)] string $expected_version,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('clients.portal_access.revoke', ['workspace' => $workspace_id, 'client' => $client_id, 'user' => $user_id], compact('confirm', 'expected_version'), $idempotency_key);
    }
}
