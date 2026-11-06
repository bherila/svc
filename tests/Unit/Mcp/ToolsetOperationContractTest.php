<?php

namespace Tests\Unit\Mcp;

use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\AgentMcpToolsets;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class ToolsetOperationContractTest extends TestCase
{
    public function test_session_controls_publish_closed_schemas_and_their_real_dispatch_bindings(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $registry = (new AgentOperationCatalog)->registry();
            $validator = new SchemaValidator;
            foreach (['list' => 'List authorized toolsets', 'enable' => 'Enable an authorized toolset', 'disable' => 'Disable a toolset'] as $method => $title) {
                $id = 'toolsets.'.$method;
                $operation = $registry->find($id);
                $this->assertNotNull($operation);
                $this->assertSame($id, $operation->mcpName());
                $this->assertSame([AgentMcpToolsets::class, $method], $operation->mcp->handler);
                $this->assertSame(['mcp:use'], $operation->requirement->scopes);
                $this->assertNull($operation->rest);
                $this->assertStringStartsWith($title.'. ', $operation->description);
                $this->assertStringContainsString('Changes only session visibility, never permissions.', $operation->description);
                $this->assertStringContainsString('Refresh tools/list after a list_changed notification.', $operation->description);
                $input = $operation->input;
                $this->assertIsArray($input);
                $valid = $method === 'list' ? [] : ['name' => 'x'];
                $this->assertSame([], $validator->validateAgainstJsonSchema($valid, $input));
                $this->assertNotEmpty($validator->validateAgainstJsonSchema(42, $input));
                $this->assertNotEmpty($validator->validateAgainstJsonSchema([...$valid, 'grant' => 'finance:read'], $input));
                if ($method !== 'list') {
                    foreach ([[], ['name' => ''], ['name' => 42], ['name' => str_repeat('x', 101)]] as $invalid) {
                        $this->assertNotEmpty($validator->validateAgainstJsonSchema($invalid, $input));
                    }
                    $this->assertSame([], $validator->validateAgainstJsonSchema(['name' => str_repeat('x', 100)], $input));
                }
                $output = $operation->output;
                $this->assertIsArray($output);
                $entry = ['name' => 'billing', 'description' => 'Billing tools', 'tool_count' => 3, 'enabled' => false];
                $this->assertSame([], $validator->validateAgainstJsonSchema(['data' => [$entry]], $output));
                foreach ([42, [], ['data' => 'invalid'], ['data' => [42]], ['data' => [$entry], 'secret' => true], ['data' => [[...$entry, 'secret' => true]]]] as $invalid) {
                    $this->assertNotEmpty($validator->validateAgainstJsonSchema($invalid, $output));
                }
                foreach (['name' => 42, 'description' => 42, 'tool_count' => '3', 'enabled' => 'false'] as $field => $wrongType) {
                    $missing = $entry;
                    unset($missing[$field]);
                    $this->assertNotEmpty($validator->validateAgainstJsonSchema(['data' => [$missing]], $output));
                    $this->assertNotEmpty($validator->validateAgainstJsonSchema(['data' => [[...$entry, $field => $wrongType]]], $output));
                }
            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
