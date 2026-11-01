<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopePresentation;
use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\OAuth\Credentials\GrantableScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use Tests\TestCase;

/**
 * The grouped scope payload on the credentials page, and the rule that it is
 * presentation only: the store endpoints take explicit scope ids (#582).
 */
final class ApiCredentialScopePresentationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureSigningKeys();
        $this->user = User::factory()->create(['email' => 'person-'.Str::random(8).'@synthetic.test']);
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic Presets', 'slug' => 'synthetic-presets-'.Str::random(8)]);
        $this->workspace->memberships()->create(['user_id' => $this->user->id, 'role' => 'owner']);
    }

    public function test_scope_groups_cover_exactly_the_scopes_offered_and_never_mcp_use(): void
    {
        $rest = $this->restProps();
        $offered = array_column($rest['scopes'], 'id');
        $grouped = array_merge(...array_column($rest['scope_groups'], 'scopes'));

        $this->assertEqualsCanonicalizing($offered, $grouped);
        $this->assertSame(array_values(array_unique($grouped)), $grouped);
        $this->assertNotContains(AgentApiScopes::MCP_USE, $grouped);
        $this->assertNotContains('other', array_column($rest['scope_groups'], 'id'));
        // A group with nothing offered is omitted, so there is never an empty fieldset.
        foreach ($rest['scope_groups'] as $group) {
            $this->assertNotSame([], $group['scopes']);
        }
        $admin = collect($rest['scope_groups'])->firstWhere('id', 'admin');
        $this->assertTrue($admin['sensitive']);
        $this->assertSame(AgentApiScopePresentation::ADMIN_DESCRIPTION, $admin['description']);
    }

    public function test_token_scope_groups_include_mcp_use_only_when_token_scopes_offer_it(): void
    {
        $rest = $this->restProps();
        $tokenScopes = array_column($rest['token_scopes'], 'id');
        $this->assertNotContains(AgentApiScopes::MCP_USE, $tokenScopes);
        $this->assertNotContains(AgentApiScopes::MCP_USE, array_merge(...array_column($rest['token_scope_groups'], 'scopes')));

        // An installation whose personal tokens target the MCP resource and opt in to the connection scope.
        config([
            'bherila-auth.oauth_server.credentials.resource' => 'mcp',
            'bherila-auth.oauth_server.credentials.personal_token_connection_scopes' => [AgentApiScopes::MCP_USE],
        ]);
        $rest = $this->restProps();
        $tokenScopes = array_column($rest['token_scopes'], 'id');
        $this->assertContains(AgentApiScopes::MCP_USE, $tokenScopes);
        $grouped = array_merge(...array_column($rest['token_scope_groups'], 'scopes'));
        $this->assertEqualsCanonicalizing($tokenScopes, $grouped);
        $this->assertSame(['account'], array_column(
            array_filter($rest['token_scope_groups'], static fn (array $g): bool => in_array(AgentApiScopes::MCP_USE, $g['scopes'], true)),
            'id',
        ));
        // OAuth apps are still never offered it.
        $this->assertNotContains(AgentApiScopes::MCP_USE, array_merge(...array_column($rest['scope_groups'], 'scopes')));
    }

    public function test_a_preset_appears_only_where_all_its_scopes_are_offered(): void
    {
        $rest = $this->restProps();
        $this->assertSame(
            ['read_only', 'time_logging', 'project_work', 'invoice_drafting'],
            array_column($rest['scope_presets'], 'id'),
        );
        $this->assertSame(array_column($rest['scope_presets'], 'id'), array_column($rest['token_scope_presets'], 'id'));
        $admin = collect($rest['scope_groups'])->firstWhere('id', 'admin')['scopes'];
        foreach ($rest['scope_presets'] as $preset) {
            $this->assertSame([], array_values(array_intersect($preset['scopes'], $admin)));
        }

        // Narrow what the catalog offers: presets needing a withdrawn scope disappear.
        $this->app->bind(GrantableScopes::class, static fn (): GrantableScopes => new class implements GrantableScopes
        {
            public function scopes(): array
            {
                return array_diff_key(
                    AgentApiScopes::descriptions(),
                    [AgentApiScopes::MCP_USE => 1, AgentApiScopes::TASKS_WRITE => 1, AgentApiScopes::FINANCE_READ => 1],
                );
            }
        });
        $rest = $this->restProps();
        $offered = array_column($rest['scopes'], 'id');
        $this->assertNotContains(AgentApiScopes::TASKS_WRITE, $offered);
        $ids = array_column($rest['scope_presets'], 'id');
        $this->assertNotContains('project_work', $ids);
        $this->assertNotContains('read_only', $ids);
        $this->assertContains('time_logging', $ids);
        foreach ($rest['scope_presets'] as $preset) {
            $this->assertSame([], array_values(array_diff($preset['scopes'], $offered)));
        }
    }

    public function test_the_index_carries_the_implications_for_what_each_surface_offers(): void
    {
        $rest = $this->restProps();
        $this->assertSame(['scope_implications', 'token_scope_implications'], array_values(array_intersect(['scope_implications', 'token_scope_implications'], array_keys($rest))));
        $this->assertSame([AgentApiScopes::BILLING_READ], $rest['scope_implications'][AgentApiScopes::BILLING_WRITE]);
        $this->assertSame([AgentApiScopes::FILES_READ], $rest['token_scope_implications'][AgentApiScopes::FILES_WRITE]);
        $offered = array_column($rest['scopes'], 'id');
        foreach ($rest['scope_implications'] as $scope => $required) {
            $this->assertContains($scope, $offered);
            $this->assertSame([], array_values(array_diff($required, $offered)));
        }

        // A surface that does not offer a requirement drops the entry.
        $this->app->bind(GrantableScopes::class, static fn (): GrantableScopes => new class implements GrantableScopes
        {
            public function scopes(): array
            {
                return array_diff_key(AgentApiScopes::descriptions(), [AgentApiScopes::MCP_USE => 1, AgentApiScopes::BILLING_READ => 1]);
            }
        });
        $this->assertArrayNotHasKey(AgentApiScopes::BILLING_WRITE, $this->restProps()['scope_implications']);
    }

    public function test_the_store_endpoints_accept_only_explicit_scope_ids(): void
    {
        $this->actingAs($this->user);
        foreach (['read_only', 'time_logging', 'admin', 'Admin'] as $notAScope) {
            $this->post('/account/api-credentials/tokens', ['name' => 'Preset', 'scopes' => [$notAScope], 'lifetime' => 'P30D'])->assertSessionHasErrors('scopes.0');
            $this->post('/account/api-credentials/apps', [
                'name' => 'Preset app', 'redirect_uris' => ['http://127.0.0.1:3210/callback'], 'scopes' => [$notAScope],
            ])->assertSessionHasErrors('scopes.0');
        }
        $this->assertSame(0, Passport::token()->newQuery()->count());

        // Ticking "Admin" posts the explicit scopes, which are stored as given.
        $admin = collect($this->restProps()['scope_groups'])->firstWhere('id', 'admin')['scopes'];
        $this->postJson('/account/api-credentials/tokens', ['name' => 'Admin', 'scopes' => $admin, 'lifetime' => 'P30D'])->assertCreated();
        $row = Passport::token()->newQuery()->where('user_id', $this->user->id)->sole();
        $this->assertEqualsCanonicalizing($admin, (array) $row->scopes);
    }

    /** @return array<string, mixed> */
    private function restProps(): array
    {
        return $this->actingAs($this->user)
            ->getJson('/account/api-credentials')
            ->assertOk()
            ->json('data');
    }

    private function configureSigningKeys(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $private = '';
        $this->assertTrue(openssl_pkey_export($key, $private));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        config(['passport.private_key' => $private, 'passport.public_key' => $details['key']]);
        $this->app->forgetInstance(AuthorizationServer::class);
        $this->app->forgetInstance(ResourceServer::class);
    }
}
