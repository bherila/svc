<?php

namespace Tests\Unit\Mcp;

use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Mockery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Tests\TestCase;

final class AccessCorrelationTransportTest extends TestCase
{
    public function test_the_registered_transport_carries_correlation_and_idempotency_but_rejects_other_headers(): void
    {
        $router = Mockery::mock(Router::class);
        $router->shouldReceive('dispatch')->once()->withArgs(function (Request $request): bool {
            $this->assertSame('/api/v1/synthetic', $request->getPathInfo());
            $this->assertSame('synthetic-operation', $request->header('Idempotency-Key'));
            $this->assertSame('synthetic_request_0001', $request->header('X-Request-Id'));
            $this->assertNull($request->header('X-Synthetic-Untrusted'));

            return true;
        })->andReturn(new JsonResponse(['synthetic' => true]));
        $this->app->instance(Router::class, $router);
        $response = app(InternalAgentApiTransport::class)->send('PUT', 'synthetic', json: [], headers: [
            'Idempotency-Key' => 'synthetic-operation',
            'X-Request-Id' => 'synthetic_request_0001',
            'X-Synthetic-Untrusted' => 'must-not-be-carried',
        ]);
        $this->assertSame(200, $response->status);
        $this->assertSame(['synthetic' => true], $response->json);
    }
}
