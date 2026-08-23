<?php

namespace App\Services\Mcp;

use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Mcp\ReflectedInputSchemaFactory;
use Bherila\McpLaravelBridge\Mcp\ToolDefinition;

final class AgentMcpInputSchemaFactory
{
    public function __construct(private readonly ReflectedInputSchemaFactory $reflected) {}

    /** @return array<string, mixed> */
    public function for(ToolDefinition $definition): array
    {
        $schema = $this->reflected->for($definition->handler);

        if (! $definition->readOnly) {
            $body = AgentApiResponseSchemaCatalog::requestForOperation($definition->operationId());
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
            foreach (['allOf', 'anyOf', 'oneOf', 'dependentRequired'] as $keyword) {
                if (array_key_exists($keyword, $body)) {
                    $schema[$keyword] = $body[$keyword];
                }
            }
        }

        return $schema;
    }
}
