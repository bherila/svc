<?php

namespace Tests\Feature\Mcp;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\McpCredentialSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Credentials\CredentialFingerprint;
use App\Services\Mcp\McpCredentialSettings;
use App\Services\Mcp\Toolsets\McpToolsetSelection;
use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * A credential's own "Show all tools" setting (docs/mcp.md, "Opting out").
 *
 * It is the fallback for hosted connector UIs that can neither send
 * `X-SVC-Toolsets: all` nor handle `notifications/tools/list_changed`. At
 * `initialize` it counts exactly as the header does: either turns the full list
 * on and neither turns it off. It belongs to one credential, grants nothing,
 * and applies to the next `initialize`; a session already open keeps its own.
 */
final class McpShowAllToolsSettingTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.mcp_enabled' => true, 'agent_api.mcp_feature_flags' => [], 'agent_api.writes_enabled' => true, 'bherila-auth.oauth_server.enabled' => true]);
        foreach (['time_entry', 'invoice', 'expense', 'payment', 'client', 'collaboration', 'project', 'proposal', 'workspace', 'file'] as $group) {
            config(['agent_api.'.$group.'_writes_enabled' => true]);
        }
        $this->owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Setting Workspace', 'slug' => 'synthetic-setting']);
        $workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Setting Client', 'slug' => 'synthetic-setting-client']);
        ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic Setting Project']);
    }

    public function test_with_the_setting_off_a_credential_gets_the_core_set(): void
    {
        $this->connection($this->owner, $this->client());

        $initialize = $this->mcp($this->initializeMessage(), headers: [McpToolsetSelection::CONNECT_HEADER => 'progressive'])->assertOk();
        $names = $this->toolNames((string) $initialize->headers->get('Mcp-Session-Id'));

        sort($names);
        $this->assertSame(['context.get', 'projects.list', 'search', 'time_entries.log', 'toolsets.disable', 'toolsets.enable', 'toolsets.list'], $names);
        $this->assertTrue($initialize->json('result.capabilities.tools.listChanged'));
        $this->assertStringContainsString('toolsets.list', (string) $initialize->json('result.instructions'));
    }

    public function test_with_the_setting_on_a_credential_gets_the_full_list_and_no_list_changed(): void
    {
        $client = $this->client();
        $this->connection($this->owner, $client);
        $full = $this->toolNames($this->initialize());
        $this->setting()->setForConnection($this->owner->id, $client, true);

        // No header at all: the setting alone asks for the full list.
        $initialize = $this->mcp($this->initializeMessage(), headers: [McpToolsetSelection::CONNECT_HEADER => ''])->assertOk();
        $names = $this->toolNames((string) $initialize->headers->get('Mcp-Session-Id'));

        $this->assertGreaterThan(50, count($names));
        $this->assertSame($full, $names);
        $this->assertNotTrue($initialize->json('result.capabilities.tools.listChanged'));
        $this->assertStringNotContainsString('toolsets.list', (string) $initialize->json('result.instructions'));
    }

    public function test_the_header_still_works_with_the_setting_off(): void
    {
        $client = $this->client();
        $this->connection($this->owner, $client);
        $this->setting()->setForConnection($this->owner->id, $client, true);
        $this->setting()->setForConnection($this->owner->id, $client, false);

        $initialize = $this->mcp($this->initializeMessage(), headers: [McpToolsetSelection::CONNECT_HEADER => 'all'])->assertOk();

        $this->assertGreaterThan(50, count($this->toolNames((string) $initialize->headers->get('Mcp-Session-Id'))));
        $this->assertNotTrue($initialize->json('result.capabilities.tools.listChanged'));
    }

    public function test_neither_the_header_nor_the_setting_can_turn_the_other_off(): void
    {
        $client = $this->client();
        $this->connection($this->owner, $client);
        $this->setting()->setForConnection($this->owner->id, $client, true);

        // A header that does not opt in leaves the setting in force.
        $initialize = $this->mcp($this->initializeMessage(), headers: [McpToolsetSelection::CONNECT_HEADER => 'progressive'])->assertOk();

        $this->assertGreaterThan(50, count($this->toolNames((string) $initialize->headers->get('Mcp-Session-Id'))));
    }

    public function test_one_credentials_setting_never_affects_another_even_for_the_same_user(): void
    {
        $first = $this->client();
        $second = $this->client();
        $this->setting()->setForConnection($this->owner->id, $first, true);

        $this->connection($this->owner, $first);
        $this->assertGreaterThan(50, count($this->toolNames($this->initializeProgressive())));

        $this->connection($this->owner, $second);
        $this->assertCount(7, $this->toolNames($this->initializeProgressive()));

        // Two personal tokens of one person share a client; the setting is the token's own.
        $tokenClient = $this->client(personal: true);
        $on = $this->connection($this->owner, $tokenClient);
        $off = $this->connection($this->owner, $tokenClient);
        $this->setting()->setForToken($this->owner->id, $on, true);

        $this->connection($this->owner, $tokenClient, tokenId: $on);
        $this->assertGreaterThan(50, count($this->toolNames($this->initializeProgressive())));
        $this->connection($this->owner, $tokenClient, tokenId: $off);
        $this->assertCount(7, $this->toolNames($this->initializeProgressive()));

        // Another person connected through the same app is a different connection.
        $other = User::factory()->create();
        $this->connection($other, $first);
        $this->assertCount(7, $this->toolNames($this->initializeProgressive()));
    }

    public function test_a_connection_keeps_its_setting_across_a_refresh_that_issues_a_new_access_token(): void
    {
        $client = $this->client();
        $this->setting()->setForConnection($this->owner->id, $client, true);

        $this->connection($this->owner, $client);
        $this->connection($this->owner, $client);

        $this->assertGreaterThan(50, count($this->toolNames($this->initializeProgressive())));
    }

    public function test_a_session_already_open_keeps_its_selection_until_the_next_initialize(): void
    {
        $client = $this->client();
        $this->connection($this->owner, $client);
        $open = $this->initializeProgressive();

        $this->setting()->setForConnection($this->owner->id, $client, true);

        $this->assertCount(7, $this->toolNames($open));
        $this->assertGreaterThan(50, count($this->toolNames($this->initializeProgressive())));

        // And turning it off does not narrow a session that already lists everything.
        $full = $this->initializeProgressive();
        $this->setting()->setForConnection($this->owner->id, $client, false);
        $this->assertGreaterThan(50, count($this->toolNames($full)));
        $this->assertCount(7, $this->toolNames($this->initializeProgressive()));
    }

    public function test_the_setting_grants_nothing(): void
    {
        $client = $this->client();
        $this->connection($this->owner, $client, [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ]);
        $limited = $this->toolNames($this->initialize());
        $this->setting()->setForConnection($this->owner->id, $client, true);

        $this->assertSame($limited, $this->toolNames($this->initializeProgressive()));
        $this->assertNotContains('invoices.issue', $this->toolNames($this->initializeProgressive()));
    }

    public function test_the_owner_changes_a_connected_apps_setting_and_the_change_is_audited(): void
    {
        $client = $this->client();
        $this->grant($this->owner, $client);
        Log::spy();

        $this->actingAs($this->owner)->putJson("/account/mcp-credentials/connections/{$client}", ['show_all_tools' => true])
            ->assertOk()->assertJsonPath('data.show_all_tools', true);

        $this->assertTrue($this->setting()->showsAllTools($this->owner->id, 'unrelated', $client));
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'mcp_credential.show_all_tools_changed'
            && $context['subject_id'] === $this->owner->public_id
            && $context['credential_fingerprint'] === CredentialFingerprint::of($client)
            && $context['show_all_tools'] === true
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $client))->once();

        $this->putJson("/account/mcp-credentials/connections/{$client}", ['show_all_tools' => false])
            ->assertOk()->assertJsonPath('data.show_all_tools', false);
        $this->assertFalse($this->setting()->showsAllTools($this->owner->id, 'unrelated', $client));
        $this->assertSame(1, McpCredentialSetting::query()->count());
    }

    public function test_the_owner_changes_a_personal_tokens_setting(): void
    {
        $tokenId = $this->personalToken($this->owner);
        $other = $this->personalToken($this->owner);

        $this->actingAs($this->owner)->putJson("/account/mcp-credentials/tokens/{$tokenId}", ['show_all_tools' => true])
            ->assertOk()->assertJsonPath('data.show_all_tools', true);

        $this->assertSame([$tokenId => true], $this->setting()->forTokens($this->owner->id, [$tokenId, $other]));
    }

    public function test_a_non_owner_cannot_change_it(): void
    {
        $client = $this->client();
        $this->grant($this->owner, $client);
        $tokenId = $this->personalToken($this->owner);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->putJson("/account/mcp-credentials/connections/{$client}", ['show_all_tools' => true])->assertNotFound();
        $this->actingAs($stranger)->putJson("/account/mcp-credentials/tokens/{$tokenId}", ['show_all_tools' => true])->assertNotFound();

        $this->assertSame(0, McpCredentialSetting::query()->count());
        $this->assertFalse($this->setting()->showsAllTools($this->owner->id, $tokenId, $client));
    }

    public function test_only_a_live_credential_of_the_signed_in_person_can_be_changed(): void
    {
        $this->actingAs($this->owner);
        $revoked = $this->client();
        $this->grant($this->owner, $revoked, revoked: true);
        $unconnected = $this->client();
        $expired = $this->personalToken($this->owner, expired: true);
        $withoutConnection = $this->personalToken($this->owner, scopes: [AgentApiScopes::IDENTITY_READ]);

        $this->putJson("/account/mcp-credentials/connections/{$revoked}", ['show_all_tools' => true])->assertNotFound();
        $this->putJson("/account/mcp-credentials/connections/{$unconnected}", ['show_all_tools' => true])->assertNotFound();
        $this->putJson('/account/mcp-credentials/connections/'.Str::uuid(), ['show_all_tools' => true])->assertNotFound();
        $this->putJson("/account/mcp-credentials/tokens/{$expired}", ['show_all_tools' => true])->assertNotFound();
        // A REST token opens no MCP connection, so there is nothing to show.
        $this->putJson("/account/mcp-credentials/tokens/{$withoutConnection}", ['show_all_tools' => true])->assertNotFound();

        $this->assertSame(0, McpCredentialSetting::query()->count());
    }

    public function test_the_request_must_say_on_or_off(): void
    {
        $client = $this->client();
        $this->grant($this->owner, $client);
        $this->actingAs($this->owner);

        $this->putJson("/account/mcp-credentials/connections/{$client}", [])->assertUnprocessable()->assertJsonValidationErrors('show_all_tools');
        $this->putJson("/account/mcp-credentials/connections/{$client}", ['show_all_tools' => 'maybe'])->assertUnprocessable();
        $this->assertSame(0, McpCredentialSetting::query()->count());
    }

    public function test_a_signed_out_visitor_cannot_change_it(): void
    {
        $client = $this->client();
        $this->grant($this->owner, $client);

        $this->putJson("/account/mcp-credentials/connections/{$client}", ['show_all_tools' => true])->assertUnauthorized();
        $this->assertSame(0, McpCredentialSetting::query()->count());
    }

    public function test_an_mcp_credential_cannot_change_it(): void
    {
        $client = $this->client();
        $this->connection($this->owner, $client);

        // Browser session only: an MCP bearer is not a signed-in person.
        $this->app['auth']->forgetGuards();
        $this->withToken('not-a-session')->putJson("/account/mcp-credentials/connections/{$client}", ['show_all_tools' => true])->assertUnauthorized();
        $this->assertSame(0, McpCredentialSetting::query()->count());
    }

    public function test_the_setup_page_lists_only_the_signed_in_persons_credentials_with_finished_urls(): void
    {
        $mine = $this->client('My Synthetic App');
        $theirs = $this->client('Their Synthetic App');
        $stranger = User::factory()->create();
        $this->grant($this->owner, $mine);
        $this->grant($stranger, $theirs);
        $this->setting()->setForConnection($this->owner->id, $mine, true);
        $token = $this->personalToken($this->owner);
        $this->personalToken($this->owner, scopes: [AgentApiScopes::IDENTITY_READ]);
        $this->personalToken($stranger);
        $workspace = Workspace::query()->sole();

        $props = $this->actingAs($this->owner)->get(route('mcp.setup', $workspace))->assertOk()->viewData('page')['props']['credentials'];

        $this->assertSame([[
            'id' => $mine, 'name' => 'My Synthetic App', 'show_all_tools' => true,
            'update_href' => "/account/mcp-credentials/connections/{$mine}",
        ]], $props['connections']);
        $this->assertSame([[
            'id' => $token, 'name' => 'Synthetic token', 'show_all_tools' => false,
            'update_href' => "/account/mcp-credentials/tokens/{$token}",
        ]], $props['tokens']);
    }

    public function test_a_credential_row_goes_when_its_credential_does(): void
    {
        $client = $this->client();
        $token = $this->personalToken($this->owner);
        $this->setting()->setForConnection($this->owner->id, $client, true);
        $this->setting()->setForToken($this->owner->id, $token, true);
        $this->assertSame(2, McpCredentialSetting::query()->count());

        Passport::client()->newQuery()->whereKey($client)->delete();
        Passport::token()->newQuery()->whereKey($token)->delete();

        $this->assertSame(0, McpCredentialSetting::query()->count());
    }

    private function setting(): McpCredentialSettings
    {
        return new McpCredentialSettings;
    }

    /** Register an OAuth client and return its id. */
    private function client(string $name = 'Synthetic MCP App', bool $personal = false): string
    {
        $id = (string) Str::uuid();
        Passport::client()->forceFill([
            'id' => $id, 'name' => $name, 'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => $personal ? ['personal_access'] : ['authorization_code', 'refresh_token'], 'revoked' => false,
        ])->save();

        return $id;
    }

    /**
     * Connect as a credential of this client: a live access token bound to the
     * MCP resource, set as the signed-in MCP caller. A new call for the same
     * client is what a refresh looks like: a new access token, the same connection.
     *
     * @param  list<string>  $scopes
     * @return string the access token id
     */
    private function connection(User $user, string $client, array $scopes = [], ?string $tokenId = null): string
    {
        $scopes = $scopes === [] ? array_keys(AgentApiScopes::descriptions()) : $scopes;
        $principal = AgentPrincipal::query()->findOrFail($user->id);
        $token = $tokenId === null ? $this->accessToken($user, $client, $scopes, 'mcp-test-'.Str::uuid()) : Passport::token()->newQuery()->findOrFail($tokenId);
        $principal->withAccessToken(new AccessToken([
            'oauth_access_token_id' => $token->id,
            'oauth_client_id' => $client,
            'oauth_scopes' => $scopes,
        ]));
        app('auth')->guard('api')->setUser($principal);
        app('auth')->shouldUse('api');

        return (string) $token->id;
    }

    /**
     * @param  list<string>  $scopes
     */
    private function accessToken(User $user, string $client, array $scopes, string $name, bool $expired = false, bool $revoked = false): Token
    {
        $token = Passport::token()->forceFill([
            'id' => Str::random(80), 'user_id' => $user->id, 'client_id' => $client, 'name' => $name, 'scopes' => $scopes,
            'revoked' => $revoked, 'resource_uri' => OAuthResourceIndicator::resource('mcp'),
            'expires_at' => $expired ? now()->subDay() : now()->addDay(),
        ]);
        $token->save();

        return $token;
    }

    /** A live grant of this client to this person, as a connected app. */
    private function grant(User $user, string $client, bool $revoked = false): void
    {
        $this->accessToken($user, $client, [AgentApiScopes::MCP_USE], 'Synthetic grant');
        if ($revoked) {
            Passport::client()->newQuery()->whereKey($client)->update(['revoked' => true]);
        }
    }

    /**
     * A personal token, as the credentials page issues one.
     *
     * @param  list<string>  $scopes
     */
    private function personalToken(User $user, array $scopes = [AgentApiScopes::MCP_USE], bool $expired = false): string
    {
        $client = Passport::client()->newQuery()->get()->first(fn ($candidate): bool => $candidate->hasGrantType('personal_access'))?->getKey() ?? $this->client('Personal', personal: true);

        return (string) $this->accessToken($user, (string) $client, $scopes, (string) config('bherila-auth.oauth_server.credentials.token_name_prefix').'Synthetic token', $expired)->id;
    }
}
