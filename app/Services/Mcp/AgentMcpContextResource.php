<?php

namespace App\Services\Mcp;

use App\Services\AgentApi\AgentReadService;
use App\Services\Mcp\Context\McpRequestContext;
use LogicException;
use Mcp\Exception\ResourceReadException;

/** Bounded resource equivalent of context.get for clients that prefer resources. */
final class AgentMcpContextResource
{
    public function __construct(
        private readonly AgentReadService $reads,
        private readonly ?McpRequestContext $requestContext = null,
    ) {}

    /** @return array<string, mixed> */
    public function read(): array
    {
        $context = $this->requestContext ?? throw new LogicException('MCP context resource requires a request context.');
        if (! $context->principal->hasScope('identity:read')) {
            throw new ResourceReadException('This connection lacks the required permission.');
        }

        return ['data' => $this->reads->context($context->principal->subject, fn (string $scope): bool => $context->principal->hasScope($scope))];
    }
}
