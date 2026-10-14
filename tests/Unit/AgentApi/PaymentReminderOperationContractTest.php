<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\AgentMcpPaymentReminderTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/** The payment reminder operations' declarations (#438), checked without booting the application. */
final class PaymentReminderOperationContractTest extends TestCase
{
    public function test_reminder_operations_declare_their_transport_authority_and_cutover(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            $registry = $catalog->registry();
            $write = ['billing:write', 'billing:read'];
            foreach ([
                'invoices.reminders.list' => ['list', Effect::Read, 'GET', 'invoices.reminders.index', '/workspaces/{workspace}/invoices/{invoice}/reminders', ['billing:read'], null, 'mcp.read', 'InvoiceRemindersResponse', ['WorkspaceId', 'InvoiceId']],
                'invoices.send_reminder' => ['send', Effect::ExternalWrite, 'POST', 'invoices.reminders.store', '/workspaces/{workspace}/invoices/{invoice}/reminders', $write, AgentDeploymentFlags::INVOICES, 'mcp.write', 'InvoiceReminderResponse', ['WorkspaceId', 'InvoiceId', 'IdempotencyKey']],
                'companies.payment_reminders.get' => ['company', Effect::Read, 'GET', 'clients.payment-reminders.show', '/workspaces/{workspace}/clients/{client}/payment-reminders', ['billing:read'], null, 'mcp.read.clients', 'CompanyPaymentRemindersResponse', ['WorkspaceId', 'client']],
                'companies.payment_reminders.set' => ['setCompany', Effect::LocalWrite, 'PUT', 'clients.payment-reminders.update', '/workspaces/{workspace}/clients/{client}/payment-reminders', $write, AgentDeploymentFlags::CLIENTS, 'mcp.write.clients', 'CompanyPaymentRemindersResponse', ['WorkspaceId', 'client', 'IdempotencyKey']],
            ] as $id => [$handler, $effect, $method, $route, $path, $scopes, $cutover, $group, $response, $parameters]) {
                $operation = $registry->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertSame($effect, $operation->effect, $id);
                $this->assertTrue($operation->idempotent, $id);
                $this->assertTrue($catalog->isManagerOnly($id), $id);
                $this->assertSame([AgentMcpPaymentReminderTools::class, $handler], $operation->mcp->handler, $id);
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
                $named = array_map(static fn (mixed $parameter): mixed => is_array($parameter) ? ($parameter['name'] ?? null) : $parameter, $operation->rest->parameters ?? []);
                $this->assertSame($parameters, $named, $id);
            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
