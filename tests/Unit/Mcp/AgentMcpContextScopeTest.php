<?php

namespace Tests\Unit\Mcp;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Services\AgentApi\AgentReadService;
use App\Services\Mcp\AgentMcpReadTools;
use App\Services\Mcp\Context\McpAccountContextResolver;
use App\Services\Mcp\Context\McpPrincipal;
use App\Services\Mcp\Context\McpRequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcp\Exception\ToolCallException;
use Tests\TestCase;

/** context.get names the scope an agent must ask for when it lacks it. */
final class AgentMcpContextScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_get_names_identity_read_when_the_scope_is_missing(): void
    {
        $tools = $this->tools(['mcp:use', 'finance:read']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('This connection lacks the identity:read scope that context.get requires.');
        $tools->context();
    }

    public function test_context_get_answers_when_identity_read_is_held(): void
    {
        $this->assertArrayHasKey('data', $this->tools(['mcp:use', 'identity:read'])->context());
    }

    /** @param list<string> $scopes */
    private function tools(array $scopes): AgentMcpReadTools
    {
        $user = User::factory()->create();
        $context = new McpRequestContext(new McpPrincipal(AgentPrincipal::query()->findOrFail($user->id), 'credential', 'client', $scopes), 'request-id');

        return new AgentMcpReadTools(app(AgentReadService::class), app(McpAccountContextResolver::class), $context);
    }
}
