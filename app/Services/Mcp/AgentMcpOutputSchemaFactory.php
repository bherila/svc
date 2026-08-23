<?php

namespace App\Services\Mcp;

use App\Support\AgentApi\AgentApiResponseSchemaCatalog;

/** Resolves each MCP output contract from its canonical REST operation. */
final class AgentMcpOutputSchemaFactory
{
    /** @return array<string, mixed> */
    public function for(AgentMcpToolDefinition $definition): array
    {
        return AgentApiResponseSchemaCatalog::forOperation($definition->operationId);
    }
}
