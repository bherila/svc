<?php

namespace Tests\Unit\Mcp;

use Bherila\McpLaravelBridge\Http\McpHttpPolicy;
use Tests\TestCase;

final class McpHttpPolicyRegistrationTest extends TestCase
{
    public function test_the_shared_http_policy_is_registered_from_application_configuration(): void
    {
        config([
            'app.url' => 'https://svc.example.test:8443',
            'bherila-auth.oauth_server.resource' => 'https://oauth.example.test/api/v1',
            'agent_api.mcp_allowed_origins' => [null, '', 'https://client.example.test'],
            'agent_api.mcp_allowed_hosts' => [null, '', 'proxy.example.test:9443'],
            'agent_api.mcp_max_body_bytes' => 1234,
            'agent_api.mcp_max_response_body_bytes' => 5678,
        ]);

        $policy = app(McpHttpPolicy::class);

        $this->assertSame(['https://client.example.test'], $policy->origins());
        $this->assertSame(['proxy.example.test:9443'], $policy->hosts());
        $this->assertSame(1234, $policy->maxRequestBodyBytes);
        $this->assertSame(5678, $policy->maxResponseBodyBytes);

        $this->app->forgetInstance(McpHttpPolicy::class);
        config([
            'app.url' => 'https://svc.example.test:8443',
            'bherila-auth.oauth_server.resource' => 'https://svc.example.test:8443/api/v1',
            'agent_api.mcp_allowed_hosts' => [],
        ]);

        $this->assertSame(
            ['svc.example.test:8443'],
            app(McpHttpPolicy::class)->hosts(),
        );
    }
}
