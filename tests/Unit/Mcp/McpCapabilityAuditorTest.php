<?php

namespace Tests\Unit\Mcp;

use App\Events\McpCapabilityInvoked;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Services\Mcp\Context\McpPrincipal;
use App\Services\Mcp\Context\McpRequestContext;
use App\Services\Mcp\McpCapabilityAuditor;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Psr\Log\AbstractLogger;
use Tests\TestCase;

final class McpCapabilityAuditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_metrics_listener_failure_does_not_interrupt_safe_audit_recording(): void
    {
        $logger = new class extends AbstractLogger
        {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $entries = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->entries[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $events = new class(app()) extends Dispatcher
        {
            /**
             * @param  array<int, mixed>  $payload
             * @return never
             */
            public function dispatch($event, $payload = [], $halt = false)
            {
                throw new \RuntimeException('metrics backend details must not escape');
            }
        };
        $user = User::factory()->create();
        $principal = AgentPrincipal::query()->findOrFail($user->id);
        $context = new McpRequestContext(new McpPrincipal($principal, 'credential', 'client', ['mcp:use']), 'request-id');

        (new McpCapabilityAuditor($logger, $events))->record($context, 'projects.list', 'mcp-read', 'agent_api.read', 'success', 12);

        $this->assertSame('mcp.capability.executed', $logger->entries[0]['message']);
        $this->assertSame('mcp.capability.metrics_unavailable', $logger->entries[1]['message']);
        $this->assertSame([
            'request_id' => 'request-id',
            'capability' => 'projects.list',
            'audit_classification' => 'agent_api.read',
        ], $logger->entries[1]['context']);
        $this->assertNotContains('metrics backend details must not escape', $logger->entries[1]['context']);
    }

    public function test_an_audit_sink_failure_does_not_interrupt_metrics_recording(): void
    {
        $logger = new class extends AbstractLogger
        {
            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('audit backend details must not escape');
            }
        };
        $user = User::factory()->create();
        $principal = AgentPrincipal::query()->findOrFail($user->id);
        $context = new McpRequestContext(new McpPrincipal($principal, 'credential', 'client', ['mcp:use']), 'request-id');
        Event::fake([McpCapabilityInvoked::class]);

        (new McpCapabilityAuditor($logger, app(EventDispatcher::class)))->record($context, 'projects.list', 'mcp-read', 'agent_api.read', 'success', 12);

        Event::assertDispatched(McpCapabilityInvoked::class, static fn (McpCapabilityInvoked $event): bool => $event->capability === 'projects.list');
    }
}
