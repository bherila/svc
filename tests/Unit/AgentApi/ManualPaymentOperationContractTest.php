<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
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
                $this->assertSame(['WorkspaceId', 'PaymentId', 'IdempotencyKey'], $named, $id);
            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
