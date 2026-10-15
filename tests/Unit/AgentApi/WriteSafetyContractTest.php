<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class WriteSafetyContractTest extends TestCase
{
    public function test_body_contracts_and_bodyless_upload_authorizations_declare_their_safety(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $registry = (new AgentOperationCatalog)->registry();
            foreach ([
                'payments.record' => [true, true],
                'payments.correct' => [true, true],
                'invoices.square_link' => [true, true],
                'companies.square_customer.set' => [true, true],
                'clients.archive' => [true, true],
                'tasks.create' => [false, false],
                'time_entries.delete' => [true, false],
                'expenses.delete' => [true, false],
            ] as $id => [$version, $confirmation]) {
                $operation = $registry->find($id);
                $this->assertNotNull($operation);
                $this->assertSame(IdempotencyKey::HeaderAndArgument, $operation->safety->idempotencyKey, $id);
                $this->assertSame($version, $operation->safety->expectedVersion, $id);
                $this->assertSame($confirmation, $operation->safety->confirm, $id);
            }
            foreach (['attachments.upload_url', 'expenses.receipts.upload_url'] as $id) {
                $operation = $registry->find($id);
                $this->assertNotNull($operation);
                $this->assertSame(Effect::LocalWrite, $operation->effect);
                $this->assertContains('mcp.switch:mcp.write', $operation->requirement->flags);
                $this->assertNotContains('mcp.switch:mcp.read', $operation->requirement->flags);
                $this->assertNull($operation->input);
                $this->assertSame(IdempotencyKey::None, $operation->safety->idempotencyKey);
                $this->assertFalse($operation->safety->expectedVersion);
                $this->assertFalse($operation->safety->confirm);
                $this->assertNotNull($operation->safety->note);
            }
            $read = $registry->find('invoices.get');
            $this->assertNotNull($read);
            $this->assertNull($read->input);
            $this->assertSame(IdempotencyKey::None, $read->safety->idempotencyKey);
            $this->assertFalse($read->safety->expectedVersion);
            $this->assertFalse($read->safety->confirm);
            $this->assertNull($read->safety->note);
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
