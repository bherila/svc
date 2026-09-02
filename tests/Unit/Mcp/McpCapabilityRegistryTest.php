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

    public function test_it_rejects_blank_required_scopes(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('MCP required scopes must contain non-empty strings.');

        (new McpCapabilityRegistry)->register($this->definition(
            McpCapabilityKind::Tool,
            'projects.list',
            requiredScopes: ['projects:read', ''],
        ));
    }

    public function test_it_rejects_duplicate_required_scopes(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('MCP required scopes must not contain duplicates.');

        (new McpCapabilityRegistry)->register($this->definition(
            McpCapabilityKind::Tool,
            'projects.list',
            requiredScopes: ['projects:read', 'projects:read'],
        ));
    }

    public function test_it_rejects_duplicate_required_capabilities(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('MCP required capabilities must not contain duplicates.');

        (new McpCapabilityRegistry)->register($this->definition(
            McpCapabilityKind::Tool,
            'projects.list',
            requiredCapabilities: ['context.get', 'context.get'],
        ));
    }

    public function test_it_rejects_a_resource_or_resource_template_with_a_blank_uri(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('MCP resources and resource templates must declare a URI.');

        (new McpCapabilityRegistry)->register($this->definition(
            McpCapabilityKind::Resource,
            'svc://context',
            uri: ' ',
        ));
    }

    public function test_it_rejects_a_resource_template_without_a_uri(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('MCP resources and resource templates must declare a URI.');

        (new McpCapabilityRegistry)->register($this->definition(
            McpCapabilityKind::ResourceTemplate,
            'agreement',
        ));
    }

    /**
     * @param  array<string, mixed>  $inputSchema
     * @param  array<string, mixed>  $outputSchema
     * @param  list<string>  $requiredScopes
     * @param  list<string>  $requiredCapabilities
     */
    private function definition(
        McpCapabilityKind $kind,
        string $name,
        array $inputSchema = ['type' => 'object', 'additionalProperties' => false],
        array $outputSchema = ['type' => 'object', 'additionalProperties' => false],
        string $featureFlag = 'mcp-projects-read',
        array $requiredScopes = ['projects:read'],
        array $requiredCapabilities = [],
        ?string $uri = null,
    ): McpCapabilityDefinition {
        return new McpCapabilityDefinition(
            kind: $kind,
            name: $name,
            title: 'List projects',
            description: 'Lists projects the principal can access.',
            handler: static fn (): array => [],
            inputSchema: $inputSchema,
            outputSchema: $outputSchema,
            requiredScopes: $requiredScopes,
            policyAbility: 'viewAny',
            requiresWorkspace: true,
            readOnly: true,
            idempotent: true,
            destructive: false,
            rateLimitBucket: 'mcp-read',
            auditClassification: 'read',
            featureFlag: $featureFlag,
            uri: $uri ?? ($kind === McpCapabilityKind::Resource ? $name : null),
            requiredCapabilities: $requiredCapabilities,
        );
    }
}
