<?php

namespace Tests\Feature\Mcp;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Services\Mcp\Context\McpPrincipalResolver;
use App\Services\Mcp\Context\McpPrincipalResolverInterface;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Token;
use Tests\TestCase;

final class McpPrincipalResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_authenticated_credential_facts_not_request_arguments(): void
    {
        $principal = AgentPrincipal::query()->findOrFail(User::factory()->create()->id);
        $principal->withAccessToken($this->token($principal));
        $request = Request::create('/api/v1/mcp', 'POST', ['client_id' => 'untrusted-client']);
        $request->setUserResolver(fn (): AgentPrincipal => $principal);

        $resolved = app(McpPrincipalResolver::class)->resolve($request);

        $this->assertSame($principal->id, $resolved->subject->id);
        $this->assertStringStartsWith('credential-', $resolved->credentialId);
        $this->assertSame('trusted-client', $resolved->clientId);
        $this->assertSame(['mcp:use', 'billing:read'], $resolved->scopes);
    }

    public function test_interface_resolves_the_existing_passport_backed_implementation(): void
    {
        $this->assertInstanceOf(McpPrincipalResolver::class, app(McpPrincipalResolverInterface::class));
    }

    public function test_it_rejects_expired_revoked_wrong_subject_wrong_audience_or_wrong_client_credentials(): void
    {
        $otherUser = User::factory()->create();
        foreach ([
            [['expires_at' => now()->subSecond()], null],
            [['revoked' => true], null],
            [['user_id' => $otherUser->id], null],
            [['resource_uri' => 'https://other.example/api/v1'], null],
            [[], 'different-client'],
        ] as [$overrides, $authenticatedClientId]) {
            $principal = AgentPrincipal::query()->findOrFail(User::factory()->create()->id);
            $principal->withAccessToken($this->token($principal, $overrides, $authenticatedClientId));
            $request = Request::create('/api/v1/mcp', 'POST');
            $request->setUserResolver(fn (): AgentPrincipal => $principal);

            try {
                app(McpPrincipalResolver::class)->resolve($request);
                $this->fail('An invalid credential must not resolve an MCP principal.');
            } catch (AuthenticationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return AccessToken<mixed>
     */
    private function token(AgentPrincipal $principal, array $overrides = [], ?string $authenticatedClientId = null): AccessToken
    {
        $token = new Token($overrides + [
            'id' => 'credential-'.Str::uuid(),
            'user_id' => $principal->id,
            'client_id' => 'trusted-client',
            'scopes' => ['mcp:use', 'billing:read'],
            'revoked' => false,
            'resource_uri' => OAuthResourceIndicator::resource(),
            'expires_at' => now()->addMinute(),
        ]);
        $token->save();

        return new AccessToken([
            'oauth_access_token_id' => $token->id,
            'oauth_client_id' => $authenticatedClientId ?? $token->client_id,
            'oauth_scopes' => $token->scopes,
        ]);
    }
}
