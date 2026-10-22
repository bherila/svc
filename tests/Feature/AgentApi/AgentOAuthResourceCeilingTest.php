<?php

namespace Tests\Feature\AgentApi;

use App\Http\Middleware\ExpectSelfRevokingOAuthResource;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Support\AgentApi\AgentApiScopes;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use BWH\Auth\Http\Middleware\ExpectOAuthResource;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Each protected resource's scope ceiling is exactly what its endpoint needs.
 *
 * A token is accepted only by routes expecting its resource and may carry only
 * scopes that resource lists. A scope missing from a ceiling makes an operation
 * on that endpoint unreachable by any credential; an extra one lets a token
 * carry a right its endpoint never checks - which is how `mcp:use` must stay
 * off REST tokens. Both sides are derived from the operation registry, the
 * single declaration of every route and MCP capability.
 */
final class AgentOAuthResourceCeilingTest extends TestCase
{
    public function test_the_configured_ceilings_are_the_catalog_lists(): void
    {
        $resources = OAuthResourceIndicator::resources();

        $this->assertSame(['rest', 'mcp'], array_keys($resources));
        $this->assertSame(url('/api/v1'), $resources['rest']['uri']);
        $this->assertSame(url('/api/v1/mcp'), $resources['mcp']['uri']);
        $this->assertSame(AgentApiScopes::rest(), $resources['rest']['scopes']);
        $this->assertSame(AgentApiScopes::mcp(), $resources['mcp']['scopes']);
        $this->assertSame('rest', OAuthResourceIndicator::defaultName());
        $this->assertSame([AgentApiScopes::MCP_USE], OAuthResourceIndicator::requiredScopes());
        $this->assertNotContains(AgentApiScopes::MCP_USE, AgentApiScopes::rest());
        $this->assertContains(AgentApiScopes::MCP_USE, AgentApiScopes::mcp());
        $this->assertEqualsCanonicalizing(array_keys(AgentApiScopes::descriptions()), array_unique([...AgentApiScopes::rest(), ...AgentApiScopes::mcp()]));
    }

    public function test_every_operation_fits_the_ceiling_of_each_endpoint_that_serves_it_and_nothing_more_is_admitted(): void
    {
        $needed = ['rest' => [], 'mcp' => [AgentApiScopes::MCP_USE]];

        foreach (app(AgentOperationCatalog::class)->registry()->all() as $operation) {
            $scopes = $operation->requirement->scopes;
            if ($operation->rest !== null) {
                $route = $this->route($operation);
                $middleware = app('router')->gatherRouteMiddleware($route);
                if ($operation->id === 'oauth.token.revoke') {
                    // Only credential disposal admits either resource, with no new permissions.
                    $this->assertContains(ExpectSelfRevokingOAuthResource::class, $middleware);
                    $this->assertSame([], $scopes);
                    $this->assertTrue($operation->requirement->authenticated);
                    $this->assertNull($operation->mcp);
                    foreach (OAuthResourceIndicator::resources() as $resource => $configured) {
                        $this->assertSame([], array_values(array_diff($scopes, $configured['scopes'])),
                            "Self-revocation fits the {$resource} ceiling");
                    }
                } else {
                    $this->assertNotContains(ExpectSelfRevokingOAuthResource::class, $middleware);
                    $resource = $this->expectedResource($route, $operation->id);
                    $this->assertSame([], array_values(array_diff($scopes, OAuthResourceIndicator::resources()[$resource]['scopes'] ?? [])),
                        "{$operation->id} is served on {$resource}, which must admit every scope it requires");
                    $needed[$resource] = [...$needed[$resource], ...$scopes];
                }
            }
            if ($operation->mcp !== null) {
                $this->assertSame([], array_values(array_diff($scopes, AgentApiScopes::mcp())),
                    "{$operation->id} is an MCP capability, so the MCP resource must admit every scope it requires");
                $needed['mcp'] = [...$needed['mcp'], ...$scopes];
            }
        }

        $this->assertEqualsCanonicalizing(array_values(array_unique($needed['rest'])), AgentApiScopes::rest(),
            'The REST ceiling admits exactly the scopes its routes require');
        $this->assertEqualsCanonicalizing(array_values(array_unique($needed['mcp'])), AgentApiScopes::mcp(),
            'The MCP ceiling admits exactly the connection scope and the scopes its capabilities and routes require');
    }

    public function test_the_mcp_endpoint_expects_the_mcp_resource(): void
    {
        $route = Route::getRoutes()->getByName('agent-api.v1.mcp');
        $this->assertNotNull($route);
        $this->assertSame('mcp', $this->expectedResource($route, 'the MCP endpoint'));
    }

    private function route(Operation $operation): RoutingRoute
    {
        $name = (string) $operation->rest?->routeName;
        $route = Route::getRoutes()->getByName($name) ?? Route::getRoutes()->getByName('agent-api.v1.'.$name);
        $this->assertNotNull($route, "{$operation->id} has a route");

        return $route;
    }

    /** The resource a route's ExpectOAuthResource middleware names; it must name one. */
    private function expectedResource(RoutingRoute $route, string $label): string
    {
        $expected = array_values(array_filter(
            app('router')->gatherRouteMiddleware($route),
            static fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, ExpectOAuthResource::class),
        ));
        $this->assertCount(1, $expected, "{$label} declares exactly one protected resource");
        $this->assertStringStartsWith(ExpectOAuthResource::class.':', $expected[0], "{$label} names its resource");

        return substr($expected[0], strlen(ExpectOAuthResource::class) + 1);
    }
}
