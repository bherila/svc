<?php

namespace App\Services\Mcp\Registry;

use LogicException;

/**
 * The sole application registry for MCP's public capability names.
 *
 * It intentionally has no discovery policy. A caller must ask the authorizer
 * for visibility and repeat that authorization before execution.
 */
final class McpCapabilityRegistry
{
    /** @var array<string, McpCapabilityDefinition> */
    private array $definitions = [];

    public function register(McpCapabilityDefinition $definition): void
    {
        if (isset($this->definitions[$definition->name])) {
            throw new LogicException("Duplicate MCP capability: {$definition->name}");
        }

        $this->definitions[$definition->name] = $definition;
    }

    /** @return list<McpCapabilityDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /** @return list<McpCapabilityDefinition> */
    public function ofKind(McpCapabilityKind $kind): array
    {
        return array_values(array_filter(
            $this->definitions,
            static fn (McpCapabilityDefinition $definition): bool => $definition->kind === $kind,
        ));
    }

    public function get(string $name): McpCapabilityDefinition
    {
        return $this->definitions[$name]
            ?? throw new LogicException("Unknown MCP capability: {$name}");
    }
}
