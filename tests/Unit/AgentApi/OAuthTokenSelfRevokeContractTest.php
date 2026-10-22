<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class OAuthTokenSelfRevokeContractTest extends TestCase
{
    public function test_any_authenticated_credential_can_discard_itself_only_through_bodyless_rest(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $operation = (new AgentOperationCatalog)->registry()->find('oauth.token.revoke');
            $this->assertNotNull($operation);
            $this->assertTrue($operation->requirement->authenticated);
            $this->assertFalse($operation->requirement->public);
            $this->assertSame([], $operation->requirement->scopes);
            $this->assertSame([], $operation->requirement->permissions);
            $this->assertSame([], $operation->requirement->anyPermissions);
            $this->assertSame([], $operation->requirement->flags);
            $this->assertNull($operation->requirement->group);
            $this->assertSame(Effect::Destructive, $operation->effect);
            $this->assertTrue($operation->isIdempotent());
            $this->assertNull($operation->input);
            $this->assertNull($operation->output);
            $this->assertNull($operation->mcp);
            $this->assertSame(IdempotencyKey::None, $operation->safety->idempotencyKey);
            $this->assertFalse($operation->safety->expectedVersion);
            $this->assertFalse($operation->safety->confirm);
            $this->assertSame('Revokes only the calling token family; repeating it is harmless.', $operation->safety->note);

            $rest = $operation->rest;
            $this->assertNotNull($rest);
            $this->assertSame('DELETE', $rest->method);
            $this->assertSame('/oauth/token', $rest->path);
            $this->assertSame('agent-api.v1.oauth.token.destroy', $rest->routeName);
            $this->assertSame([204], $rest->successStatuses);
            $this->assertSame([], $rest->pathParameters);
            $this->assertSame([], $rest->parameters);
            $this->assertNull($rest->requestSchema);
            $this->assertNull($rest->responseSchema);
            $this->assertSame([204 => 'Calling token family revoked'], $rest->responseDescriptions);
            $this->assertSame([401, 429], array_keys($rest->responses));
            $this->assertSame(['x-svc-flags' => []], $operation->extensions);
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
