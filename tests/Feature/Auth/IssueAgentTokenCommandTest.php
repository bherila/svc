<?php

namespace Tests\Feature\Auth;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Credentials\AgentTokenKind;
use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\FakesProviderIdentity;
use Tests\TestCase;

/**
 * The operator's agent credential for one exact person: issued from a shell,
 * accepted where an OAuth-connected agent's would be, never broader than the
 * scopes named and the person's own workspace roles, and revocable by name.
 */
final class IssueAgentTokenCommandTest extends TestCase
{
    use CallsMcp;
    use FakesProviderIdentity;
    use RefreshDatabase;

    /** @var list<array{message: string, context: array<mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProviderIdentity(enforced: false);
        $this->app->forgetInstance(AuthorizationServer::class);
        $this->app->forgetInstance(ResourceServer::class);
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = ['message' => $event->message, 'context' => $event->context];
        });
    }

    public function test_a_rest_token_works_on_the_rest_api_with_only_its_scopes_and_the_person_s_own_roles(): void
    {
        [$user, $workspace] = $this->member('rest-owner');
        $elsewhere = Workspace::query()->create(['name' => 'Someone else', 'slug' => 'someone-else-'.Str::lower(Str::random(6))]);

        [$exit, $output] = $this->issue($user, 'Operator REST', [AgentApiScopes::IDENTITY_READ, AgentApiScopes::PROJECTS_READ, AgentApiScopes::IDENTITY_READ]);

        $this->assertSame(0, $exit);
        $issued = $this->decode($output);
        $this->assertSame(['token', 'expires_at', 'name', 'scopes', 'resource'], array_keys($issued));
        $this->assertSame('Operator REST', $issued['name']);
        $this->assertSame([AgentApiScopes::IDENTITY_READ, AgentApiScopes::PROJECTS_READ], $issued['scopes']);
        $this->assertSame(OAuthResourceIndicator::resource('rest'), $issued['resource']);

        $row = Passport::token()->newQuery()->sole();
        $this->assertSame($user->id, (int) $row->user_id);
        $this->assertSame('operator-agent-token: Operator REST', $row->name);
        $this->assertSame([AgentApiScopes::IDENTITY_READ, AgentApiScopes::PROJECTS_READ], $row->scopes);
        $this->assertSame(OAuthResourceIndicator::resource('rest'), $row->resource_uri);
        $this->assertTrue(Carbon::parse($issued['expires_at'])->equalTo($row->expires_at));

        $this->bearer($issued['token'])->getJson('/api/v1/context')->assertOk();
        $this->bearer($issued['token'])->getJson("/api/v1/workspaces/{$workspace->public_id}/projects")->assertOk();
        // The person's roles still decide which workspace it reaches.
        $this->bearer($issued['token'])->getJson("/api/v1/workspaces/{$elsewhere->public_id}/projects")->assertNotFound();
        // A scope it was not issued is refused, though the person could grant it.
        $this->bearer($issued['token'])->getJson("/api/v1/workspaces/{$workspace->public_id}/time-entries")->assertForbidden();
        // Bound to REST: it cannot open an MCP connection.
        $this->bearer($issued['token'])->mcp($this->initializeMessage())->assertUnauthorized();
    }

    public function test_an_mcp_token_connects_with_only_its_scopes(): void
    {
        [$user, $workspace] = $this->member('mcp-owner');

        [$exit, $output] = $this->issue($user, 'Operator MCP', [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ]);

        $this->assertSame(0, $exit);
        $issued = $this->decode($output);
        $this->assertSame(OAuthResourceIndicator::resource('mcp'), $issued['resource']);
        $this->assertSame(OAuthResourceIndicator::resource('mcp'), Passport::token()->newQuery()->sole()->resource_uri);

        $this->bearer($issued['token']);
        $session = $this->initialize();
        $context = $this->callTool($session, 'context.get', []);
        $this->assertFalse($context['result']['isError'] ?? true);
        $this->assertNotContains('projects.list', $this->toolNames($session));
        $refused = $this->mcp(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => [
            'name' => 'projects.list',
            'arguments' => ['workspace_id' => $workspace->public_id],
        ]], $session)->json();
        $this->assertTrue(isset($refused['error']) || ($refused['result']['isError'] ?? false), 'An unrequested scope must be refused.');
        $this->assertArrayNotHasKey('structuredContent', $refused['result'] ?? []);

        // Bound to MCP: it cannot call the REST API.
        $this->bearer($issued['token'])->getJson('/api/v1/context')->assertUnauthorized();
    }

    public function test_the_lifetime_defaults_to_an_hour_and_is_capped_at_four_hours(): void
    {
        [$user] = $this->member('ttl-owner');
        $lifetime = Passport::$personalAccessTokensExpireIn;

        foreach (['Default' => [[], 60], 'Longest' => [['--ttl' => '240'], 240], 'Shortest' => [['--ttl' => '1'], 1]] as $name => [$options, $minutes]) {
            // The signed expiry comes from the OAuth server's own clock.
            $before = time();
            [$exit, $output] = $this->issue($user, $name, [AgentApiScopes::IDENTITY_READ], $options);
            $after = time();
            $this->assertSame(0, $exit, $name);
            $expiresAt = Carbon::parse($this->decode($output)['expires_at'])->getTimestamp();
            $this->assertGreaterThanOrEqual($before + $minutes * 60, $expiresAt, $name);
            $this->assertLessThanOrEqual($after + $minutes * 60, $expiresAt, $name);
        }

        foreach (['241', '0', '-5', '1.5', 'ten', ''] as $ttl) {
            [$exit, $output] = $this->issue($user, 'Too long '.$ttl, [AgentApiScopes::IDENTITY_READ], ['--ttl' => $ttl]);
            $this->assertSame(2, $exit, "ttl {$ttl}");
            $this->assertStringNotContainsString('"token"', $output);
        }
        $this->assertSame(3, Passport::token()->newQuery()->count());

        // The lifetime never leaks into the next personal token this process issues.
        $this->assertSame($lifetime, Passport::$personalAccessTokensExpireIn);
    }

    public function test_unknown_scopes_names_and_users_are_refused_without_issuing(): void
    {
        [$user] = $this->member('refusals');

        $cases = [
            [$user->public_id, 'Unknown scope', [AgentApiScopes::IDENTITY_READ, 'finance.read']],
            [$user->public_id, 'Wildcard', ['*']],
            [$user->public_id, 'No scopes', []],
            [$user->public_id, '   ', [AgentApiScopes::IDENTITY_READ]],
            [$user->public_id, "Line\nbreak", [AgentApiScopes::IDENTITY_READ]],
            // Within the stored column once the reserved prefix is added.
            [$user->public_id, str_repeat('n', 256 - strlen(AgentTokenKind::Operator->namePrefix())), [AgentApiScopes::IDENTITY_READ]],
            ['not-a-uuid', 'Bad id', [AgentApiScopes::IDENTITY_READ]],
            [(string) $user->id, 'Internal id', [AgentApiScopes::IDENTITY_READ]],
        ];
        foreach ($cases as [$publicId, $name, $scopes]) {
            $exit = Artisan::call('svc:auth:issue-agent-token', ['user_public_id' => $publicId, 'name' => $name, 'scopes' => $scopes]);
            $this->assertSame(2, $exit, json_encode([$publicId, $name, $scopes]));
        }

        $exit = Artisan::call('svc:auth:issue-agent-token', ['user_public_id' => (string) Str::uuid(), 'name' => 'Nobody', 'scopes' => [AgentApiScopes::IDENTITY_READ]]);
        $this->assertSame(1, $exit);

        $this->assertSame(0, Passport::token()->newQuery()->count());
    }

    public function test_a_provider_bound_person_is_stamped_and_an_inactive_identity_is_refused(): void
    {
        $this->fakeProviderIdentity(enforced: true);
        $user = $this->boundUser('person-agent-token');
        $this->providerIdentity('person-agent-token', 5);

        [$exit, $output] = $this->issue($user, 'Bound MCP', [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ]);
        $this->assertSame(0, $exit);
        $issued = $this->decode($output);
        $row = Passport::token()->newQuery()->sole();
        $this->assertSame('person-agent-token', $row->provider_subject);
        $this->assertSame(5, (int) $row->provider_generation);

        $this->bearer($issued['token']);
        $session = $this->initialize();
        $this->assertFalse($this->callTool($session, 'context.get', [])['result']['isError'] ?? true);

        $inactive = $this->boundUser('person-agent-inactive');
        $this->disableProviderIdentity('person-agent-inactive');
        [$exit] = $this->issue($inactive, 'Refused', [AgentApiScopes::IDENTITY_READ]);
        $this->assertSame(1, $exit);

        $this->takeProviderOffline();
        [$exit] = $this->issue($user, 'Outage', [AgentApiScopes::IDENTITY_READ]);
        $this->assertSame(1, $exit);

        $this->assertSame(1, Passport::token()->newQuery()->count());
    }

    public function test_revoking_by_name_ends_the_token_at_once_and_fails_closed_on_duplicates(): void
    {
        [$user, $workspace] = $this->member('revoke-owner');
        $first = $this->decode($this->issue($user, 'Shared name', [AgentApiScopes::PROJECTS_READ])[1])['token'];
        $second = $this->decode($this->issue($user, 'Shared name', [AgentApiScopes::PROJECTS_READ])[1])['token'];
        $other = $this->decode($this->issue($user, 'Kept', [AgentApiScopes::PROJECTS_READ])[1])['token'];
        $path = "/api/v1/workspaces/{$workspace->public_id}/projects";
        $this->bearer($first)->getJson($path)->assertOk();

        $this->assertSame(1, Artisan::call('svc:auth:revoke-agent-token', ['user_public_id' => $user->public_id, 'name' => 'Shared name']));
        $this->bearer($first)->getJson($path)->assertOk();

        $this->assertSame(0, Artisan::call('svc:auth:revoke-agent-token', ['user_public_id' => $user->public_id, 'name' => 'Shared name', '--all' => true]));
        $this->assertSame(['revoked' => 2], $this->decode(Artisan::output()));
        $this->bearer($first)->getJson($path)->assertUnauthorized();
        $this->bearer($second)->getJson($path)->assertUnauthorized();
        $this->bearer($other)->getJson($path)->assertOk();

        // Already revoked: nothing left to match.
        $this->assertSame(1, Artisan::call('svc:auth:revoke-agent-token', ['user_public_id' => $user->public_id, 'name' => 'Shared name', '--all' => true]));
        // Another person's token of the same name is out of reach.
        [$stranger] = $this->member('stranger');
        $this->assertSame(1, Artisan::call('svc:auth:revoke-agent-token', ['user_public_id' => $stranger->public_id, 'name' => 'Kept']));
        $this->bearer($other)->getJson($path)->assertOk();

        $this->assertSame(0, Artisan::call('svc:auth:revoke-agent-token', ['user_public_id' => $user->public_id, 'name' => 'Kept']));
        $this->bearer($other)->getJson($path)->assertUnauthorized();

        $this->assertSame(2, Artisan::call('svc:auth:revoke-agent-token', ['user_public_id' => 'not-a-uuid', 'name' => 'Kept']));
        $this->assertSame(1, Artisan::call('svc:auth:revoke-agent-token', ['user_public_id' => (string) Str::uuid(), 'name' => 'Kept']));
    }

    public function test_the_json_line_is_written_raw_so_a_name_with_markup_round_trips(): void
    {
        [$user] = $this->member('markup');

        [$exit, $output] = $this->issue($user, '<info>Support</info>', [AgentApiScopes::IDENTITY_READ]);
        $this->assertSame(0, $exit);
        $this->assertSame('<info>Support</info>', $this->decode($output)['name']);
        $this->assertSame(0, Artisan::call('svc:auth:revoke-agent-token', ['user_public_id' => $user->public_id, 'name' => '<info>Support</info>']));
    }

    public function test_nothing_is_issued_while_oauth_issuance_is_switched_off(): void
    {
        [$user] = $this->member('switched-off');
        config(['bherila-auth.oauth_server.enabled' => false]);

        foreach ([[AgentApiScopes::IDENTITY_READ], [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ]] as $scopes) {
            [$exit, $output] = $this->issue($user, 'Switched off', $scopes);
            $this->assertSame(1, $exit);
            $this->assertStringNotContainsString('"token"', $output);
        }
        $this->assertSame(0, Passport::token()->newQuery()->count());
        $this->assertSame(0, Passport::client()->newQuery()->count());
    }

    public function test_revocation_never_reaches_a_token_this_command_did_not_issue(): void
    {
        [$user, $workspace] = $this->member('provenance');
        $principal = AgentPrincipal::query()->findOrFail($user->id);
        app(ClientRepository::class)->createPersonalAccessGrantClient('Synthetic personal client', 'agent-principals');
        // A browser-created personal token and an OAuth-flow token, each stored
        // under a name an operator could type.
        $browser = $principal->createToken('api-token: Laptop', [AgentApiScopes::PROJECTS_READ]);
        $other = $principal->createToken('Laptop', [AgentApiScopes::PROJECTS_READ]);

        $this->assertSame(1, Artisan::call('svc:auth:revoke-agent-token', ['user_public_id' => $user->public_id, 'name' => 'api-token: Laptop']));
        $this->assertSame(1, Artisan::call('svc:auth:revoke-agent-token', ['user_public_id' => $user->public_id, 'name' => 'Laptop', '--all' => true]));
        $this->assertSame(0, Passport::token()->newQuery()->where('revoked', true)->count());

        $issued = $this->decode($this->issue($user, 'Laptop', [AgentApiScopes::PROJECTS_READ])[1]);
        $this->assertSame('Laptop', $issued['name']);
        $this->assertSame(0, Artisan::call('svc:auth:revoke-agent-token', ['user_public_id' => $user->public_id, 'name' => 'Laptop']));
        $this->assertSame(['revoked' => 1], $this->decode(Artisan::output()));
        $this->bearer($issued['token'])->getJson("/api/v1/workspaces/{$workspace->public_id}/projects")->assertUnauthorized();
        $this->assertFalse((bool) Passport::token()->newQuery()->whereKey($browser->accessTokenId)->value('revoked'));
        $this->assertFalse((bool) Passport::token()->newQuery()->whereKey($other->accessTokenId)->value('revoked'));
    }

    public function test_an_expired_token_of_the_same_name_is_neither_a_duplicate_nor_revoked(): void
    {
        [$user, $workspace] = $this->member('reused-name');
        $this->issue($user, 'Reused', [AgentApiScopes::PROJECTS_READ]);
        Passport::token()->newQuery()->update(['expires_at' => now()->subMinute()]);
        $current = $this->decode($this->issue($user, 'Reused', [AgentApiScopes::PROJECTS_READ])[1])['token'];

        $this->assertSame(0, Artisan::call('svc:auth:revoke-agent-token', ['user_public_id' => $user->public_id, 'name' => 'Reused']));
        $this->assertSame(['revoked' => 1], $this->decode(Artisan::output()));
        $this->bearer($current)->getJson("/api/v1/workspaces/{$workspace->public_id}/projects")->assertUnauthorized();

        // Only an expired match left: nothing live to revoke.
        $this->assertSame(1, Artisan::call('svc:auth:revoke-agent-token', ['user_public_id' => $user->public_id, 'name' => 'Reused', '--all' => true]));
    }

    public function test_the_token_is_printed_once_and_never_logged_while_issue_and_revoke_are_audited(): void
    {
        [$user] = $this->member('audit-owner');

        [$exit, $output] = $this->issue($user, 'Audited', [AgentApiScopes::IDENTITY_READ]);
        $this->assertSame(0, $exit);
        $token = $this->decode($output)['token'];
        $this->assertSame(1, substr_count($output, $token));
        $this->assertStringNotContainsString($user->email, $output);

        Artisan::call('svc:auth:revoke-agent-token', ['user_public_id' => $user->public_id, 'name' => 'Audited']);
        $this->assertStringNotContainsString($token, Artisan::output());

        $messages = array_column($this->logged, 'message');
        $this->assertContains('agent_token.issued', $messages);
        $this->assertContains('agent_token.revoked', $messages);
        $tokenId = (string) Passport::token()->newQuery()->sole()->getKey();
        foreach ($this->logged as $entry) {
            $serialized = json_encode($entry, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($token, $serialized);
            $this->assertStringNotContainsString($tokenId, $serialized);
            $this->assertStringNotContainsString($user->email, $serialized);
        }
        $issued = $this->logged[array_search('agent_token.issued', $messages, true)]['context'];
        $this->assertSame($user->public_id, $issued['subject_id']);
        $this->assertSame('Audited', $issued['name']);
        $this->assertSame([AgentApiScopes::IDENTITY_READ], $issued['scopes']);
        $this->assertSame(hash('sha256', $tokenId), $issued['credential_fingerprint']);
    }

    /**
     * One issuer (#456): the deployment smoke and the operator draw on the
     * same personal access client, so neither changes the client the other is
     * issued from, and neither leaves its lifetime behind for the next issue.
     */
    public function test_the_deployment_smoke_and_the_operator_share_one_client_and_leave_the_lifetime_alone(): void
    {
        [$user] = $this->member('shared-client');
        $lifetime = Passport::$personalAccessTokensExpireIn;

        $this->assertSame(0, $this->issue($user, 'Before smoke', [AgentApiScopes::IDENTITY_READ])[0]);
        $this->assertSame(0, Artisan::call('svc:mcp:deploy-smoke-credentials', ['--ttl' => '5']));
        $smoke = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($lifetime, Passport::$personalAccessTokensExpireIn);
        [$exit, $output] = $this->issue($user, 'After smoke', [AgentApiScopes::IDENTITY_READ]);
        $this->assertSame(0, $exit);

        $this->assertSame(1, Passport::client()->newQuery()->count());
        $this->assertSame(1, Passport::token()->newQuery()->distinct()->count('client_id'));
        // Each lifetime is the one its own issue asked for.
        $this->assertEqualsWithDelta(time() + 300, Carbon::parse($smoke['expires_at'])->getTimestamp(), 5);
        $this->assertEqualsWithDelta(time() + 3600, Carbon::parse($this->decode($output)['expires_at'])->getTimestamp(), 5);
        $smokeExpiries = Passport::token()->newQuery()->where('user_id', '!=', $user->id)->pluck('expires_at');
        $this->assertCount(2, $smokeExpiries);
        foreach ($smokeExpiries as $expiresAt) {
            $this->assertEqualsWithDelta(time() + 300, Carbon::parse($expiresAt)->getTimestamp(), 5);
        }

        $this->assertSame(2, Artisan::call('svc:mcp:deploy-smoke-credentials', ['--ttl' => '241']));
        $this->assertSame(0, Artisan::call('svc:mcp:deploy-smoke-credentials', ['--ttl' => '5']));
        $this->assertSame(1, Passport::client()->newQuery()->count());

        // The smoke's audit names its own actor, by fingerprint only.
        $smokeIssues = array_values(array_filter($this->logged, static fn (array $entry): bool => $entry['message'] === 'agent_token.issued' && $entry['context']['actor'] === 'deploy-smoke'));
        $this->assertCount(4, $smokeIssues);
        $this->assertSame('authorized', $smokeIssues[0]['context']['name']);
        $this->assertStringNotContainsString($smoke['authorized_token'], json_encode($this->logged, JSON_THROW_ON_ERROR));
    }

    /**
     * The emergency path, audited like every other revocation: who, whose,
     * how many, and fingerprints - never a token, its id or an email.
     */
    public function test_emergency_revocation_ends_every_agent_token_a_person_holds_and_is_audited(): void
    {
        [$user, $workspace] = $this->member('emergency');
        [$stranger] = $this->member('emergency-stranger');
        $rest = $this->decode($this->issue($user, 'Rest', [AgentApiScopes::PROJECTS_READ])[1])['token'];
        $mcp = $this->decode($this->issue($user, 'Mcp', [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ])[1])['token'];
        $kept = $this->decode($this->issue($stranger, 'Rest', [AgentApiScopes::IDENTITY_READ])[1])['token'];
        $ids = Passport::token()->newQuery()->where('user_id', $user->id)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

        $this->assertSame(0, Artisan::call('svc:auth:revoke-connections', ['user_public_id' => $user->public_id]));
        $this->assertSame(['access_tokens' => 2, 'refresh_tokens' => 0, 'authorization_codes' => 0, 'unattributed_refresh_tokens' => 0], $this->decode(Artisan::output()));

        $this->bearer($rest)->getJson("/api/v1/workspaces/{$workspace->public_id}/projects")->assertUnauthorized();
        $this->bearer($mcp)->mcp($this->initializeMessage())->assertUnauthorized();
        $this->bearer($kept)->getJson('/api/v1/context')->assertOk();

        $entries = array_values(array_filter($this->logged, static fn (array $entry): bool => $entry['message'] === 'agent_token.revoked'));
        $this->assertCount(1, $entries);
        $context = $entries[0]['context'];
        $this->assertSame('console', $context['actor']);
        $this->assertSame($user->public_id, $context['subject_id']);
        $this->assertSame('connections', $context['selection']);
        $this->assertSame(2, $context['revoked']);
        $this->assertSame(0, $context['refresh_tokens_revoked']);
        $this->assertSame(0, $context['authorization_codes_revoked']);
        $this->assertArrayNotHasKey('client_id', $context);
        $this->assertEqualsCanonicalizing(array_map(static fn (string $id): string => hash('sha256', $id), $ids), $context['credential_fingerprints']);
        $serialized = json_encode($this->logged, JSON_THROW_ON_ERROR);
        foreach ([$rest, $mcp, $kept, $user->email, ...$ids] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    /** @return array{0: User, 1: Workspace} */
    private function member(string $label): array
    {
        $user = User::factory()->create(['email' => $label.'@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic '.$label, 'slug' => 'synthetic-'.$label.'-'.Str::lower(Str::random(6))]);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);

        return [$user, $workspace];
    }

    /**
     * @param  list<string>  $scopes
     * @param  array<string, string>  $options
     * @return array{0: int, 1: string}
     */
    private function issue(User $user, string $name, array $scopes, array $options = []): array
    {
        $exit = Artisan::call('svc:auth:issue-agent-token', [
            'user_public_id' => $user->public_id,
            'name' => $name,
            'scopes' => $scopes,
            ...$options,
        ]);

        return [$exit, Artisan::output()];
    }

    /** @return array<string, mixed> */
    private function decode(string $output): array
    {
        return json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
    }

    private function bearer(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }
}
