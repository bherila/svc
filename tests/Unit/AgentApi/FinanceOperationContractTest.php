<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\AgentMcpFinanceTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Bherila\McpLaravelBridge\Capabilities\SchemaRef;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/**
 * The finance reconciliation operations' declarations (#457) and the
 * payments.correct scopes (#473), checked without booting the application:
 * each REST binding says exactly what the checked OpenAPI document says.
 */
final class FinanceOperationContractTest extends TestCase
{
    private const array OPERATIONS = [
        'finance.invoice_payments.list' => ['list', Effect::Read, 'mcp.read', 'GET', '/workspaces/{workspace}/invoice-payments', [200], ['finance:read']],
        'finance.reconciliations.upsert' => ['upsert', Effect::LocalWrite, 'mcp.write', 'PUT', '/workspaces/{workspace}/invoice-payments/{payment}/reconciliations/{external_system}/{transaction}', [200, 201], ['finance:reconcile']],
        'finance.reconciliations.deactivate' => ['deactivate', Effect::LocalWrite, 'mcp.write', 'DELETE', '/workspaces/{workspace}/invoice-payments/{payment}/reconciliations/{external_system}/{transaction}', [200], ['finance:reconcile']],
    ];

    private ?Container $previous = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
    }

    protected function tearDown(): void
    {
        AgentApiResponseSchemaCatalog::flush();
        Container::setInstance($this->previous);
        parent::tearDown();
    }

    public function test_each_finance_operation_declares_its_transport_authority_and_safety(): void
    {
        $catalog = new AgentOperationCatalog;
        foreach (self::OPERATIONS as $id => [$handler, $effect, $group, $method, $path, $statuses, $scopes]) {
            $operation = $catalog->registry()->find($id);
            $this->assertNotNull($operation, $id);
            $this->assertSame($effect, $operation->effect, $id);
            $this->assertTrue($operation->idempotent, $id);
            $this->assertTrue($catalog->isManagerOnly($id), $id);
            $this->assertSame([AgentMcpFinanceTools::class, $handler], $operation->mcp?->handler, $id);
            $this->assertSame($scopes, $operation->requirement->scopes, $id);
            // No write cutover: only the MCP switches.
            $this->assertSame(['mcp', 'mcp.switch:'.$group, 'mcp.switch:'.$id], $operation->requirement->flags, $id);
            $this->assertSame(IdempotencyKey::None, $operation->safety->idempotencyKey, $id);
            $this->assertFalse($operation->safety->expectedVersion, $id);
            $this->assertFalse($operation->safety->confirm, $id);
            $this->assertSame($effect->readOnly() ? null : 'Idempotent by its path (payment, external system, transaction): repeating it is the same write, so it takes no idempotency key.', $operation->safety->note, $id);
            $this->assertNotNull($operation->rest, $id);
            $this->assertSame($method, $operation->rest->method, $id);
            $this->assertSame($path, $operation->rest->path, $id);
            $this->assertSame($statuses, $operation->rest->successStatuses, $id);
            $this->assertEquals(SchemaRef::responseOf($id), $operation->output, $id);
            $this->assertEquals($id === 'finance.reconciliations.upsert' ? SchemaRef::requestOf($id) : null, $operation->input, $id);
        }
        // Only what is declared manager-only is withheld from non-managers.
        $this->assertFalse($catalog->isManagerOnly('context.get'));
        $this->assertFalse($catalog->isManagerOnly('finance.unknown'));
    }

    /** Parameters, prose, refusals, scopes and extensions are the document's, field for field. */
    public function test_each_finance_binding_is_what_the_checked_document_publishes(): void
    {
        $document = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/public/openapi/svc-agent-v1.json'), true, flags: JSON_THROW_ON_ERROR);
        $registry = (new AgentOperationCatalog)->registry();
        foreach (array_keys(self::OPERATIONS) as $id) {
            $operation = $registry->find($id);
            $rest = $operation?->rest;
            $this->assertNotNull($rest, $id);
            $published = $document['paths'][$rest->path][strtolower($rest->method)];
            $this->assertSame($id, $published['operationId']);
            $this->assertSame($published['description'], $rest->description, $id);
            $this->assertStringEndsWith('The Sanctum finance token issued by svc:auth:issue-token is accepted too, its finance.read and finance.reconcile abilities standing for these scopes.', (string) $rest->description, $id);
            $this->assertSame($published['security'][0]['oauth2'], $operation->requirement->scopes, $id);
            $this->assertSame($id, $published['x-mcp-tool'], $id);
            $this->assertSame(['x-mcp-tool' => $id], $operation->extensions, $id);
            $this->assertSame($published['parameters'], array_map(
                static fn (string|array $parameter): array => is_string($parameter) ? ['$ref' => '#/components/parameters/'.$parameter] : $parameter,
                $rest->parameters ?? [],
            ), $id);
            $refusals = [];
            foreach ($rest->responses as $status => $response) {
                $refusals[(string) $status] = $response;
            }
            $this->assertSame([401, 403, 404, 422, 429], array_keys($rest->responses), $id);
            foreach ($refusals as $status => $response) {
                $this->assertSame($published['responses'][$status], $response, "{$id} {$status}");
            }
        }
    }

    /** #473: the version payments.correct checks comes from payments.list. */
    public function test_correcting_a_payment_needs_the_read_scope_as_well(): void
    {
        $operation = (new AgentOperationCatalog)->registry()->find('payments.correct');

        $this->assertNotNull($operation);
        $this->assertSame(['payments:record', 'payments:read'], $operation->requirement->scopes);
    }
}
