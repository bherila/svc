<?php

namespace Tests\Unit\Mcp;

use App\Services\Mcp\Registry\McpCapabilityDefinition;
use App\Services\Mcp\Registry\McpCapabilityKind;
use App\Services\Mcp\Registry\McpCapabilityRegistry;
use LogicException;
use Tests\TestCase;

final class McpCapabilityRegistryTest extends TestCase
{
    public function test_it_indexes_the_one_public_definition_by_name_and_kind(): void
    {
        $registry = new McpCapabilityRegistry;
        $tool = $this->definition(McpCapabilityKind::Tool, 'projects.list');
        $resource = $this->definition(McpCapabilityKind::Resource, 'svc://context');

        $registry->register($tool);
        $registry->register($resource);

        $this->assertSame([$tool, $resource], $registry->all());
        $this->assertSame([$tool], $registry->ofKind(McpCapabilityKind::Tool));
        $this->assertSame($resource, $registry->get('svc://context'));
    }

    public function test_it_rejects_duplicate_or_unknown_public_names(): void
    {
        $registry = new McpCapabilityRegistry;
        $registry->register($this->definition(McpCapabilityKind::Tool, 'projects.list'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Duplicate MCP capability: projects.list');

        $registry->register($this->definition(McpCapabilityKind::Tool, 'projects.list'));
    }

    public function test_it_rejects_an_unknown_public_name(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unknown MCP capability: projects.list');

        (new McpCapabilityRegistry)->get('projects.list');
    }

    public function test_it_rejects_definitions_without_required_production_metadata(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('MCP feature flag must be declared.');

        (new McpCapabilityRegistry)->register($this->definition(McpCapabilityKind::Tool, 'projects.list', featureFlag: ''));
    }

    public function test_it_rejects_a_non_closed_root_schema(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('MCP output schema must be a closed object.');

        (new McpCapabilityRegistry)->register($this->definition(
            McpCapabilityKind::Tool,
            'projects.list',
            outputSchema: ['type' => 'object', 'additionalProperties' => true],
        ));
    }

    /**
     * @param  array<string, mixed>  $inputSchema
     * @param  array<string, mixed>  $outputSchema
     */
    private function definition(
        McpCapabilityKind $kind,
        string $name,
        array $inputSchema = ['type' => 'object', 'additionalProperties' => false],
        array $outputSchema = ['type' => 'object', 'additionalProperties' => false],
        string $featureFlag = 'mcp-projects-read',
    ): McpCapabilityDefinition {
        return new McpCapabilityDefinition(
            kind: $kind,
            name: $name,
            title: 'List projects',
            description: 'Lists projects the principal can access.',
            handler: static fn (): array => [],
            inputSchema: $inputSchema,
            outputSchema: $outputSchema,
            requiredScopes: ['projects:read'],
            policyAbility: 'viewAny',
            requiresWorkspace: true,
            readOnly: true,
            idempotent: true,
            destructive: false,
            rateLimitBucket: 'mcp-read',
            auditClassification: 'read',
            featureFlag: $featureFlag,
            uri: $kind === McpCapabilityKind::Resource ? $name : null,
        );
    }
}
