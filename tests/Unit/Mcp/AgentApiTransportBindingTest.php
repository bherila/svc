<?php

namespace Tests\Unit\Mcp;

use App\Support\AgentApi\InternalApiDispatch;
use App\Support\AgentApi\MarkedInternalAgentApiTransport;
use Bherila\McpLaravelBridge\Http\AgentApiTransport;
use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
use Illuminate\Http\Request;
use ReflectionProperty;
use Tests\TestCase;

/** How the application binds the transport MCP tools reach their REST routes through (#459). */
final class AgentApiTransportBindingTest extends TestCase
{
    public function test_tools_get_the_marked_transport_addressed_to_the_callers_host(): void
    {
        $this->app->instance('request', Request::create('https://svc.example.test:8443/api/v1/mcp', 'POST'));

        $transport = $this->app->make(AgentApiTransport::class);
        $this->assertInstanceOf(MarkedInternalAgentApiTransport::class, $transport);
        $this->assertInstanceOf(MarkedInternalAgentApiTransport::class, $this->app->make(InternalAgentApiTransport::class));
        $this->assertSame('https://svc.example.test:8443/api/v1', (new ReflectionProperty(InternalAgentApiTransport::class, 'apiPrefix'))->getValue($transport));
        $this->assertSame(['Idempotency-Key', 'X-Request-Id'], (new ReflectionProperty(InternalAgentApiTransport::class, 'allowedHeaders'))->getValue($transport));
    }

    public function test_one_dispatch_marker_serves_the_whole_request(): void
    {
        $dispatch = $this->app->make(InternalApiDispatch::class);

        $this->assertSame($dispatch, $this->app->make(InternalApiDispatch::class));
        $this->assertTrue($dispatch->run(fn (): bool => $this->app->make(InternalApiDispatch::class)->active()));
    }
}
