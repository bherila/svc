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
        $this->assertProductionContract($definition);
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

    private function assertProductionContract(McpCapabilityDefinition $definition): void
    {
        foreach ([
            'name' => $definition->name,
            'title' => $definition->title,
            'description' => $definition->description,
            'policy ability' => $definition->policyAbility,
            'rate-limit bucket' => $definition->rateLimitBucket,
            'audit classification' => $definition->auditClassification,
            'feature flag' => $definition->featureFlag,
        ] as $field => $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new LogicException("MCP {$field} must be declared.");
            }
        }
        if ($definition->requiredScopes === []) {
            throw new LogicException('MCP required scopes must be declared.');
        }
        if (! $this->isClosedObjectSchema($definition->inputSchema)) {
            throw new LogicException('MCP input schema must be a closed object.');
        }
        if (! $this->isClosedObjectSchema($definition->outputSchema)) {
            throw new LogicException('MCP output schema must be a closed object.');
        }
        if ($definition->kind === McpCapabilityKind::Resource && (! is_string($definition->uri) || $definition->uri === '')) {
            throw new LogicException('MCP resources must declare a URI.');
        }
        if (count($definition->requiredCapabilities) !== count(array_unique($definition->requiredCapabilities))) {
            throw new LogicException('MCP required capabilities must not contain duplicates.');
        }
    }

    /** @param array<string, mixed> $schema */
    private function isClosedObjectSchema(array $schema): bool
    {
        return ($schema['type'] ?? null) === 'object'
            && ($schema['additionalProperties'] ?? null) === false;
    }
}
