<?php

namespace Tests\Feature\Access;

use App\Models\AccessAuditEvent;
use App\Models\ClientCompany;
use App\Models\ClientPortalInvitation;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\PortalInvitations;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\PortalAccessScope;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationError;
use BWH\Auth\Testing\FakeApplicationInvitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsAccessFixtures;
use Tests\TestCase;

/**
 * Portal invitations from a client's settings page (#430): offered when
 * nobody signs in with the address, listed with a copyable link for managers,
 * and sent again or withdrawn. The page sends each through the `/api/v1`
 * operation agents use, on its session (#385); only the page ever receives
 * the link.
 */
final class ClientPortalInvitationScreenTest extends TestCase
{
    use BuildsAccessFixtures;
    use RefreshDatabase;

    private FakeApplicationInvitations $provider;

    private Workspace $workspace;

    private User $admin;

    private ClientCompany $company;

    private ClientProject $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        // Agent writes switched off: the website does not depend on them.
        config(['bherila-auth.oauth_client.provider' => 'example', 'svc.portal_invitations.enabled' => true,
            'agent_api.writes_enabled' => false, 'agent_api.client_writes_enabled' => false]);
        $this->provider = FakeApplicationInvitations::swap();
        $this->workspace = Workspace::query()->create(['name' => 'Portal', 'slug' => 'portal']);
        $this->admin = User::factory()->create(['oauth_provider' => 'example', 'oauth_subject' => '501']);
        $this->addStaff($this->workspace, $this->admin, 'admin');
        $this->company = $this->company($this->workspace, 'portal-client');
        $this->alpha = $this->project($this->company, 'Alpha');
    }

    public function test_an_unknown_address_is_offered_an_invitation_and_invited(): void
    {
        $this->send($this->admin, 'POST', $this->base('portal-access'), ['email' => 'new@client.example.test', 'scope' => 'company', 'confirm' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.invite', ['Nobody signs in with that address yet. Send them an invitation, and they get this access when they first sign in.']);
        $this->provider->assertNothingRequested();

        $this->send($this->admin, 'POST', $this->base('portal-invitations'), ['email' => 'New@client.example.test', 'scope' => 'projects', 'projects' => [$this->alpha->public_id], 'confirm' => true])
            ->assertCreated()->assertJsonMissingPath('data.link');

        $this->provider->assertCreated('new@client.example.test', actor: '501');
        $invitation = ClientPortalInvitation::query()->sole();
        $this->assertSame(['pending', 'projects', [$this->alpha->id]], [$invitation->status, $invitation->access_scope, $invitation->projectIds()]);
    }

    public function test_settings_lists_invitations_with_the_link_only_while_it_can_be_accepted(): void
    {
        $this->invite('pending@client.example.test');
        $this->invite('expired@client.example.test');
        ClientPortalInvitation::query()->where('email', 'expired@client.example.test')->update(['expires_at' => now()->subDay()]);
        $this->invite('dismissed@client.example.test');
        ClientPortalInvitation::query()->where('email', 'dismissed@client.example.test')->update(['status' => 'revoked', 'pending_email' => null]);
        $this->invite('missed@client.example.test');
        ClientPortalInvitation::query()->where('email', 'missed@client.example.test')->update(['status' => 'not_applied', 'pending_email' => null, 'link' => null, 'not_applied_reason' => 'projects_moved']);
        $pending = ClientPortalInvitation::query()->where('email', 'pending@client.example.test')->sole();
        $base = $this->base('portal-invitations');

        $this->actingAs($this->admin)->get($this->settings())->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('portal.invite_url', $base)
                ->has('portal.invitations', 3)
                ->where('portal.invitations.0.email', 'missed@client.example.test')
                ->where('portal.invitations.0.status', 'not_applied')
                ->where('portal.invitations.0.reason', 'None of the invited projects belong to this client any more, so nothing was given. Add them again with the projects they should reach.')
                ->where('portal.invitations.0.link', null)
                ->where('portal.invitations.0.resend_url', null)
                ->where('portal.invitations.1.status', 'expired')
                ->where('portal.invitations.1.link', null)
                ->where('portal.invitations.2', [
                    'id' => $pending->public_id,
                    'email' => 'pending@client.example.test',
                    'scope' => 'company',
                    'projects' => [],
                    'status' => 'pending',
                    'reason' => null,
                    'expires_at' => $pending->expires_at->toIso8601String(),
                    'email_sent' => true,
                    'link' => $this->provider->invitation(1)['link'],
                    'resend_url' => $base.'/'.$pending->public_id.'/resend',
                    'revoke_url' => $base.'/'.$pending->public_id,
                ]));
    }

    public function test_settings_never_disclose_another_companys_project_from_a_historical_invitation(): void
    {
        $this->invite('synthetic-project-scope@client.example.test');
        $otherCompany = $this->company($this->workspace, 'synthetic-other-client');
        $otherProject = $this->project($otherCompany, 'Synthetic other client project');
        $invitation = ClientPortalInvitation::query()->sole();
        $invitation->forceFill(['access_scope' => 'projects', 'project_ids' => [$this->alpha->id, $otherProject->id]])->save();
        $this->actingAs($this->admin, 'web')->get($this->settings())->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('portal.invitations.0.projects', [$this->alpha->public_id])
                ->where('portal.invitations.0.link', $invitation->link));
    }

    public function test_an_invitation_is_sent_again_and_withdrawn_from_settings(): void
    {
        $this->invite('person@client.example.test');
        $invitation = ClientPortalInvitation::query()->sole();
        $url = $this->base('portal-invitations').'/'.$invitation->public_id;

        $this->send($this->admin, 'POST', $url.'/resend', ['confirm' => true])->assertOk()->assertJsonMissingPath('data.link');
        $this->provider->assertResent(1, actor: '501');

        $this->send($this->admin, 'DELETE', $url, ['confirm' => true])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->provider->assertRevoked(1, actor: '501');
        $this->assertSame('revoked', $invitation->fresh()->status);

        $this->send($this->admin, 'DELETE', $url, ['confirm' => true])
            ->assertConflict()->assertJsonPath('message', 'That invitation was already accepted or withdrawn.');
    }

    public function test_every_open_invitation_remains_manageable_beyond_the_first_page(): void
    {
        $this->invite('oldest@client.example.test');
        $oldest = ClientPortalInvitation::query()->where('workspace_id', $this->workspace->id)->sole();
        for ($index = 0; $index < 100; $index++) {
            $oldest->replicate()->forceFill([
                'public_id' => (string) Str::uuid(),
                'email' => "fixture-{$index}@client.example.test",
                'pending_email' => "fixture-{$index}@client.example.test",
            ])->save();
        }
        $other = $this->company($this->workspace, 'other-client');
        $oldest->replicate()->forceFill(['public_id' => (string) Str::uuid(), 'client_company_id' => $other->id])->save();

        $this->actingAs($this->admin)->get($this->settings())->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('portal.invitations', 100)
                ->where('portal.invitation_pagination.next_url', $this->settings().'?invitation_page=2')
                ->where('portal.invitation_pagination.previous_url', null));
        $this->get($this->settings().'?invitation_page=2')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('portal.invitations', 1)
                ->where('portal.invitations.0.id', $oldest->public_id)
                ->where('portal.invitations.0.link', $this->provider->invitation(1)['link'])
                ->where('portal.invitations.0.revoke_url', $this->base('portal-invitations').'/'.$oldest->public_id)
                ->where('portal.invitation_pagination.next_url', null)
                ->where('portal.invitation_pagination.previous_url', $this->settings().'?invitation_page=1'));
    }

    public function test_a_provider_refusal_is_shown_on_the_form_and_records_nothing(): void
    {
        $this->provider->refuseNext('create', ApplicationInvitationError::Unavailable);

        $this->send($this->admin, 'POST', $this->base('portal-invitations'), ['email' => 'new@client.example.test', 'scope' => 'company', 'confirm' => true])
            ->assertServiceUnavailable()->assertJsonPath('message', 'The identity provider is unavailable. Try again later.');

        $this->assertSame(0, ClientPortalInvitation::query()->count());
        $this->assertSame(0, AccessAuditEvent::query()->count());
    }

    public function test_an_unfinished_provider_withdrawal_stays_manageable_without_an_admission_link(): void
    {
        $this->invite('withdrawal@client.example.test');
        $invitation = ClientPortalInvitation::query()->where('workspace_id', $this->workspace->id)->sole();
        $url = $this->base('portal-invitations').'/'.$invitation->public_id;
        $this->provider->refuseNext('revoke', ApplicationInvitationError::Unavailable);

        $this->send($this->admin, 'DELETE', $url, ['confirm' => true], 'synthetic-withdrawal')->assertServiceUnavailable();
        $this->assertSame('revoked', $invitation->fresh()->status);
        $this->assertTrue($invitation->fresh()->provider_revocation_pending);
        $this->actingAs($this->admin)->get($this->settings())->assertInertia(fn (Assert $page) => $page
            ->has('portal.invitations', 1)
            ->where('portal.invitations.0.status', 'revoked')
            ->where('portal.invitations.0.link', null)
            ->where('portal.invitations.0.resend_url', null)
            ->where('portal.invitations.0.revoke_url', $url)
            ->where('portal.invitations.0.reason', 'Portal access is withdrawn. The identity provider withdrawal has not finished; try again.'));

        // The page retries an unanswered withdrawal with the same key, which
        // finishes it without restoring access.
        $this->send($this->admin, 'DELETE', $url, ['confirm' => true], 'synthetic-withdrawal')->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertFalse($invitation->fresh()->provider_revocation_pending);
        $this->actingAs($this->admin)->get($this->settings())->assertInertia(fn (Assert $page) => $page->where('portal.invitations', []));
    }

    public function test_an_address_that_signs_in_is_added_rather_than_invited(): void
    {
        User::factory()->create(['email' => 'known@client.example.test', 'oauth_provider' => 'example', 'oauth_subject' => '777']);

        $this->send($this->admin, 'POST', $this->base('portal-invitations'), ['email' => 'known@client.example.test', 'scope' => 'company', 'confirm' => true])
            ->assertUnprocessable()->assertJsonPath('message', 'That person already signs in here; add them as a contact instead.');

        $this->provider->assertNothingRequested();
    }

    public function test_while_off_nothing_is_offered_listed_or_reachable(): void
    {
        $this->invite('person@client.example.test');
        $invitation = ClientPortalInvitation::query()->sole();
        config(['svc.portal_invitations.enabled' => false]);
        $url = $this->base('portal-invitations').'/'.$invitation->public_id;

        $this->actingAs($this->admin)->get($this->settings())->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('portal.invite_url', null)->where('portal.invitations', []));
        $this->send($this->admin, 'POST', $this->base('portal-invitations'), ['email' => 'new@client.example.test', 'scope' => 'company', 'confirm' => true])->assertNotFound();
        $this->send($this->admin, 'POST', $url.'/resend', ['confirm' => true])->assertNotFound();
        $this->send($this->admin, 'DELETE', $url, ['confirm' => true])->assertNotFound();
        $this->send($this->admin, 'POST', $this->base('portal-access'), ['email' => 'new@client.example.test', 'scope' => 'company', 'confirm' => true])
            ->assertUnprocessable()->assertJsonPath('errors.email', ['Nobody signs in with that address yet. Ask them to sign in once, then add them.']);

        $this->assertSame('pending', $invitation->fresh()->status);
        $this->assertSame(1, count($this->provider->calls()));
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function outsiders(): array
    {
        // A member is refused; a stranger cannot see the workspace at all.
        return ['member' => ['member', 403], 'stranger' => ['stranger', 404]];
    }

    #[DataProvider('outsiders')]
    public function test_members_and_strangers_reach_no_invitation(string $who, int $status): void
    {
        $this->invitedDirectly('person@client.example.test');
        $invitation = ClientPortalInvitation::query()->sole();
        $url = $this->base('portal-invitations').'/'.$invitation->public_id;
        $actor = $who === 'member' ? $this->staff($this->workspace, 'member') : User::factory()->create();

        $this->send($actor, 'POST', $this->base('portal-invitations'), ['email' => 'new@client.example.test', 'scope' => 'company', 'confirm' => true])->assertStatus($status);
        $this->send($actor, 'POST', $url.'/resend', ['confirm' => true])->assertStatus($status);
        $this->send($actor, 'DELETE', $url, ['confirm' => true])->assertStatus($status);

        $this->assertSame('pending', $invitation->fresh()->status);
        $this->assertSame(1, count($this->provider->calls()));
    }

    public function test_another_clients_invitation_is_not_found(): void
    {
        $this->invite('person@client.example.test');
        $invitation = ClientPortalInvitation::query()->sole();
        $other = $this->company($this->workspace, 'other-client');
        $foreign = "/api/v1/workspaces/{$this->workspace->public_id}/clients/{$other->public_id}/portal-invitations/{$invitation->public_id}";

        $this->send($this->admin, 'POST', $foreign.'/resend', ['confirm' => true])->assertNotFound();
        $this->send($this->admin, 'DELETE', $foreign, ['confirm' => true])->assertNotFound();

        $this->assertSame('pending', $invitation->fresh()->status);
        $this->assertSame(1, count($this->provider->calls()));
    }

    /** @param array<string, mixed> $body */
    private function send(User $actor, string $method, string $url, array $body, ?string $key = null): TestResponse
    {
        // A browser request: the session cookie, not a bearer token. One
        // actor per test: the test process keeps the API guard's user.
        return $this->actingAs($actor)->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40))
            ->withHeader('Idempotency-Key', $key ?? 'synthetic-'.Str::uuid())->json($method, $url, $body);
    }

    private function invite(string $email): void
    {
        $this->send($this->admin, 'POST', $this->base('portal-invitations'), ['email' => $email, 'scope' => 'company', 'confirm' => true])->assertCreated();
    }

    /** An invitation made without a request, for tests acting as someone else. */
    private function invitedDirectly(string $email): void
    {
        app(PortalInvitations::class)->invite(AccessMutationContext::user($this->admin), $this->company, $email, PortalAccessScope::Company);
    }

    private function settings(): string
    {
        return "/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/settings";
    }

    private function base(string $path): string
    {
        return "/api/v1/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/{$path}";
    }
}
