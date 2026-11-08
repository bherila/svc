<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\AgentMcpAutopayTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/** The autopay operations' declarations (#636), checked without booting the application. */
final class AutopayOperationContractTest extends TestCase
{
    public function test_autopay_operations_declare_their_transport_authority_confirmation_and_cutover(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            $registry = $catalog->registry();
            $write = ['billing:write', 'billing:read'];
            foreach ([
                'companies.autopay.get' => ['company', Effect::Read, 'GET', 'clients.autopay.show', '/workspaces/{workspace}/clients/{client}/autopay', ['billing:read'], null, 'mcp.read.clients', 'CompanyAutopayResponse', ['WorkspaceId', 'client'], false],
                'companies.autopay.set' => ['setCompany', Effect::ExternalWrite, 'PUT', 'clients.autopay.update', '/workspaces/{workspace}/clients/{client}/autopay', $write, AgentDeploymentFlags::CLIENTS, 'mcp.write.clients', 'CompanyAutopayResponse', ['WorkspaceId', 'client', 'IdempotencyKey'], true],
                'invoices.autopay.get' => ['invoice', Effect::Read, 'GET', 'invoices.autopay.show', '/workspaces/{workspace}/invoices/{invoice}/autopay', ['billing:read'], null, 'mcp.read', 'InvoiceAutopayResponse', ['WorkspaceId', 'InvoiceId'], false],
                'invoices.autopay.skip' => ['skip', Effect::LocalWrite, 'POST', 'invoices.autopay.skip', '/workspaces/{workspace}/invoices/{invoice}/autopay/skip', $write, AgentDeploymentFlags::INVOICES, 'mcp.write', 'InvoiceAutopayResponse', ['WorkspaceId', 'InvoiceId', 'IdempotencyKey'], true],
                'invoices.autopay.resolve' => ['resolve', Effect::LocalWrite, 'POST', 'invoices.autopay.resolve', '/workspaces/{workspace}/invoices/{invoice}/autopay/resolve', $write, AgentDeploymentFlags::INVOICES, 'mcp.write', 'InvoiceAutopayResponse', ['WorkspaceId', 'InvoiceId', 'IdempotencyKey'], true],
            ] as $id => [$handler, $effect, $method, $route, $path, $scopes, $cutover, $group, $response, $parameters, $confirms]) {
                $operation = $registry->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertSame($effect, $operation->effect, $id);
                $this->assertTrue($operation->idempotent, $id);
                $this->assertTrue($catalog->isManagerOnly($id), $id);
                $this->assertSame([AgentMcpAutopayTools::class, $handler], $operation->mcp->handler, $id);
                $this->assertSame($method, $operation->rest->method, $id);
                $this->assertSame('agent-api.v1.'.$route, $operation->rest->routeName, $id);
                $this->assertSame($path, $operation->rest->path, $id);
                $this->assertSame([200], $operation->rest->successStatuses, $id);
                $this->assertSame($scopes, $operation->requirement->scopes, $id);
                $this->assertSame([...($cutover === null ? [] : [$cutover]), 'mcp', 'mcp.switch:'.$group, 'mcp.switch:'.$id], $operation->requirement->flags, $id);
                $this->assertSame($cutover === null ? IdempotencyKey::None : IdempotencyKey::HeaderAndArgument, $operation->safety->idempotencyKey, $id);
                $this->assertSame($cutover !== null, $operation->safety->expectedVersion, $id);
                $this->assertSame($confirms, $operation->safety->confirm, $id);
                $this->assertSame($response, AgentApiResponseSchemaCatalog::operationComponent($id), $id);
                $this->assertSame($id, $operation->extensions['x-mcp-tool'] ?? null, $id);
                $named = array_map(static fn (mixed $parameter): mixed => is_array($parameter) ? ($parameter['name'] ?? null) : $parameter, $operation->rest->parameters ?? []);
                $this->assertSame($parameters, $named, $id);
            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
