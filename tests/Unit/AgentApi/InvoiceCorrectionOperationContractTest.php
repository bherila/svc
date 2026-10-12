<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\AgentMcpReadTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class InvoiceCorrectionOperationContractTest extends TestCase
{
    public function test_evidence_is_an_idempotent_manager_only_read_on_both_transports(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            $operation = $catalog->registry()->find('invoices.corrections.get');
            $this->assertNotNull($operation);
            $this->assertSame(Effect::Read, $operation->effect);
            $this->assertTrue($operation->idempotent);
            $this->assertTrue($catalog->isManagerOnly($operation->id));
            $this->assertSame(['billing:read'], $operation->requirement->scopes);
            $this->assertSame([AgentMcpReadTools::class, 'invoiceCorrection'], $operation->mcp->handler);
            $this->assertSame('GET', $operation->rest->method);
            $this->assertSame('/workspaces/{workspace}/invoices/{invoice}/corrections/{correction}', $operation->rest->path);
            $this->assertContains('WorkspaceId', $operation->rest->parameters);
            $this->assertContains('InvoiceId', $operation->rest->parameters);
            $this->assertContains('correction', array_column(array_filter($operation->rest->parameters, is_array(...)), 'name'));
            $this->assertSame('InvoiceCorrectionResponse', AgentApiResponseSchemaCatalog::operationComponent($operation->id));
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
