<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentInlineSchemas;
use App\Services\AgentApi\Operations\AgentRestDocumentation;
use Tests\TestCase;

/**
 * `capacity_ledger.get` takes the same arguments on REST as on MCP (#366): one
 * agreement in one workspace and a 1-60 month window defaulting to 12, and the
 * REST operation names its MCP tool.
 */
final class CapacityLedgerContractTest extends TestCase
{
    public function test_rest_and_mcp_declare_the_same_window_and_name_each_other(): void
    {
        $rest = AgentRestDocumentation::operations()['capacity_ledger.get'];
        $mcp = AgentInlineSchemas::capacityLedgerInput();

        $this->assertSame('GET', $rest['method']);
        $this->assertSame(['billing:read'], $rest['scopes']);
        $this->assertSame('CapacityLedgerResponse', $rest['response']);
        $this->assertSame([
            'WorkspaceId',
            ['name' => 'agreement', 'in' => 'path', 'required' => true, 'schema' => $mcp['properties']['agreement_id']],
            ['name' => 'months', 'in' => 'query', 'schema' => $mcp['properties']['months']],
        ], $rest['parameters']);
        $this->assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'default' => 12], $mcp['properties']['months']);
    }
}
