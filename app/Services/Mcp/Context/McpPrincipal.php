<?php

namespace App\Services\Mcp\Context;

use App\Models\AgentPrincipal;

/**
 * Immutable authentication facts established before an MCP capability runs.
 *
 * Capability arguments must never be used to fill any of these fields. The
 * resolver deliberately keeps credential/client identity alongside the local
 * subject so sessions, idempotency, and audit records cannot be rebound to a
 * different OAuth connection later.
 *
 * @param  list<string>  $scopes
 */
final readonly class McpPrincipal
{
    public function __construct(
        public AgentPrincipal $subject,
        public string $credentialId,
        public string $clientId,
        public array $scopes,
    ) {}

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
