<?php

namespace App\Services\Mcp;

use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;

/** REST and MCP run the same portal invitation boundary and receipts; neither returns the link. */
final class AgentMcpPortalInvitationTools
{
    public function __construct(private readonly InternalAgentApiTransport $api, private readonly RequestArguments $arguments) {}

    /** @return array<string, mixed> */
    public function list(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25, #[Schema(maxLength: 2048)] ?string $cursor = null): array
    {
        return $this->send('GET', "workspaces/{$workspace_id}/clients/{$client_id}/portal-invitations", query: compact('limit', 'cursor'));
    }

    /** @param list<string> $projects
     * @return array<string, mixed> */
    public function create(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(format: 'email', maxLength: 255)] string $email, #[Schema(enum: ['company', 'projects'])] string $scope,
        #[Schema] bool $confirm, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request,
        #[Schema(maxItems: 500, items: ['type' => 'string', 'format' => 'uuid'])] array $projects = []): array
    {
        return $this->send('POST', "workspaces/{$workspace_id}/clients/{$client_id}/portal-invitations", compact('email', 'scope', 'confirm') + ($this->arguments->has($request, 'projects') ? ['projects' => $projects] : []), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function resend(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(format: 'uuid')] string $invitation_id, #[Schema] bool $confirm,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->send('POST', "workspaces/{$workspace_id}/clients/{$client_id}/portal-invitations/{$invitation_id}/resend", compact('confirm'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function revoke(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(format: 'uuid')] string $invitation_id, #[Schema] bool $confirm,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->send('DELETE', "workspaces/{$workspace_id}/clients/{$client_id}/portal-invitations/{$invitation_id}", compact('confirm'), $idempotency_key);
    }

    /** @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @return array<string, mixed> */
    private function send(string $method, string $path, array $body = [], ?string $key = null, array $query = []): array
    {
        $response = $this->api->send($method, $path, query: $query, json: $body, headers: $key === null ? [] : ['Idempotency-Key' => $key]);
        if ($response->status >= 200 && $response->status < 300 && $response->json !== null) {
            return $response->json;
        }
        throw new ToolCallException(AgentMcpApiFailure::message($response->status, $response->json));
    }
}
