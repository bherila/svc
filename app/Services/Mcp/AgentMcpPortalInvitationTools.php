<?php

namespace App\Services\Mcp;

use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/** REST and MCP run the same portal invitation boundary and receipts; neither returns the link. */
final class AgentMcpPortalInvitationTools
{
    public function __construct(private readonly AgentMcpRestCall $rest, private readonly RequestArguments $arguments) {}

    /** @return array<string, mixed> */
    public function list(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25, #[Schema(maxLength: 2048)] ?string $cursor = null): array
    {
        return $this->rest->call('clients.portal_invitations.list', ['workspace' => $workspace_id, 'client' => $client_id], query: compact('limit', 'cursor'));
    }

    /** @param list<string> $projects
     * @return array<string, mixed> */
    public function create(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(format: 'email', maxLength: 255)] string $email, #[Schema(enum: ['company', 'projects'])] string $scope,
        #[Schema] bool $confirm, #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
        RequestContext $request,
        #[Schema(maxItems: 500, items: ['type' => 'string', 'format' => 'uuid'])] array $projects = []): array
    {
        return $this->rest->call('clients.portal_invitations.create', ['workspace' => $workspace_id, 'client' => $client_id], compact('email', 'scope', 'confirm') + ($this->arguments->has($request, 'projects') ? ['projects' => $projects] : []), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function resend(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(format: 'uuid')] string $invitation_id, #[Schema] bool $confirm,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('clients.portal_invitations.resend', ['workspace' => $workspace_id, 'client' => $client_id, 'invitation' => $invitation_id], compact('confirm'), $idempotency_key);
    }

    /** @return array<string, mixed> */
    public function revoke(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id,
        #[Schema(format: 'uuid')] string $invitation_id, #[Schema] bool $confirm,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key): array
    {
        return $this->rest->call('clients.portal_invitations.revoke', ['workspace' => $workspace_id, 'client' => $client_id, 'invitation' => $invitation_id], compact('confirm'), $idempotency_key);
    }
}
