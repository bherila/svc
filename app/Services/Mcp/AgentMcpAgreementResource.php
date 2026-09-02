<?php

namespace App\Services\Mcp;

use App\Services\AgentApi\AgentAgreementReadService;
use App\Services\Mcp\Context\McpAccountContextResolver;
use App\Services\Mcp\Context\McpRequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use LogicException;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ResourceReadException;

/** Canonical agreement resource backed by the same scoped read as agreements.get. */
final class AgentMcpAgreementResource
{
    private const string RESOURCE_TEMPLATE = 'svc://workspaces/{workspace_id}/agreements/{agreement_id}';

    public function __construct(
        private readonly AgentAgreementReadService $agreements,
        private readonly McpAccountContextResolver $accounts,
        private readonly ?McpRequestContext $requestContext = null,
    ) {}

    /** @return array{data: array<string, mixed>} */
    public function read(string $workspace_id, string $agreement_id): array
    {
        if (! Str::isUuid($workspace_id) || ! Str::isUuid($agreement_id)) {
            throw new ResourceNotFoundException(self::RESOURCE_TEMPLATE);
        }

        try {
            $context = $this->workspace($workspace_id);

            return ['data' => $this->agreements->get($context->principal->subject, $context->workspace, $agreement_id)];
        } catch (ModelNotFoundException) {
            // A validly-shaped selector can still be outside the principal's
            // reach, and an agreement can disappear after discovery. Both are
            // resource misses at the MCP boundary, not server failures.
            throw new ResourceNotFoundException(self::RESOURCE_TEMPLATE);
        }
    }

    private function workspace(string $workspaceId): McpRequestContext
    {
        $context = $this->requestContext ?? throw new LogicException('MCP agreement resource requires a request context.');
        if (! $context->principal->hasScope('billing:read')) {
            throw new ResourceReadException('This connection lacks the required permission.');
        }

        return $this->accounts->resolve($context, $workspaceId);
    }
}
