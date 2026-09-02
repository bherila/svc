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
        $this->assertNonEmptyUniqueStrings($definition->requiredScopes, 'required scopes');
        if (! $this->isClosedObjectSchema($definition->inputSchema)) {
            throw new LogicException('MCP input schema must be a closed object.');
        }
        if (! $this->isClosedObjectSchema($definition->outputSchema)) {
            throw new LogicException('MCP output schema must be a closed object.');
        }
        if (in_array($definition->kind, [McpCapabilityKind::Resource, McpCapabilityKind::ResourceTemplate], true)
            && (! is_string($definition->uri) || trim($definition->uri) === '')) {
            throw new LogicException('MCP resources and resource templates must declare a URI.');
        }
        $this->assertNonEmptyUniqueStrings($definition->requiredCapabilities, 'required capabilities');
    }

    /** @param array<string, mixed> $schema */
    private function isClosedObjectSchema(array $schema): bool
    {
        return ($schema['type'] ?? null) === 'object'
            && ($schema['additionalProperties'] ?? null) === false;
    }

    /** @param list<string> $values */
    private function assertNonEmptyUniqueStrings(array $values, string $field): void
    {
        foreach ($values as $value) {
            if (trim($value) === '') {
                throw new LogicException("MCP {$field} must contain non-empty strings.");
            }
        }
        if (count($values) !== count(array_unique($values))) {
            throw new LogicException("MCP {$field} must not contain duplicates.");
        }
    }
}
