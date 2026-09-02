<?php

namespace Tests\Unit\Mcp;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Services\Mcp\Context\McpAuthorizer;
use App\Services\Mcp\Context\McpPrincipal;
use App\Services\Mcp\Context\McpRequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class McpAuthorizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_every_declared_scope_from_authenticated_context(): void
    {
        $user = User::factory()->create();
        $context = new McpRequestContext(new McpPrincipal(
            AgentPrincipal::query()->findOrFail($user->id),
            'credential',
            'client',
            ['mcp:use', 'projects:read'],
        ), 'request-id');

        $authorizer = new McpAuthorizer;
        $this->assertTrue($authorizer->allowsScopes($context, ['mcp:use', 'projects:read']));
        $this->assertFalse($authorizer->allowsScopes($context, ['mcp:use', 'billing:read']));
    }
}
