<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\AgentMcpReadTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class PeriodPreviewOperationContractTest extends TestCase
{
    public function test_the_preview_is_an_idempotent_manager_only_read_of_one_named_period_on_both_transports(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            $operation = $catalog->registry()->find('invoices.preview_period');
            $this->assertNotNull($operation);
            $this->assertSame(Effect::Read, $operation->effect);
            $this->assertTrue($operation->idempotent);
            $this->assertTrue($catalog->isManagerOnly($operation->id));
            $this->assertSame(['billing:read'], $operation->requirement->scopes);
            $this->assertSame([AgentMcpReadTools::class, 'invoicesPreviewPeriod'], $operation->mcp->handler);
            $this->assertSame('GET', $operation->rest->method);
            $this->assertSame('/workspaces/{workspace}/agreements/{agreement}/invoices/preview', $operation->rest->path);
            $this->assertSame([
                'WorkspaceId',
                ['name' => 'agreement', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                ['name' => 'period_start', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date']],
            ], $operation->rest->parameters);
            // A read: no write cutover may withhold it.
            $this->assertSame(['x-svc-flags' => [], 'x-mcp-tool' => 'invoices.preview_period'], $operation->extensions);
            $this->assertSame('InvoicePeriodPreviewResponse', AgentApiResponseSchemaCatalog::operationComponent($operation->id));
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
