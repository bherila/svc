<?php

namespace Tests\Unit\Auth;

use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\FirstPartySession;
use App\Support\AgentApi\ProviderIdentityWrites;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesProviderIdentity;
use Tests\TestCase;

/** The resource ceilings and the provider identity hooks, without a database. */
final class ProviderIdentityWiringTest extends TestCase
{
    use FakesProviderIdentity;

    public function test_the_rest_ceiling_is_every_operation_scope_and_the_mcp_ceiling_adds_the_connection_scope(): void
    {
        $operationScopes = array_values(array_diff(array_keys(AgentApiScopes::descriptions()), [AgentApiScopes::MCP_USE]));

        $this->assertSame($operationScopes, AgentApiScopes::rest());
        $this->assertSame([AgentApiScopes::MCP_USE, ...$operationScopes], AgentApiScopes::mcp());
        $this->assertSame(AgentApiScopes::rest(), FirstPartySession::scopes());
    }

    public function test_an_mcp_tool_call_and_the_rest_route_it_dispatches_share_one_write_verification(): void
    {
        $this->assertSame(app(ProviderIdentityWrites::class), app(ProviderIdentityWrites::class));
    }

    public function test_sanctum_asks_the_provider_identity_check_about_every_token(): void
    {
        $callback = Sanctum::$accessTokenAuthenticationCallback;
        $this->assertIsCallable($callback);

        // Enforcement is off by default: Sanctum's own verdict stands either way.
        $this->assertTrue($callback(new PersonalAccessToken, true));
        $this->assertFalse($callback(new PersonalAccessToken, false));

        // While off, even a bound owner's unstamped token keeps working.
        $this->assertTrue($callback($this->tokenFor($this->owner('person-unit')), true));

        // An invalid token is never revived, whatever enforcement says.
        config(['bherila-auth.provider_identity.enabled' => true]);
        $this->assertFalse($callback(new PersonalAccessToken, false));
        $this->assertFalse($callback($this->tokenFor($this->owner('person-unit')), false));

        // On: a bound owner's token without a stamp, or with another subject's,
        // is refused before the provider is asked; an unbound owner's is left alone.
        $this->assertFalse($callback($this->tokenFor($this->owner('person-unit')), true));
        $this->assertFalse($callback($this->tokenFor($this->owner('person-unit'), ['provider_subject' => 'person-other', 'provider_generation' => 1]), true));
        $this->assertFalse($callback($this->tokenFor($this->owner('person-unit'), ['provider_subject' => 'person-unit', 'provider_generation' => -1]), true));
        $this->assertTrue($callback($this->tokenFor($this->owner(null)), true));
    }

    public function test_a_stamped_token_is_accepted_only_while_the_provider_reports_the_same_generation(): void
    {
        $this->fakeProviderIdentity();
        $callback = Sanctum::$accessTokenAuthenticationCallback;
        $this->assertIsCallable($callback);
        $owner = $this->owner('person-stamped', self::PROVIDER);

        $this->providerIdentity('person-stamped', 0);
        $this->assertTrue($callback($this->tokenFor($owner, ['provider_subject' => 'person-stamped', 'provider_generation' => 0]), true));

        $this->providerIdentity('person-stamped', 2);
        $this->travel(6)->minutes();
        $this->assertTrue($callback($this->tokenFor($owner, ['provider_subject' => 'person-stamped', 'provider_generation' => 2]), true));
        $this->assertFalse($callback($this->tokenFor($owner, ['provider_subject' => 'person-stamped', 'provider_generation' => null]), true));

        // MariaDB returns the generation as a string.
        $this->assertTrue($callback($this->tokenFor($owner, ['provider_subject' => 'person-stamped', 'provider_generation' => '2']), true));

        // A reset at the provider ends the older token: a read still rides the
        // last observation inside its window, a write asks again.
        $stamped = $this->tokenFor($owner, ['provider_subject' => 'person-stamped', 'provider_generation' => 2]);
        $this->providerIdentity('person-stamped', 3);
        $this->assertTrue($callback($stamped, true));
        $this->app['request']->setMethod('PUT');
        $this->assertFalse($callback($stamped, true));
        $this->app['request']->setMethod('GET');
        $this->assertFalse($callback($stamped, true), 'The write refreshed the shared observation');
    }

    private function owner(?string $subject, string $provider = 'test-provider'): User
    {
        config(['bherila-auth.oauth_client.provider' => $provider]);

        return (new User)->forceFill([
            'id' => 1,
            'oauth_provider' => $subject === null ? null : $provider,
            'oauth_subject' => $subject,
        ]);
    }

    /** @param array<string, mixed> $stamp */
    private function tokenFor(User $owner, array $stamp = []): PersonalAccessToken
    {
        return (new PersonalAccessToken)->forceFill($stamp)->setRelation('tokenable', $owner);
    }
}
