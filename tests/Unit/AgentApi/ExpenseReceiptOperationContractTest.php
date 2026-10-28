<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\AgentMcpWriteTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Tests\TestCase;

final class ExpenseReceiptOperationContractTest extends TestCase
{
    public function test_receipt_delete_publishes_the_exact_versioned_confirmed_expense_authority_contract(): void
    {
        $catalog = new AgentOperationCatalog;
        $operation = $catalog->registry()->find('expenses.receipts.delete');
        $this->assertNotNull($operation);
        $this->assertSame(Effect::Destructive, $operation->effect);
        $this->assertTrue($operation->isIdempotent());
        $this->assertTrue($catalog->isManagerOnly($operation->id));
        $this->assertSame(['expenses:read', 'expenses:write'], $operation->requirement->scopes);
        $this->assertSame([AgentDeploymentFlags::EXPENSES, 'mcp', 'mcp.switch:mcp.write', 'mcp.switch:expenses.receipts.delete'], $operation->requirement->flags);
        $this->assertSame(IdempotencyKey::HeaderAndArgument, $operation->safety->idempotencyKey);
        $this->assertTrue($operation->safety->confirm);
        $this->assertTrue($operation->safety->expectedVersion);
        $this->assertSame([AgentMcpWriteTools::class, 'expenseReceiptsDelete'], $operation->mcp->handler);
        $rest = $operation->rest;
        $this->assertNotNull($rest);
        $this->assertSame('DELETE', $rest->method);
        $this->assertSame('/workspaces/{workspace}/expenses/{expense}/receipts/{receipt}', $rest->path);
        $this->assertSame('agent-api.v1.expense-receipts.destroy', $rest->routeName);
        $this->assertSame([200], $rest->successStatuses);
        $document = json_decode((string) file_get_contents(public_path('openapi/svc-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $published = $document['paths'][$rest->path]['delete'];
        $this->assertSame($operation->id, $published['operationId']);
        $this->assertSame($published['description'], $rest->description);
        $this->assertSame($published['description'], $operation->description);
        $this->assertSame($published['parameters'], array_map(static fn (string|array $parameter): array => is_string($parameter) ? ['$ref' => '#/components/parameters/'.$parameter] : $parameter, $rest->parameters));
        $this->assertSame([409], array_keys($rest->responses));
        $this->assertSame($published['responses']['409'], $rest->responses[409]);
        $body = AgentApiResponseSchemaCatalog::requestForOperation($operation->id);
        $this->assertFalse($body['additionalProperties']);
        $this->assertSame(['expected_version', 'expected_parent_version', 'confirm'], $body['required']);
        $this->assertTrue($body['properties']['confirm']['const']);
        $receipt = AgentApiResponseSchemaCatalog::schema('ExpenseReceipt');
        $this->assertContains('version', $receipt['required']);
        $this->assertContains('lifecycle_state', $receipt['required']);
    }
}
