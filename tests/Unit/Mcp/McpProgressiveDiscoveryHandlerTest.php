<?php

namespace Tests\Unit\Mcp;

use App\Services\Mcp\AgentMcpToolsets;
use App\Services\Mcp\McpProgressiveDiscoveryHandler;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\McpBinding;
use Bherila\McpLaravelBridge\Capabilities\McpKind;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Capabilities\Requirement;
use Mcp\Capability\Registry;
use Mcp\Exception\InvalidCursorException;
use Mcp\Schema\Prompt;
use Mcp\Schema\Request\ListPromptsRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Request\PingRequest;
use Mcp\Schema\Tool;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\TestCase;

final class McpProgressiveDiscoveryHandlerTest extends TestCase
{
    public function test_pagination_filters_before_counting_and_preserves_all_201_visible_tools(): void
    {
        $registry = new Registry;
        $operations = [];
        for ($id = 0; $id < 201; $id++) {
            $name = 'synthetic.tool_'.$id;
            $operations[] = $this->operation($name);
            $registry->registerTool(Tool::fromArray(['name' => $name, 'inputSchema' => ['type' => 'object']]), static fn () => null);
        }
        $toolsets = new AgentMcpToolsets($operations);
        $handler = new McpProgressiveDiscoveryHandler($registry, $toolsets, []);
        $session = new Session(new InMemorySessionStore);
        $request = $this->request();
        $this->assertTrue($handler->supports($request));
        $this->assertSame([], $handler->handle($request, $session)->result->tools);
        $session->set('svc_toolsets', ['synthetic']);
        $first = $handler->handle($request, $session)->result;
        $this->assertCount(100, $first->tools);
        $this->assertSame('synthetic.tool_0', $first->tools[0]->name);
        $this->assertSame('synthetic.tool_99', $first->tools[99]->name);
        $this->assertSame(base64_encode('100'), $first->nextCursor);
        $second = $handler->handle($this->request($first->nextCursor), $session)->result;
        $this->assertCount(100, $second->tools);
        $this->assertSame('synthetic.tool_100', $second->tools[0]->name);
        $third = $handler->handle($this->request($second->nextCursor), $session)->result;
        $this->assertCount(1, $third->tools);
        $this->assertSame('synthetic.tool_200', $third->tools[0]->name);
        $this->assertNull($third->nextCursor);
        $this->assertSame([], $handler->handle($this->request(base64_encode('201')), $session)->result->tools);
    }

    public function test_invalid_cursors_are_refused_including_positions_past_the_visible_set(): void
    {
        $session = new Session(new InMemorySessionStore);
        $handler = new McpProgressiveDiscoveryHandler(new Registry, new AgentMcpToolsets([]), []);
        foreach (['invalid%', base64_encode('-1'), base64_encode('1.5'), base64_encode('1')] as $cursor) {
            try {
                $handler->handle($this->request($cursor), $session);
                $this->fail('Invalid cursor accepted: '.$cursor);
            } catch (InvalidCursorException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_prompt_visibility_requires_every_tool_and_fetch_never_changes_selection(): void
    {
        $registry = new Registry;
        $registry->registerPrompt(Prompt::fromArray(['name' => 'workflow']), static fn () => []);
        $registry->registerPrompt(Prompt::fromArray(['name' => 'core-workflow']), static fn () => []);
        $prompt = new Operation(id: 'prompts.workflow', title: 'Workflow', description: 'Synthetic prompt.', effect: Effect::Read,
            requirement: new Requirement(scopes: ['synthetic:read']), mcp: new McpBinding(name: 'workflow', kind: McpKind::Prompt),
            requiresOperations: ['synthetic.first', 'other.second']);
        $core = new Operation(id: 'prompts.core', title: 'Core', description: 'Core prompt.', effect: Effect::Read,
            requirement: new Requirement(scopes: ['identity:read']), mcp: new McpBinding(name: 'core-workflow', kind: McpKind::Prompt), requiresOperations: ['context.get']);
        $toolsets = new AgentMcpToolsets([$this->operation('synthetic.first'), $this->operation('other.second', 'other:read')]);
        $handler = new McpProgressiveDiscoveryHandler($registry, $toolsets, [$prompt, $core]);
        $session = new Session(new InMemorySessionStore);
        $request = ListPromptsRequest::fromArray(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'prompts/list']);
        $this->assertTrue($handler->supports($request));
        $this->assertSame(['core-workflow'], array_column($handler->handle($request, $session)->result->prompts, 'name'));
        $session->set('svc_toolsets', ['synthetic']);
        $this->assertFalse($handler->promptVisible('workflow', $session));
        $this->assertSame(['synthetic'], $session->get('svc_toolsets'));
        $session->set('svc_toolsets', ['synthetic', 'other']);
        $this->assertTrue($handler->promptVisible('workflow', $session));
        $this->assertSame(['workflow', 'core-workflow'], array_column($handler->handle($request, $session)->result->prompts, 'name'));
        $this->assertFalse($handler->promptVisible('unknown', $session));
        $ping = PingRequest::fromArray(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping']);
        $this->assertFalse($handler->supports($ping));
    }

    private function operation(string $name, string $scope = 'synthetic:read'): Operation
    {
        return new Operation(id: $name, title: $name, description: 'Synthetic tool.', effect: Effect::Read,
            requirement: new Requirement(scopes: [$scope]), mcp: new McpBinding);
    }

    private function request(?string $cursor = null): ListToolsRequest
    {
        return ListToolsRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['cursor' => $cursor]]);
    }
}
