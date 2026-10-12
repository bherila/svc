<?php

namespace Tests\Feature\AgentApi;

use App\Models\AccessAuditEvent;
use App\Models\AgentMutationAudit;
use App\Models\AgentMutationReceipt;
use App\Models\ClientCompany;
use App\Models\ClientPortalInvitation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Access\PortalInvitations;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\PortalAccessScope;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationError;
use BWH\Auth\Testing\FakeApplicationInvitations;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsAccessFixtures;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * Portal invitations over REST and MCP (#430): the same boundary and receipts
 * as the settings page, and never the acceptance link.
 */
final class AgentPortalInvitationTest extends TestCase
{
    use BuildsAccessFixtures;
    use CallsMcp;
    use RefreshDatabase;

    private const array SCOPES = ['mcp:use', 'clients:read', 'clients:write'];

    private FakeApplicationInvitations $provider;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.client_writes_enabled' => true,
            'bherila-auth.oauth_client.provider' => 'example-provider', 'svc.portal_invitations.enabled' => true]);
        $this->provider = FakeApplicationInvitations::swap();
    }

    public static function transports(): array
    {
        return ['REST' => ['rest'], 'MCP' => ['mcp']];
    }

    public static function writeCases(): array
    {
        $cases = [];
        foreach (['rest', 'mcp'] as $transport) {
            foreach (['create', 'resend', 'revoke'] as $kind) {
                $cases[$transport.' '.$kind] = [$transport, $kind];
            }
        }

        return $cases;
    }

    #[DataProvider('transports')]
    public function test_managers_invite_list_resend_and_revoke_without_ever_receiving_the_link(string $transport): void
    {
        [$workspace, $owner, $company, $project] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);

        $created = $this->success($this->operation($transport, 'create', $workspace, $company, ['email' => 'Invitee@Client.example.test', 'scope' => 'projects', 'projects' => [$project->public_id], 'confirm' => true], key: 'synthetic-create'), 'create');
        $this->provider->assertCreated('invitee@client.example.test', actor: (string) $owner->oauth_subject);
        $invitation = ClientPortalInvitation::query()->sole();
        $this->assertSame([$invitation->public_id, 'invitee@client.example.test', 'projects', 'pending', true], [
            $created['data']['id'], $created['data']['email'], $created['data']['scope'], $created['data']['status'], $created['data']['email_sent'],
        ]);
        $this->assertSame([$project->public_id], array_column($created['data']['projects'], 'id'));
        $this->assertSame(route('agent-api.v1.clients.portal-invitations.resend', ['workspace' => $workspace->public_id, 'client' => $company->public_id, 'invitation' => $invitation->public_id]), $created['data']['resend_href']);

        $listed = $this->success($this->operation($transport, 'list', $workspace, $company), 'list');
        $this->assertSame([$created['data']], $listed['data']);

        $resent = $this->success($this->operation($transport, 'resend', $workspace, $company, ['confirm' => true], $invitation, 'synthetic-resend'), 'resend');
        $this->provider->assertResent(1);
        $this->assertSame('pending', $resent['data']['status']);

        $revoked = $this->success($this->operation($transport, 'revoke', $workspace, $company, ['confirm' => true], $invitation, 'synthetic-revoke'), 'revoke');
        $this->provider->assertRevoked(1);
        $this->assertSame(['revoked', null, null], [$revoked['data']['status'], $revoked['data']['resend_href'], $revoked['data']['revoke_href']]);
        $this->assertSame([], $this->success($this->operation($transport, 'list', $workspace, $company), 'list')['data']);

        // Neither the responses nor anything stored for agents carries a link.
        $link = $this->provider->invitation(1)['link'];
        $this->assertStringNotContainsString('identity.example.test', json_encode([$created, $listed, $resent, $revoked], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('identity.example.test', json_encode(AgentMutationReceipt::query()->get()->toArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('identity.example.test', json_encode(AgentMutationAudit::query()->get()->toArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('identity.example.test', json_encode(AccessAuditEvent::query()->get()->toArray(), JSON_THROW_ON_ERROR));
        $this->assertNotSame('', $link);
        $this->assertSame(['portal.invited', 'portal.invitation_resent', 'portal.invitation_revoked'], AccessAuditEvent::query()->orderBy('id')->pluck('action')->all());
        $this->assertSame(3, AccessAuditEvent::query()->whereNotNull('operation_id')->count());
    }

    /** Codex review on #444: each access audit carries the request that caused it. */
    public function test_access_audits_carry_the_request_id(): void
    {
        [$workspace, $owner, $company] = $this->fixture();
        $this->actingAsMcp($owner, self::SCOPES);
        $path = '/api/v1/workspaces/'.$workspace->public_id.'/clients/'.$company->public_id.'/portal-invitations';

        $created = $this->postJson($path, $this->body('create'), ['Idempotency-Key' => 'request-id-create', 'X-Request-Id' => 'synthetic-request-0001'])->assertCreated();
        $this->postJson($path.'/'.$created->json('data.id').'/resend', $this->body('resend'), ['Idempotency-Key' => 'request-id-resend'])->assertOk();

        $requestIds = AccessAuditEvent::query()->orderBy('id')->pluck('request_id')->all();
        $this->assertSame('synthetic-request-0001', $requestIds[0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $requestIds[1], 'Without a header a generated id is recorded.');
    }

    #[DataProvider('writeCases')]
    public function test_rest_and_mcp_share_receipts_and_a_replay_never_asks_the_provider_again(string $transport, string $kind): void
    {
        [$workspace, $owner, $company] = $this->fixture();
        $invitation = $kind === 'create' ? null : $this->seedInvitation($owner, $company);
        $this->actingAsMcp($owner, self::SCOPES);
        $calls = count($this->provider->calls());
        $body = $this->body($kind);

        $first = $this->success($this->operation($transport, $kind, $workspace, $company, $body, $invitation), $kind);
        $again = $this->success($this->operation($transport === 'rest' ? 'mcp' : 'rest', $kind, $workspace, $company, $body, $invitation), $kind);

        $this->assertSame($first, $again);
        $this->assertCount($calls + 1, $this->provider->calls());
        $this->assertSame(1, AgentMutationReceipt::query()->where('operation', 'clients.portal_invitations.'.$kind)->count());
        $this->refusal($this->operation('rest', $kind, $workspace, $company, [...$body, 'confirm' => false], $invitation), 409);
    }

    #[DataProvider('writeCases')]
    public function test_writes_require_scope_key_confirmation_cutovers_and_the_switch_and_refuse_members_and_strangers(string $transport, string $kind): void
    {
        [$workspace, $owner, $company] = $this->fixture();
        $invitation = $kind === 'create' ? null : $this->seedInvitation($owner, $company);
        $calls = count($this->provider->calls());
        $body = $this->body($kind);

        $this->actingAsMcp($owner, ['mcp:use', 'clients:read']);
        $this->refusal($this->operation($transport, $kind, $workspace, $company, $body, $invitation), 403);
        foreach ([$this->staff($workspace, 'member'), User::factory()->create()] as $index => $unauthorized) {
            $this->actingAsMcp($unauthorized, self::SCOPES);
            $this->refusal($this->operation($transport, $kind, $workspace, $company, $body, $invitation), $index === 0 ? 403 : 404);
        }
        $this->actingAsMcp($owner, self::SCOPES);
        $this->refusal($this->operation($transport, $kind, $workspace, $company, $body, $invitation, key: null), 422);
        $this->refusal($this->operation($transport, $kind, $workspace, $company, [...$body, 'confirm' => false], $invitation), 422);
        foreach (['svc.portal_invitations.enabled', 'agent_api.client_writes_enabled', 'agent_api.writes_enabled'] as $flag) {
            config([$flag => false]);
            $this->refusal($this->operation($transport, $kind, $workspace, $company, $body, $invitation), 404);
            config([$flag => true]);
        }

        $this->assertCount($calls, $this->provider->calls());
        $this->assertSame(0, AccessAuditEvent::query()->whereNotNull('operation_id')->count());
    }

    #[DataProvider('transports')]
    public function test_listing_requires_a_manager_the_read_scope_and_the_switch(string $transport): void
    {
        [$workspace, $owner, $company] = $this->fixture();
        $this->seedInvitation($owner, $company);

        $this->actingAsMcp($owner, ['mcp:use', 'clients:write']);
        $this->refusal($this->operation($transport, 'list', $workspace, $company), 403);
        $this->actingAsMcp($this->staff($workspace, 'member'), self::SCOPES);
        $this->refusal($this->operation($transport, 'list', $workspace, $company), 403);
        $this->actingAsMcp(User::factory()->create(), self::SCOPES);
        $this->refusal($this->operation($transport, 'list', $workspace, $company), 404);
        $this->actingAsMcp($owner, self::SCOPES);
        config(['svc.portal_invitations.enabled' => false]);
        $this->refusal($this->operation($transport, 'list', $workspace, $company), 404);
    }

    #[DataProvider('transports')]
    public function test_invitations_are_isolated_between_clients_and_workspaces(string $transport): void
    {
        [$workspace, $owner, $company] = $this->fixture();
        [$otherWorkspace, $otherOwner, $otherCompany] = $this->fixture();
        $sameWorkspaceCompany = $this->company($workspace, 'second-synthetic-client');
        $mine = $this->seedInvitation($owner, $company, 'mine@client.example.test');
        $theirs = $this->seedInvitation($otherOwner, $otherCompany, 'theirs@client.example.test');
        $sibling = $this->seedInvitation($owner, $sameWorkspaceCompany, 'sibling@client.example.test');
        $calls = count($this->provider->calls());
        $this->actingAsMcp($owner, self::SCOPES);

        $this->assertSame([$mine->public_id], array_column($this->success($this->operation($transport, 'list', $workspace, $company), 'list')['data'], 'id'));
        $this->refusal($this->operation($transport, 'list', $otherWorkspace, $otherCompany), 404);
        foreach (['resend', 'revoke'] as $kind) {
            $this->refusal($this->operation($transport, $kind, $workspace, $company, $this->body($kind), $theirs, 'foreign-'.$kind), 404);
            $this->refusal($this->operation($transport, $kind, $workspace, $company, $this->body($kind), $sibling, 'sibling-'.$kind), 404);
        }

        $this->assertCount($calls, $this->provider->calls());
        $this->assertSame(['pending', 'pending', 'pending'], [$mine->fresh()->status, $theirs->fresh()->status, $sibling->fresh()->status]);
    }

    #[DataProvider('transports')]
    public function test_refusals_map_to_their_statuses_and_record_nothing(string $transport): void
    {
        [$workspace, $owner, $company] = $this->fixture();
        User::factory()->create(['email' => 'known@client.example.test', 'oauth_provider' => 'example-provider', 'oauth_subject' => '9001']);
        $this->actingAsMcp($owner, self::SCOPES);

        $this->refusal($this->operation($transport, 'create', $workspace, $company, [...$this->body('create'), 'email' => 'known@client.example.test'], key: 'known'), 422);
        $this->provider->refuseNext('create', ApplicationInvitationError::RateLimited, retryAfter: 120, limit: 'recipient');
        $limited = $this->operation($transport, 'create', $workspace, $company, $this->body('create'), key: 'limited');
        $this->refusal($limited, 429);
        if ($limited instanceof TestResponse) {
            $limited->assertHeader('Retry-After', '120');
        }
        $this->provider->refuseNext('create', ApplicationInvitationError::ApplicationNotEnabled);
        $this->refusal($this->operation($transport, 'create', $workspace, $company, $this->body('create'), key: 'not-enabled'), 503);

        $this->assertSame(0, ClientPortalInvitation::query()->count());
        $this->assertSame(0, AccessAuditEvent::query()->count());

        $revoked = $this->seedInvitation($owner, $company);
        app(PortalInvitations::class)->revoke(AccessMutationContext::user($owner), $company, $revoked);
        $this->refusal($this->operation($transport, 'revoke', $workspace, $company, $this->body('revoke'), $revoked, 'already-revoked'), 409);
        $this->refusal($this->operation($transport, 'resend', $workspace, $company, $this->body('resend'), $revoked, 'resend-revoked'), 409);
        $this->assertSame('conflict', AgentMutationAudit::query()->where('operation', 'clients.portal_invitations.revoke')->sole()->error_category);
    }

    #[DataProvider('transports')]
    public function test_authority_is_decided_again_under_the_lock_and_a_refused_invitation_is_withdrawn(string $transport): void
    {
        [$workspace, , $company] = $this->fixture();
        $admin = User::factory()->create(['oauth_provider' => 'example-provider', 'oauth_subject' => '8001']);
        $this->addStaff($workspace, $admin, 'admin');
        $this->actingAsMcp($admin, self::SCOPES);
        // Demoted once the provider has issued the invitation, as the access
        // lock is taken: the fast check before the call passed.
        $demoted = false;
        DB::listen(function (QueryExecuted $query) use (&$demoted, $workspace, $admin): void {
            if ($demoted || $this->provider->calls('create') === [] || preg_match('/from [`"]workspaces[`"]/i', $query->sql) !== 1) {
                return;
            }
            $demoted = true;
            WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $admin->id)->update(['role' => 'member']);
        });

        $this->refusal($this->operation($transport, 'create', $workspace, $company, $this->body('create')), 403);
        $this->assertTrue($demoted);

        $this->provider->assertCreated('invitee@client.example.test');
        $this->provider->assertRevoked(1);
        $this->assertSame(0, ClientPortalInvitation::query()->count());
        $this->assertSame('forbidden', AgentMutationAudit::query()->where('operation', 'clients.portal_invitations.create')->sole()->error_category);
    }

    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic invitation workspace', 'slug' => 'synthetic-invitations-'.uniqid()]);
        $owner = User::factory()->create(['oauth_provider' => 'example-provider', 'oauth_subject' => (string) random_int(1000, 999999)]);
        $this->addStaff($workspace, $owner, 'owner');
        $company = $this->company($workspace, 'synthetic-client');
        $project = $this->project($company, 'Synthetic invitation project');

        return [$workspace, $owner, $company, $project];
    }

    private function seedInvitation(User $owner, ClientCompany $company, string $email = 'seeded@client.example.test'): ClientPortalInvitation
    {
        return app(PortalInvitations::class)->invite(AccessMutationContext::user($owner), $company, $email, PortalAccessScope::Company);
    }

    private function body(string $kind): array
    {
        return $kind === 'create' ? ['email' => 'invitee@client.example.test', 'scope' => 'company', 'confirm' => true] : ['confirm' => true];
    }

    private function operation(string $transport, string $kind, Workspace $workspace, ClientCompany $company, array $body = [], ?ClientPortalInvitation $invitation = null, ?string $key = 'synthetic-key'): TestResponse|array
    {
        if ($transport === 'mcp') {
            $arguments = ['workspace_id' => $workspace->public_id, 'client_id' => $company->public_id, ...$body];
            if (in_array($kind, ['resend', 'revoke'], true)) {
                $arguments['invitation_id'] = $invitation?->public_id;
            }
            if ($kind !== 'list' && $key !== null) {
                $arguments['idempotency_key'] = $key;
            }

            return $this->callTool($this->initialize(), 'clients.portal_invitations.'.$kind, $arguments);
        }
        $path = '/api/v1/workspaces/'.$workspace->public_id.'/clients/'.$company->public_id.'/portal-invitations';
        if ($kind === 'list') {
            return $this->getJson($path.'?'.http_build_query($body));
        }
        if ($kind !== 'create') {
            $path .= '/'.$invitation?->public_id.($kind === 'resend' ? '/resend' : '');
        }

        return $this->json($kind === 'revoke' ? 'DELETE' : 'POST', $path, $body, $key === null ? [] : ['Idempotency-Key' => $key]);
    }

    private function success(TestResponse|array $response, string $kind): array
    {
        if ($response instanceof TestResponse) {
            $body = $response->assertStatus($kind === 'create' ? 201 : 200)->json();
        } else {
            $this->assertFalse($response['result']['isError'] ?? true, json_encode($response));
            $body = $response['result']['structuredContent'];
        }
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($body, AgentApiResponseSchemaCatalog::forOperation('clients.portal_invitations.'.$kind)));

        return $body;
    }

    private function refusal(TestResponse|array $response, int $status): void
    {
        if ($response instanceof TestResponse) {
            $response->assertStatus($status);
            $this->assertStringNotContainsString('identity.example.test', (string) $response->getContent());
        } else {
            $this->assertTrue(isset($response['error']) || ($response['result']['isError'] ?? false), json_encode($response));
            $this->assertStringNotContainsString('identity.example.test', json_encode($response, JSON_THROW_ON_ERROR));
        }
    }
}
