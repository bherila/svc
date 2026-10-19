<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\AgentMcpWriteTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/**
 * The writes MCP reaches over REST (#459), as their declarations document
 * them: binding, cutovers, extensions, the refusals by status and, for the
 * time log and the issue, the read view. Checked without booting the app.
 */
final class RoutedWriteOperationContractTest extends TestCase
{
    private const array STATUSES = [401, 403, 404, 409, 422, 429];

    public function test_each_routed_write_documents_its_binding_flags_refusals_and_view(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $registry = (new AgentOperationCatalog)->registry();
            $writes = ['AGENT_API_WRITES_ENABLED'];
            $time = ['AGENT_API_TIME_ENTRY_WRITES_ENABLED'];
            $expenses = ['AGENT_API_WRITES_ENABLED', 'AGENT_API_EXPENSE_WRITES_ENABLED'];
            foreach ([
                'tasks.create' => ['tasksCreate', 'POST', '/workspaces/{workspace}/projects/{project}/tasks', [201], AgentDeploymentFlags::WRITES, $writes, ['WorkspaceId', 'ProjectId', 'IdempotencyKey']],
                'tasks.update' => ['tasksUpdate', 'PATCH', '/workspaces/{workspace}/tasks/{task}', [200], AgentDeploymentFlags::WRITES, $writes, ['WorkspaceId', 'TaskId', 'IdempotencyKey']],
                'time_entries.log' => ['timeEntriesLog', 'POST', '/workspaces/{workspace}/time-entries', [201], AgentDeploymentFlags::TIME_ENTRIES, $time, ['WorkspaceId', 'IdempotencyKey', 'view']],
                'time_entries.update' => ['timeEntriesUpdate', 'PATCH', '/workspaces/{workspace}/time-entries/{entry}', [200], AgentDeploymentFlags::TIME_ENTRIES, $time, ['WorkspaceId', 'TimeEntryId', 'IdempotencyKey']],
                'time_entries.delete' => ['timeEntriesDelete', 'DELETE', '/workspaces/{workspace}/time-entries/{entry}', [200], AgentDeploymentFlags::TIME_ENTRIES, $time, ['WorkspaceId', 'TimeEntryId', 'IdempotencyKey']],
                'expenses.log' => ['expensesLog', 'POST', '/workspaces/{workspace}/expenses', [201], AgentDeploymentFlags::EXPENSES, $expenses, ['WorkspaceId', 'IdempotencyKey']],
                'expenses.update' => ['expensesUpdate', 'PATCH', '/workspaces/{workspace}/expenses/{expense}', [200], AgentDeploymentFlags::EXPENSES, $expenses, ['WorkspaceId', 'ExpenseId', 'IdempotencyKey']],
                'expenses.delete' => ['expensesDelete', 'DELETE', '/workspaces/{workspace}/expenses/{expense}', [200], AgentDeploymentFlags::EXPENSES, $expenses, ['WorkspaceId', 'ExpenseId', 'IdempotencyKey']],
                'invoices.issue' => ['invoicesIssue', 'POST', '/workspaces/{workspace}/invoices/{invoice}/issue', [200], AgentDeploymentFlags::INVOICES, ['AGENT_API_WRITES_ENABLED', 'AGENT_API_INVOICE_WRITES_ENABLED'], ['WorkspaceId', 'InvoiceId', 'IdempotencyKey', 'view']],
            ] as $id => [$handler, $method, $path, $statuses, $cutover, $flags, $parameters]) {
                $operation = $registry->find($id);
                $this->assertNotNull($operation, $id);
                $rest = $operation->rest;
                $this->assertNotNull($rest, $id);
                $this->assertSame([AgentMcpWriteTools::class, $handler], $operation->mcp?->handler, $id);
                $this->assertSame([$method, $path, $statuses], [$rest->method, $rest->path, $rest->successStatuses], $id);
                $this->assertSame($cutover, $operation->requirement->flags[0], $id);
                $this->assertSame(IdempotencyKey::HeaderAndArgument, $operation->safety->idempotencyKey, $id);
                $this->assertSame(['x-svc-flags' => $flags, 'x-mcp-tool' => $id], $operation->extensions, $id);
                $this->assertIsString($rest->description, $id);
                $this->assertNotSame('', $rest->description, $id);

                $this->assertSame(self::STATUSES, array_keys($rest->responses), $id);
                foreach ($rest->responses as $status => $response) {
                    $this->assertIsArray($response, "{$id} {$status}");
                    $this->assertNotSame('', $response['description'] ?? '', "{$id} {$status}");
                    $this->assertSame(['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]], $response['content'] ?? null, "{$id} {$status}");
                }

                $named = array_map(static fn (mixed $parameter): mixed => is_array($parameter) ? ($parameter['name'] ?? null) : $parameter, $rest->parameters ?? []);
                $this->assertSame($parameters, $named, $id);
                if (in_array('view', $parameters, true)) {
                    $view = $rest->parameters[array_search('view', $named, true)];
                    $this->assertIsArray($view);
                    $this->assertSame(['query', ['type' => 'string', 'enum' => ['read']]], [$view['in'], $view['schema']], $id);
                    $this->assertStringContainsString($id === 'invoices.issue' ? 'billing:read' : 'time:read', $view['description'], $id);
                    $this->assertStringContainsString('not part of the request an Idempotency-Key binds', $view['description'], $id);
                }
            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
