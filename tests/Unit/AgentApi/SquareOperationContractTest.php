<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\AgentMcpSquareMirrorTools;
use App\Services\Mcp\AgentMcpSquareTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/**
 * The read-only Square pass-through's declarations (#438): each operation is
 * a manager-only, idempotent, open-world read behind the Square switch, and
 * its REST contract is exactly what the checked OpenAPI document publishes.
 */
final class SquareOperationContractTest extends TestCase
{
    private const string COMMON = ' Live, read-only Square data fetched on each call for finding Square records to link to svc records; svc never creates, changes or pays anything in Square, and linking is a separate svc operation. Workspace managers only, and only in the workspace Square is connected to; unavailable while Square is not configured. Square ids and cursors are opaque.';

    /** operation => [MCP handler method, route name suffix, path after /square, response component] */
    private const array OPERATIONS = [
        'square.locations.list' => ['locations', 'locations.index', '/locations', 'SquareLocationListResponse'],
        'square.customers.list' => ['customers', 'customers.index', '/customers', 'SquareCustomerListResponse'],
        'square.customers.search' => ['searchCustomers', 'customers.search', '/customers/search', 'SquareCustomerListResponse'],
        'square.customers.get' => ['customer', 'customers.show', '/customers/{customer}', 'SquareCustomerResponse'],
        'square.invoices.list' => ['invoices', 'invoices.index', '/invoices', 'SquareInvoiceListResponse'],
        'square.invoices.search' => ['searchInvoices', 'invoices.search', '/invoices/search', 'SquareInvoiceListResponse'],
        'square.invoices.get' => ['invoice', 'invoices.show', '/invoices/{invoice}', 'SquareInvoiceResponse'],
        'square.orders.get' => ['order', 'orders.show', '/orders/{order}', 'SquareOrderResponse'],
        'square.payments.list' => ['payments', 'payments.index', '/payments', 'SquarePaymentListResponse'],
        'square.payments.get' => ['payment', 'payments.show', '/payments/{payment}', 'SquarePaymentResponse'],
    ];

    /**
     * svc's mirror of Square: operation => [MCP handler method, HTTP method,
     * route name suffix, path after /square, scope, effect, response, request].
     */
    private const array MIRROR = [
        'square.sync' => ['sync', 'POST', 'sync', '/sync', 'billing:write', Effect::LocalWrite, 'SquareSyncRunResponse', 'SquareSyncRequest'],
    ];

    public function test_square_operations_are_manager_only_open_world_reads_behind_the_square_switch(): void
    {
        $this->withCatalog(function (AgentOperationCatalog $catalog): void {
            $registry = $catalog->registry();
            $declared = [];
            foreach ($registry->all() as $operation) {
                if (str_starts_with($operation->id, 'square.')) {
                    $declared[] = $operation->id;
                }
            }
            $expected = [...array_keys(self::OPERATIONS), ...array_keys(self::MIRROR)];
            sort($declared);
            sort($expected);
            $this->assertSame($expected, $declared);

            foreach (self::OPERATIONS as $id => [$method, $route, $path]) {
                $operation = $registry->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertSame(Effect::Read, $operation->effect, $id);
                $this->assertTrue($operation->isIdempotent(), $id);
                $this->assertTrue($catalog->isManagerOnly($id), $id);
                $this->assertTrue($catalog->readsExternalSystem($id), $id);
                $this->assertSame([AgentMcpSquareTools::class, $method], $operation->mcp?->handler, $id);
                $this->assertSame(['billing:read'], $operation->requirement->scopes, $id);
                $this->assertSame([AgentDeploymentFlags::SQUARE, 'mcp', 'mcp.switch:mcp.read.square', 'mcp.switch:'.$id], $operation->requirement->flags, $id);
                $this->assertSame(IdempotencyKey::None, $operation->safety->idempotencyKey, $id);
                $this->assertSame('GET', $operation->rest?->method, $id);
                $this->assertSame('agent-api.v1.square.'.$route, $operation->rest->routeName, $id);
                $this->assertSame('/workspaces/{workspace}/square'.$path, $operation->rest->path, $id);
                $this->assertSame([200], $operation->rest->successStatuses, $id);
                $this->assertSame(['x-mcp-tool' => $id], $operation->extensions, $id);
                $this->assertStringEndsWith(self::COMMON, $operation->description, $id);
                $this->assertNotSame(self::COMMON, $operation->description, $id);
                $this->assertSame($operation->rest->description, $operation->description, $id);
            }
            // Asking a fresh catalog builds the declarations first.
            $this->assertTrue((new AgentOperationCatalog)->readsExternalSystem('square.locations.list'));
            // Only the Square pass-through reaches outside svc.
            foreach (['invoices.list', 'payments.list', 'search', 'context.get'] as $local) {
                $this->assertFalse($catalog->readsExternalSystem($local), $local);
            }
        });
    }

    public function test_mirror_operations_are_manager_only_and_never_write_to_square(): void
    {
        $this->withCatalog(function (AgentOperationCatalog $catalog): void {
            foreach (self::MIRROR as $id => [$handler, $method, $route, $path, $scope, $effect]) {
                $operation = $catalog->registry()->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertSame($effect, $operation->effect, $id);
                $this->assertTrue($operation->isIdempotent(), $id);
                $this->assertTrue($catalog->isManagerOnly($id), $id);
                $this->assertSame([AgentMcpSquareMirrorTools::class, $handler], $operation->mcp?->handler, $id);
                $this->assertSame([$scope], $operation->requirement->scopes, $id);
                $this->assertSame($method, $operation->rest?->method, $id);
                $this->assertSame('agent-api.v1.square.'.$route, $operation->rest->routeName, $id);
                $this->assertSame('/workspaces/{workspace}/square'.$path, $operation->rest->path, $id);
                $this->assertSame([200], $operation->rest->successStatuses, $id);
                $this->assertSame($operation->rest->description, $operation->description, $id);
                $this->assertStringContainsString('Workspace managers only.', $operation->description, $id);
                if ($effect === Effect::Read) {
                    // Reads of the mirror never reach Square.
                    $this->assertFalse($catalog->readsExternalSystem($id), $id);
                    $this->assertSame([AgentDeploymentFlags::SQUARE, 'mcp', 'mcp.switch:mcp.read.square', 'mcp.switch:'.$id], $operation->requirement->flags, $id);
                    $this->assertSame(IdempotencyKey::None, $operation->safety->idempotencyKey, $id);
                } else {
                    // The sync reads Square, writes only svc's mirror, and the
                    // outer agent write switch withholds it.
                    $this->assertTrue($catalog->readsExternalSystem($id), $id);
                    $this->assertSame([AgentDeploymentFlags::WRITES, AgentDeploymentFlags::SQUARE, 'mcp', 'mcp.switch:mcp.write', 'mcp.switch:'.$id], $operation->requirement->flags, $id);
                    $this->assertSame(IdempotencyKey::HeaderAndArgument, $operation->safety->idempotencyKey, $id);
                    $this->assertStringContainsString('nothing is created, changed or paid in Square', $operation->description, $id);
                }
            }
        });
    }

    public function test_mirror_rest_bindings_match_the_checked_document(): void
    {
        $document = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/public/openapi/svc-agent-v1.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->withCatalog(function (AgentOperationCatalog $catalog) use ($document): void {
            foreach (self::MIRROR as $id => [, $method, , $path, $scope, , $response, $request]) {
                $declared = $catalog->registry()->find($id);
                $this->assertNotNull($declared?->rest, $id);
                $operation = $document['paths']['/workspaces/{workspace}/square'.$path][strtolower($method)] ?? null;
                $this->assertIsArray($operation, $id);
                $this->assertSame($id, $operation['operationId'], $id);
                $this->assertSame($declared->description, $operation['description'], $id);
                $this->assertSame([['oauth2' => [$scope]], ['apiToken' => []]], $operation['security'], $id);
                $this->assertSame(
                    array_map(static fn (string|array $parameter): array => is_string($parameter) ? ['$ref' => '#/components/parameters/'.$parameter] : $parameter, $declared->rest->parameters),
                    $operation['parameters'],
                    $id,
                );
                $this->assertSame('#/components/schemas/'.$response, $operation['responses']['200']['content']['application/json']['schema']['$ref'], $id);
                $this->assertSame($request === null ? null : '#/components/schemas/'.$request, $operation['requestBody']['content']['application/json']['schema']['$ref'] ?? null, $id);
            }
        });
    }

    /** The declarations and the published contract agree, parameter for parameter. */
    public function test_square_rest_bindings_match_the_checked_document(): void
    {
        $document = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/public/openapi/svc-agent-v1.json'), true, flags: JSON_THROW_ON_ERROR);
        $published = [];
        foreach ($document['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (is_array($operation) && str_starts_with((string) ($operation['operationId'] ?? ''), 'square.')) {
                    $published[$operation['operationId']] = [$path, $method, $operation];
                }
            }
        }

        $this->withCatalog(function (AgentOperationCatalog $catalog) use ($published): void {
            foreach (self::OPERATIONS as $id => [, , $path, $component]) {
                $this->assertArrayHasKey($id, $published, $id);
                [$publishedPath, $method, $operation] = $published[$id];
                $declared = $catalog->registry()->find($id);
                $this->assertNotNull($declared?->rest, $id);
                $this->assertSame('/workspaces/{workspace}/square'.$path, $publishedPath, $id);
                $this->assertSame('get', $method, $id);
                $this->assertSame($declared->description, $operation['description'], $id);
                $this->assertSame([['oauth2' => ['billing:read']], ['apiToken' => []]], $operation['security'], $id);
                $this->assertSame(
                    array_map(static fn (string|array $parameter): array => is_string($parameter) ? ['$ref' => '#/components/parameters/'.$parameter] : $parameter, $declared->rest->parameters),
                    $operation['parameters'],
                    $id,
                );
                $this->assertSame('#/components/schemas/'.$component, $operation['responses']['200']['content']['application/json']['schema']['$ref'], $id);
            }
        });
    }

    /** @param callable(AgentOperationCatalog): void $assertions */
    private function withCatalog(callable $assertions): void
    {
        // Only path helpers need a container; no providers, routes or database boot.
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $assertions(new AgentOperationCatalog);
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
