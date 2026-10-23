<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\AgentRestDocumentation;
use App\Services\Mcp\AgentMcpSquareMirrorTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/**
 * The declarations behind the #458 reconcile operation: what it is, who may
 * call it, and the shapes it promises, without booting the application. The
 * workspace audits are pinned in {@see BillingAuditOperationContractTest}.
 */
final class SquareOperabilityOperationContractTest extends TestCase
{
    public function test_square_reconcile_is_a_manager_only_idempotent_write_that_reads_square(): void
    {
        $this->withCatalog(function (AgentOperationCatalog $catalog): void {
            $id = 'invoices.square_reconcile';
            $operation = $catalog->registry()->find($id);
            $this->assertNotNull($operation);
            $this->assertSame(Effect::LocalWrite, $operation->effect);
            $this->assertTrue($operation->isIdempotent());
            $this->assertTrue($catalog->isManagerOnly($id));
            $this->assertTrue($catalog->readsExternalSystem($id));
            $this->assertSame([AgentMcpSquareMirrorTools::class, 'reconcileInvoice'], $operation->mcp?->handler);
            $this->assertSame([AgentDeploymentFlags::WRITES, AgentDeploymentFlags::SQUARE, AgentDeploymentFlags::MCP, AgentDeploymentFlags::MCP_SWITCH.'mcp.write', AgentDeploymentFlags::MCP_SWITCH.$id], $operation->requirement->flags);
            $this->assertNotNull($operation->rest);
            $this->assertSame(['POST', '/workspaces/{workspace}/invoices/{invoice}/square-reconcile', [200]], [$operation->rest->method, $operation->rest->path, $operation->rest->successStatuses]);
            $documented = AgentRestDocumentation::operations()[$id];
            $this->assertSame([
                'method' => 'POST',
                'scopes' => ['billing:write', 'billing:read'],
                'parameters' => ['WorkspaceId', 'InvoiceId', 'IdempotencyKey'],
                'request' => 'InvoiceSquareReconcileRequest',
                'response' => 'InvoiceSquareResponse',
                'response_description' => 'The invoice after reconciliation',
            ], array_diff_key($documented, ['description' => true]));
            // Generated from the declaration: the cutover it nests in, and the tool that reaches it.
            $this->assertSame(['x-svc-flags' => ['AGENT_API_WRITES_ENABLED'], 'x-mcp-tool' => $id], $operation->extensions);
            $this->assertStringContainsString('Nothing is created, changed or paid in Square', $documented['description']);
        });
    }

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
