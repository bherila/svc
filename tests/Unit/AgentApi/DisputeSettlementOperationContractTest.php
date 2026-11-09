<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\AgentMcpWriteTools;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/** The two dispute settlements (docs/ux-overhaul.md 7b″), declared without booting the application. */
final class DisputeSettlementOperationContractTest extends TestCase
{
    public function test_settlements_declare_their_transport_authority_and_switches(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        try {
            $catalog = new AgentOperationCatalog;
            foreach ([
                'time_entries.settle_dispute' => [
                    'timeEntriesSettleDispute', 'Settle a disputed time entry', false,
                    'agent-api.v1.time-entries.settle_dispute', '/workspaces/{workspace}/time-entries/{entry}/dispute/settle',
                    ['WorkspaceId', 'TimeEntryId', 'IdempotencyKey'], ['time:write', 'time:read'], 'agent.writes.time_entries',
                    ['AGENT_API_TIME_ENTRY_WRITES_ENABLED'],
                    ' Answering is open to the person who logged the time or a workspace owner or admin; writing off needs the permission to approve time on its project.',
                ],
                'expenses.settle_dispute' => [
                    'expensesSettleDispute', 'Settle a disputed expense', true,
                    'agent-api.v1.expenses.settle_dispute', '/workspaces/{workspace}/expenses/{expense}/dispute/settle',
                    ['WorkspaceId', 'ExpenseId', 'IdempotencyKey'], ['expenses:read', 'expenses:write'], 'agent.writes.expenses',
                    ['AGENT_API_WRITES_ENABLED', 'AGENT_API_EXPENSE_WRITES_ENABLED'],
                    ' Workspace owners and admins only.',
                ],
            ] as $id => [$handler, $title, $managerOnly, $route, $path, $parameters, $scopes, $cutover, $svcFlags, $who]) {
                $operation = $catalog->registry()->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertSame($title, $operation->title, $id);
                $this->assertSame(AgentOperationCatalog::SETTLE_DESCRIPTION.$who, $operation->description, $id);
                $this->assertSame(Effect::LocalWrite, $operation->effect, $id);
                $this->assertTrue($operation->idempotent, $id);
                $this->assertSame($managerOnly, $catalog->isManagerOnly($id), $id);
                $this->assertSame([AgentMcpWriteTools::class, $handler], $operation->mcp->handler, $id);
                $this->assertSame('POST', $operation->rest->method, $id);
                $this->assertSame($route, $operation->rest->routeName, $id);
                $this->assertSame($path, $operation->rest->path, $id);
                $this->assertSame($parameters, $operation->rest->parameters, $id);
                $this->assertSame([200], $operation->rest->successStatuses, $id);
                $this->assertSame($scopes, $operation->requirement->scopes, $id);
                $this->assertSame([$cutover, 'mcp', 'mcp.switch:mcp.write', 'mcp.switch:'.$id], $operation->requirement->flags, $id);
                $this->assertSame(['x-svc-flags' => $svcFlags, 'x-mcp-tool' => $id], $operation->extensions, $id);
                $this->assertSame(IdempotencyKey::HeaderAndArgument, $operation->safety->idempotencyKey, $id);
                $this->assertTrue($operation->safety->expectedVersion, $id);
            }
        } finally {
            Container::setInstance($previous);
        }
    }
}
