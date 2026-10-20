<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentInlineSchemas;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\AgentMcpDeferredBuyDownTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class DeferredBuyDownOperationContractTest extends TestCase
{
    public function test_the_preview_is_manager_only_and_the_write_requires_idempotency_and_a_backlog_version(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            $preview = $catalog->registry()->find('deferred_backlog.preview');
            $create = $catalog->registry()->find('invoices.create_deferred_buydown');
            $this->assertNotNull($preview);
            $this->assertNotNull($create);
            $this->assertSame(Effect::Read, $preview->effect);
            $this->assertSame(Effect::LocalWrite, $create->effect);
            foreach ([$preview, $create] as $operation) {
                $this->assertTrue($catalog->isManagerOnly($operation->id));
                $this->assertTrue($operation->idempotent);
                $this->assertNotSame('', $operation->title);
                $this->assertNotSame('', $operation->description);
            }
            $this->assertSame(['billing:read'], $preview->requirement->scopes);
            $this->assertSame(['billing:write', 'billing:read'], $create->requirement->scopes);
            $this->assertSame([AgentMcpDeferredBuyDownTools::class, 'preview'], $preview->mcp->handler);
            $this->assertSame([AgentMcpDeferredBuyDownTools::class, 'create'], $create->mcp->handler);
            $this->assertSame('GET', $preview->rest->method);
            $this->assertSame('POST', $create->rest->method);
            $this->assertSame('/workspaces/{workspace}/agreements/{agreement}/deferred-backlog/preview', $preview->rest->path);
            $this->assertSame('/workspaces/{workspace}/agreements/{agreement}/deferred-backlog/invoices', $create->rest->path);
            $this->assertSame([], $preview->extensions['x-svc-flags']);
            $this->assertSame(['AGENT_API_WRITES_ENABLED', 'AGENT_API_INVOICE_WRITES_ENABLED'], $create->extensions['x-svc-flags']);
            $this->assertSame(IdempotencyKey::HeaderAndArgument, $create->safety->idempotencyKey);
            $this->assertTrue($create->safety->expectedVersion);
            $this->assertFalse($create->safety->confirm);
            $this->assertSame(AgentInlineSchemas::deferredBuyDownPreviewInput(), $preview->input);
            $this->assertSame('DeferredBuyDownPreviewResponse', AgentApiResponseSchemaCatalog::operationComponent($preview->id));
            $this->assertSame('InvoiceMutationResponse', AgentApiResponseSchemaCatalog::operationComponent($create->id));
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }

    public function test_the_preview_names_only_public_paths_and_the_same_bounded_selection_fields_as_creation(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $schema = AgentInlineSchemas::deferredBuyDownPreviewInput();
            $this->assertSame('object', $schema['type']);
            $this->assertFalse($schema['additionalProperties']);
            $this->assertSame(['workspace_id', 'agreement_id'], $schema['required']);
            $this->assertSame([
                'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
                'agreement_id' => ['type' => 'string', 'format' => 'uuid'],
                'hours' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 1000000],
                'amount' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647],
                'round_up' => ['type' => 'boolean', 'default' => false],
                'discount_amount' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 2147483647, 'default' => 0],
                'discount_reason' => ['type' => ['string', 'null'], 'maxLength' => 1000],
            ], $schema['properties']);
            $this->assertSame([
                ['required' => ['hours'], 'not' => ['required' => ['amount']]],
                ['required' => ['amount'], 'not' => ['required' => ['hours']]],
            ], $schema['oneOf']);
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
