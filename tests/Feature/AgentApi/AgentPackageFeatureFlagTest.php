<?php

namespace Tests\Feature\AgentApi;

use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\ServicePackageOperations;
use App\Services\Packages\ServicePackageWorkflow;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentOpenApi;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\OpenApi\OpenApiDocumentBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Tests\Concerns\BootsServicePackages;
use Tests\Concerns\BuildsServicePackages;
use Tests\Concerns\CallsMcp;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * With SVC_SERVICE_PACKAGES_ENABLED off, as it is by default, the package
 * operations do not exist on the agent API: no route, no MCP tool or toolset,
 * nothing in the served OpenAPI document (API-6, X-1). The checked contract
 * still documents them, so it does not depend on the local configuration.
 */
#[Requirement('API-6', 'X-1')]
final class AgentPackageFeatureFlagTest extends TestCase
{
    use BootsServicePackages;
    use BuildsServicePackages;
    use CallsMcp;
    use RefreshDatabase;

    protected static function servicePackagesAtBoot(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.package_writes_enabled' => true, 'app.url' => AgentOpenApi::CHECKED_BASE_URL,
            'bherila-auth.oauth_server.authorization_endpoint' => AgentOpenApi::CHECKED_BASE_URL.'/oauth/authorize',
            'bherila-auth.oauth_server.token_endpoint' => AgentOpenApi::CHECKED_BASE_URL.'/oauth/token']);
    }

    public function test_the_feature_is_off_by_default(): void
    {
        $this->assertFalse(ServicePackageOperations::enabled());
    }

    public function test_no_package_operation_is_registered_or_routed(): void
    {
        $registry = app(OperationRegistry::class);
        foreach (array_keys(ServicePackageOperations::ROUTES) as $id) {
            $this->assertNull($registry->find($id), $id);
            $this->assertNull(app('router')->getRoutes()->getByName('agent-api.v1.'.ServicePackageOperations::ROUTES[$id][0]), $id);
        }
        $uris = array_map(static fn (Route $route): string => $route->uri(), app('router')->getRoutes()->getRoutes());
        $this->assertSame([], array_values(array_filter($uris, static fn (string $uri): bool => str_starts_with($uri, 'api/') && (str_contains($uri, '/packages') || str_contains($uri, '/package-requests')))));

        [$workspace, $owner] = $this->packageWorkspace();
        $package = $this->createPackage($workspace, $owner);
        $this->actingAsMcp($owner, array_keys(AgentApiScopes::descriptions()));
        $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/packages')->assertNotFound();
        $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/packages/'.$package->public_id)->assertNotFound();
        $this->postJson('/api/v1/workspaces/'.$workspace->public_id.'/packages', $this->hostingPackageInput(['link_name' => 'absent']), ['Idempotency-Key' => 'synthetic-absent'])->assertNotFound();

        // Nor do the sign-up request operations, for a request that exists.
        config(['svc.service_packages.enabled' => true]);
        $request = $this->packageRequest($workspace, app(ServicePackageWorkflow::class)->publish($owner, $workspace, $package));
        $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/package-requests')->assertNotFound();
        $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/package-requests/'.$request->public_id)->assertNotFound();
        foreach (['approve', 'decline', 'erase'] as $action) {
            $this->postJson('/api/v1/workspaces/'.$workspace->public_id.'/package-requests/'.$request->public_id.'/'.$action, ['expected_version' => AgentApiVersion::for($request), 'confirm' => true], ['Idempotency-Key' => 'synthetic-absent-'.$action])->assertNotFound();
        }
        $this->assertSame('new', $request->refresh()->status);
    }

    public function test_mcp_offers_no_package_tool_or_toolset(): void
    {
        [, $owner] = $this->packageWorkspace();
        $this->actingAsMcp($owner, array_keys(AgentApiScopes::descriptions()));

        $names = $this->toolNames($this->initialize());
        $this->assertSame([], array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'packages.') || str_starts_with($name, 'package_requests.'))));
        $this->assertContains('proposals.list', $names);

        $session = $this->initializeProgressive();
        $toolsets = $this->callTool($session, 'toolsets.list', [])['result']['structuredContent']['data']['toolsets'];
        $this->assertNotContains('packages', array_column($toolsets, 'name'));
        $this->assertContains('proposals', array_column($toolsets, 'name'));
        $enable = $this->callTool($session, 'toolsets.enable', ['toolsets' => ['packages']]);
        $this->assertTrue($enable['result']['isError'] ?? false);
        $this->assertStringContainsString('Unknown toolset: packages', json_encode($enable));
        $context = $this->getJson('/api/v1/context')->assertOk()->json('data.withheld_tools');
        $this->assertSame([], array_values(array_filter(array_column($context, 'name'), static fn (string $name): bool => str_starts_with($name, 'packages.') || str_starts_with($name, 'package_requests.'))));
    }

    public function test_the_served_document_leaves_the_package_operations_out(): void
    {
        $served = $this->getJson('/api/openapi.json')->assertOk()->json();
        $this->assertSame([], array_values(array_filter(array_keys($served['paths']), static fn (string $path): bool => str_contains($path, '/packages') || str_contains($path, '/package-requests'))));
        $this->assertSame([], array_values(array_filter(array_keys($served['components']['schemas']), static fn (string $name): bool => str_starts_with($name, 'ServicePackage'))));

        // The checked contract is the same whatever the switch, and differs
        // from the served document by exactly the package surface.
        $this->assertSame([], OpenApiDocumentBuilder::differences(AgentOpenApi::checked()->full(), public_path(AgentOpenApi::CHECKED_DOCUMENT)));
        $differences = OpenApiDocumentBuilder::differences(AgentOpenApi::forInstallation()->full(), public_path(AgentOpenApi::CHECKED_DOCUMENT));
        $missing = array_values(array_filter($differences, static fn (string $line): bool => str_ends_with($line, ': missing from the generated document')));
        $this->assertSame($differences, $missing, 'Only the package surface is left out.');
        $expected = [
            ...array_map(static fn (string $path): string => '/paths/'.str_replace('/', '~1', $path).': missing from the generated document',
                array_values(array_unique(array_column(ServicePackageOperations::ROUTES, 1)))),
            ...array_map(static fn (string $component): string => '/components/schemas/'.$component.': missing from the generated document', ServicePackageOperations::components()),
        ];
        sort($expected);
        sort($missing);
        $this->assertSame($expected, $missing);
        $this->assertNotSame(app(AgentOperationCatalog::class)->registry(), app(AgentOperationCatalog::class)->contract());
    }
}
