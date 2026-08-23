<?php

namespace App\Services\Mcp;

use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\HandlerResolver;
use Mcp\Capability\Discovery\SchemaGenerator;
use Psr\Log\NullLogger;

final class AgentMcpInputSchemaFactory
{
    /** @return array<string, mixed> */
    public function for(AgentMcpToolDefinition $definition): array
    {
        $schema = (new SchemaGenerator(new DocBlockParser(logger: new NullLogger)))
            ->generate(HandlerResolver::resolve($definition->handler));
        $schema['additionalProperties'] = false;

        return $schema;
    }
}
