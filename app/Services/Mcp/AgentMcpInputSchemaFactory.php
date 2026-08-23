<?php

namespace App\Services\Mcp;

use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
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

        if (! $definition->readOnly) {
            $body = AgentApiResponseSchemaCatalog::requestForOperation($definition->operationId);
            foreach ($body['properties'] as $name => $property) {
                $schema['properties'][$name] = $property;
            }
            $schema['required'] = array_values(array_unique([
                ...($schema['required'] ?? []),
                ...($body['required'] ?? []),
            ]));
            if (isset($body['$defs'])) {
                $schema['$defs'] = $body['$defs'];
            }
        }

        return $schema;
    }
}
