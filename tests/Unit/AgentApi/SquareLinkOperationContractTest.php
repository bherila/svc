<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\AgentMcpSquareLinkTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/** The Square link operations' declarations (#438), checked without booting the application. */
final class SquareLinkOperationContractTest extends TestCase
{
    public function test_square_operations_declare_their_transport_authority_and_cutover(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            $registry = $catalog->registry();
            $write = ['billing:write', 'billing:read'];
            foreach ([
                'invoices.square_link' => ['link', Effect::LocalWrite, 'POST', 'invoices.square-link.store', '/workspaces/{workspace}/invoices/{invoice}/square-link', $write, AgentDeploymentFlags::INVOICES, 'mcp.write', 'InvoiceSquareResponse'],
                'invoices.square_unlink' => ['unlink', Effect::Destructive, 'DELETE', 'invoices.square-link.destroy', '/workspaces/{workspace}/invoices/{invoice}/square-link', $write, AgentDeploymentFlags::INVOICES, 'mcp.write', 'InvoiceSquareResponse'],
                'companies.square_customer.get' => ['customer', Effect::Read, 'GET', 'clients.square-customer.show', '/workspaces/{workspace}/clients/{client}/square-customer', ['billing:read'], null, 'mcp.read.clients', 'CompanySquareStateResponse'],
                'companies.square_customer.set' => ['setCustomer', Effect::LocalWrite, 'PUT', 'clients.square-customer.update', '/workspaces/{workspace}/clients/{client}/square-customer', $write, AgentDeploymentFlags::CLIENTS, 'mcp.write.clients', 'CompanySquareStateResponse'],
                'companies.square_customer.clear' => ['clearCustomer', Effect::Destructive, 'DELETE', 'clients.square-customer.destroy', '/workspaces/{workspace}/clients/{client}/square-customer', $write, AgentDeploymentFlags::CLIENTS, 'mcp.write.clients', 'CompanySquareStateResponse'],
                'agreements.square_series.set' => ['markAgreement', Effect::LocalWrite, 'PUT', 'agreements.square-series.update', '/workspaces/{workspace}/agreements/{agreement}/square-series', $write, AgentDeploymentFlags::CLIENTS, 'mcp.write.clients', 'AgreementResponse'],
                'agreements.square_series.clear' => ['clearAgreement', Effect::Destructive, 'DELETE', 'agreements.square-series.destroy', '/workspaces/{workspace}/agreements/{agreement}/square-series', $write, AgentDeploymentFlags::CLIENTS, 'mcp.write.clients', 'AgreementResponse'],
            ] as $id => [$handler, $effect, $method, $route, $path, $scopes, $cutover, $group, $response]) {
                $operation = $registry->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertSame($effect, $operation->effect, $id);
                $this->assertTrue($operation->idempotent, $id);
                $this->assertTrue($catalog->isManagerOnly($id), $id);
                $this->assertSame([AgentMcpSquareLinkTools::class, $handler], $operation->mcp->handler, $id);
                $this->assertSame($method, $operation->rest->method, $id);
                $this->assertSame('agent-api.v1.'.$route, $operation->rest->routeName, $id);
                $this->assertSame($path, $operation->rest->path, $id);
                $this->assertSame([200], $operation->rest->successStatuses, $id);
                $this->assertSame($scopes, $operation->requirement->scopes, $id);
                $this->assertSame([...($cutover === null ? [] : [$cutover]), 'mcp', 'mcp.switch:'.$group, 'mcp.switch:'.$id], $operation->requirement->flags, $id);
                $this->assertSame($cutover === null ? IdempotencyKey::None : IdempotencyKey::HeaderAndArgument, $operation->safety->idempotencyKey, $id);
                $this->assertSame($cutover !== null, $operation->safety->expectedVersion, $id);
                $this->assertSame($response, AgentApiResponseSchemaCatalog::operationComponent($id), $id);
                $this->assertSame($id, $operation->extensions['x-mcp-tool'] ?? null, $id);
                $this->assertSame(match ($cutover) {
                    null => [],
                    AgentDeploymentFlags::INVOICES => ['AGENT_API_WRITES_ENABLED', 'AGENT_API_INVOICE_WRITES_ENABLED'],
                    default => ['AGENT_API_WRITES_ENABLED', 'AGENT_API_CLIENT_WRITES_ENABLED'],
                }, $operation->extensions['x-svc-flags'] ?? null, $id);
            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
