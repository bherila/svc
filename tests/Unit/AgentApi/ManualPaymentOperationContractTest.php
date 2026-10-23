<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\RecordPaymentAction;
use App\Services\Mcp\AgentMcpWriteTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/** The manual payment operations' declarations (#455), checked without booting the application. */
final class ManualPaymentOperationContractTest extends TestCase
{
    public function test_manual_payment_operations_declare_their_transport_authority_and_cutover(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            $registry = $catalog->registry();
            foreach ([
                'payments.set_status' => ['paymentsSetStatus', 'payments.status', '/workspaces/{workspace}/payments/{payment}/status', 'ReceivedPaymentStatusRequest'],
                'payments.refund' => ['paymentsRefund', 'payments.refund', '/workspaces/{workspace}/payments/{payment}/refunds', 'ReceivedPaymentRefundRequest'],
                'payments.reverse_refund' => ['paymentsReverseRefund', 'payments.refunds.reverse', '/workspaces/{workspace}/payments/{payment}/refunds/{refund}/reversal', 'ReceivedPaymentRefundReversalRequest'],
            ] as $id => [$handler, $route, $path, $request]) {
                $operation = $registry->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertSame(Effect::Destructive, $operation->effect, $id);
                $this->assertTrue($operation->idempotent, $id);
                $this->assertTrue($catalog->isManagerOnly($id), $id);
                $this->assertSame([AgentMcpWriteTools::class, $handler], $operation->mcp->handler, $id);
                $this->assertNotNull($operation->rest, $id);
                $this->assertSame('POST', $operation->rest->method, $id);
                $this->assertSame('agent-api.v1.'.$route, $operation->rest->routeName, $id);
                $this->assertSame($path, $operation->rest->path, $id);
                $this->assertSame([200], $operation->rest->successStatuses, $id);
                $this->assertSame(['payments:record', 'payments:read'], $operation->requirement->scopes, $id);
                $this->assertSame([AgentDeploymentFlags::PAYMENTS, 'mcp', 'mcp.switch:mcp.write', 'mcp.switch:'.$id], $operation->requirement->flags, $id);
                $this->assertSame(IdempotencyKey::HeaderAndArgument, $operation->safety->idempotencyKey, $id);
                $this->assertTrue($operation->safety->expectedVersion, $id);
                $this->assertTrue($operation->safety->confirm, $id);
                $this->assertSame('ReceivedPaymentResponse', AgentApiResponseSchemaCatalog::operationComponent($id), $id);
                $this->assertSame($request, AgentApiResponseSchemaCatalog::catalog()->requestComponent($id), $id);
                $named = array_map(static fn (mixed $parameter): mixed => is_array($parameter) ? ($parameter['name'] ?? null) : $parameter, $operation->rest->parameters ?? []);
                $this->assertSame($id === 'payments.reverse_refund' ? ['WorkspaceId', 'PaymentId', 'refund', 'IdempotencyKey'] : ['WorkspaceId', 'PaymentId', 'IdempotencyKey'], $named, $id);
            }
            // The refund named by the reversal's path (#472) is a required UUID.
            $refund = $registry->find('payments.reverse_refund')?->rest?->parameters[2] ?? null;
            $this->assertIsArray($refund);
            $this->assertSame(['name' => 'refund', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                array_diff_key($refund, ['description' => true]));
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }

    /**
     * payments.record documents each refusal by status, as the checked
     * document publishes it, and its request closes finance_reconciliation to
     * its three fields (S6).
     */
    public function test_recording_documents_its_refusals_and_closes_the_allocation(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $document = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/public/openapi/svc-agent-v1.json'), true, flags: JSON_THROW_ON_ERROR);
            $operation = (new AgentOperationCatalog)->registry()->find('payments.record');
            $this->assertNotNull($operation);
            $rest = $operation->rest;
            $this->assertNotNull($rest);
            $this->assertSame(['payments:record', 'billing:read'], $operation->requirement->scopes);
            $this->assertSame([401, 403, 404, 409, 422, 429], array_keys($rest->responses));
            $published = $document['paths'][$rest->path]['post'];
            foreach ($rest->responses as $status => $response) {
                $this->assertSame($published['responses'][(string) $status], $response, (string) $status);
            }
            $this->assertStringContainsString('finance:reconcile', $rest->responses[403]['description']);
            $this->assertStringContainsString('pending', $rest->responses[422]['description']);
            $this->assertStringContainsString('Square', $rest->responses[422]['description']);

            $allocation = $document['components']['schemas']['ReceivedPaymentRecordRequest']['properties']['finance_reconciliation'];
            $this->assertSame(['object', 'null'], $allocation['type']);
            $this->assertFalse($allocation['additionalProperties']);
            $this->assertSame(['external_system_slug', 'external_transaction_uuid', 'allocated_amount'], $allocation['required']);
            $this->assertSame($allocation['required'], array_keys($allocation['properties']));
            $this->assertSame(RecordPaymentAction::RECONCILIATION_FIELDS, $allocation['required']);
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
