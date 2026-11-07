<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentInlineSchemas;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\AgentMcpToolsetTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\McpKind;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/**
 * The toolset tools' declarations (progressive discovery, docs/mcp.md): three
 * MCP-only reads that change which tools one session lists, need the identity
 * read every listing connection already holds, and have no REST operation.
 * Pinned word for word, because a client reads the descriptions and schemas.
 */
final class ToolsetOperationContractTest extends TestCase
{
    private const string NOTE = ' Enabling a toolset only lists tools the connection could already call; every call is authorized again.';

    public function test_each_toolset_tool_is_declared_exactly(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            $expected = [
                'toolsets.list' => ['list', 'List toolsets', 'List the toolsets this connection may use, each with a description, the number of tools it holds and whether it is enabled. Only a core set of tools is listed until a toolset is enabled with toolsets.enable.'.self::NOTE, AgentInlineSchemas::toolsetsListInput()],
                'toolsets.enable' => ['enable', 'Enable toolsets', 'Enable one or more toolsets from toolsets.list for this connection. The server then sends notifications/tools/list_changed and tools/list includes their tools.'.self::NOTE, AgentInlineSchemas::toolsetsChangeInput()],
                'toolsets.disable' => ['disable', 'Disable toolsets', 'Disable toolsets enabled earlier on this connection to shorten tools/list again. The core tools stay listed. The server sends notifications/tools/list_changed.', AgentInlineSchemas::toolsetsChangeInput()],
            ];
            foreach ($expected as $id => [$method, $title, $description, $input]) {
                $operation = $catalog->registry()->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertSame($title, $operation->title, $id);
                $this->assertSame($description, $operation->description, $id);
                $this->assertSame(Effect::Read, $operation->effect, $id);
                $this->assertTrue($operation->isIdempotent(), $id);
                $this->assertFalse($catalog->isManagerOnly($id), $id);
                $this->assertFalse($catalog->readsExternalSystem($id), $id);
                $this->assertSame(['mcp:use'], $operation->requirement->scopes, $id);
                $this->assertSame([AgentDeploymentFlags::MCP, AgentDeploymentFlags::MCP_SWITCH.'mcp.read.toolsets', AgentDeploymentFlags::MCP_SWITCH.$id], $operation->requirement->flags, $id);
                $this->assertNull($operation->rest, $id);
                $this->assertSame(McpKind::Tool, $operation->mcp?->kind, $id);
                $this->assertSame([AgentMcpToolsetTools::class, $method], $operation->mcp->handler, $id);
                $this->assertSame($input, $operation->input, $id);
                $this->assertSame(AgentInlineSchemas::toolsetsOutput(), $operation->output, $id);
                $this->assertSame([], $operation->extensions, $id);
            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }

    public function test_the_input_schemas_are_closed_and_bounded(): void
    {
        $this->assertSame(['type' => 'object', 'additionalProperties' => false], AgentInlineSchemas::toolsetsListInput());
        $this->assertSame([
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['toolsets'],
            'properties' => [
                'toolsets' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 32,
                    'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                    'description' => 'Toolset names from toolsets.list.',
                ],
            ],
        ], AgentInlineSchemas::toolsetsChangeInput());
    }

    public function test_the_output_schema_is_closed_and_names_every_field_the_tools_return(): void
    {
        $this->assertSame([
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['data'],
            'properties' => [
                'data' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['mode', 'changed', 'toolsets'],
                    'properties' => [
                        'mode' => ['type' => 'string', 'enum' => ['progressive', 'all']],
                        'changed' => ['type' => 'boolean'],
                        'notes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Present only when the connection lacks identity:read: context.get, which discovers workspace IDs, needs it.'],
                        'toolsets' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['name', 'description', 'tool_count', 'enabled'],
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'description' => ['type' => 'string'],
                                    'tool_count' => ['type' => 'integer', 'minimum' => 0],
                                    'enabled' => ['type' => 'boolean'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], AgentInlineSchemas::toolsetsOutput());
    }
}
