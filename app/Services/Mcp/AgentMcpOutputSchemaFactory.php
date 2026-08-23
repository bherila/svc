<?php

namespace App\Services\Mcp;

use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Mcp\ToolDefinition;

/** Resolves each MCP output contract from its canonical REST operation. */
final class AgentMcpOutputSchemaFactory
{
    /** @return array<string, mixed> */
    public function for(ToolDefinition $definition): array
    {
        return AgentApiResponseSchemaCatalog::forOperation($definition->operationId());
    }
}
