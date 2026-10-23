<?php

namespace Tests\Feature\AgentApi;

use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Http\GateOperation;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * Every route under `/api/v1` is an operation on the registry (#457), so it is
 * in the OpenAPI document, gated by its declared scopes and reachable over MCP,
 * or it is named below with the reason it is not. A new route that is neither
 * fails here, whatever it is named: the registry's own route test only sees
 * routes under the `agent-api.v1.` name prefix.
 */
final class VersionedApiRouteInventoryTest extends TestCase
{
    /**
     * Routes under `/api/v1` that are not operations, by route name.
     *
     * @var array<string, string>
     */
    private const array NOT_OPERATIONS = [
        'agent-api.v1.mcp' => 'The MCP transport endpoint itself; each tool it serves is an operation.',
        'agent-api.v1.mcp.options' => 'CORS preflight for the MCP transport endpoint.',
    ];

    public function test_every_versioned_api_route_is_an_operation_or_explains_why_not(): void
    {
        $registry = app(OperationRegistry::class);
        $router = app('router');
        $operations = 0;
        $unexplained = [];
        $explained = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if ($route->uri() !== 'api/v1' && ! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $name = (string) $route->getName();
            $description = implode('|', $route->methods()).' '.$route->uri().' ('.($name === '' ? 'unnamed' : $name).')';
            $gates = array_values(array_filter(
                $router->gatherRouteMiddleware($route),
                static fn (string $entry): bool => str_starts_with($entry, GateOperation::class.':'),
            ));
            if ($gates !== []) {
                $this->assertCount(1, $gates, $description);
                $operation = $registry->find(substr($gates[0], strlen(GateOperation::class.':')));
                $this->assertNotNull($operation, $description);
                $this->assertSame($operation->rest?->routeName, $name, $description);
                $this->assertArrayNotHasKey($name, self::NOT_OPERATIONS, "{$description} is an operation; take it off the list.");
                $operations++;

                continue;
            }
            if (isset(self::NOT_OPERATIONS[$name])) {
                $explained[] = $name;

                continue;
            }
            $unexplained[] = $description;
        }

        $this->assertSame([], $unexplained, 'Bind these through Route::operation() from the operation registry, or name them in NOT_OPERATIONS with a reason.');
        $listed = array_keys(self::NOT_OPERATIONS);
        sort($listed);
        sort($explained);
        $this->assertSame($listed, $explained, 'Every listed route still exists and is still not an operation.');
        $this->assertGreaterThan(100, $operations);
    }
}
