<?php

namespace Tests\Feature\Access;

use App\Exceptions\PortalInvitationFailed;
use App\Models\AccessAuditEvent;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientPortalInvitation;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Access\PortalInvitations;
use App\Services\Access\WorkspaceAccess;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\AccessRefusal;
use App\Support\Access\PortalAccessScope;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationError;
use BWH\Auth\Testing\FakeApplicationInvitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsAccessFixtures;
use Tests\Concerns\FakesProviderIdentity;
use Tests\TestCase;

/**
 * Inviting a portal contact who has never signed in (#430): the invitation
 * asks the identity provider to admit the address, and the invited access is
 * applied at that person's first sign-in with a matching address, after
 * deciding again under the workspace access lock.
 */
final class PortalInvitationsTest extends TestCase
{
    use BuildsAccessFixtures;
    use FakesProviderIdentity;
    use RefreshDatabase;

    private FakeApplicationInvitations $provider;

    private Workspace $workspace;

    private User $owner;

    private User $admin;

    private ClientCompany $company;

    private ClientProject $alpha;

    private ClientProject $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeProviderIdentity(enforced: false);
        config(['svc.portal_invitations.enabled' => true]);
        $this->provider = FakeApplicationInvitations::swap();
        $this->workspace = Workspace::query()->create(['name' => 'Invitations', 'slug' => 'invitations']);
        $this->owner = $this->boundStaff('owner', '1001');
        $this->admin = $this->boundStaff('admin', '1002');
        $this->company = $this->company($this->workspace, 'invited-client');
        $this->alpha = $this->project($this->company, 'Alpha');
        $this->beta = $this->project($this->company, 'Beta');
    }

    public function test_the_feature_is_off_by_default(): void
    {
        $this->refreshApplication();

        $this->assertFalse(config('svc.portal_invitations.enabled'));
        $this->assertFalse(PortalInvitations::enabled());
    }

    public function test_an_unknown_address_is_invited_through_the_provider_and_recorded_pending(): void
    {
        $invitation = $this->invite($this->admin, 'New.Person@Client.example.test', PortalAccessScope::Projects, [$this->beta, $this->alpha]);

        $this->provider->assertCreated('new.person@client.example.test', actor: '1002');
        $this->assertSame(['pending', 'new.person@client.example.test', 'projects'], [$invitation->status, $invitation->email, $invitation->access_scope]);
        $this->assertEqualsCanonicalizing([$this->alpha->id, $this->beta->id], $invitation->projectIds());
        $this->assertSame($this->provider->invitation(1)['link'], $invitation->fresh()->link);
        // Encrypted at rest: the stored column is not the link.
        $this->assertStringNotContainsString('identity.example.test', (string) DB::table('client_portal_invitations')->value('link'));

        $event = AccessAuditEvent::query()->sole();
        $this->assertSame(['portal.invited', $this->admin->id, null, $this->company->public_id], [$event->action, $event->actor_user_id, $event->subject_user_id, $event->client_company_public_id]);
        $this->assertSame(['invitation' => $invitation->public_id, 'scope' => 'projects', 'projects' => collect([$this->alpha, $this->beta])->pluck('public_id')->sort()->values()->all()], $event->after_state);
        $this->assertStringNotContainsString('client.example.test', json_encode($event->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_accepting_and_signing_in_with_the_matching_address_gives_exactly_the_invited_scope(): void
    {
        $invitation = $this->invite($this->admin, 'invitee@client.example.test', PortalAccessScope::Projects, [$this->alpha]);
        $this->provider->accept(1);

        $this->signIn('2001', 'Invitee@Client.example.test')->assertRedirect();

        $user = User::query()->where('oauth_subject', '2001')->sole();
        $membership = ClientCompanyMembership::query()->where('user_id', $user->id)->sole();
        $this->assertSame([$this->company->id, 'projects'], [$membership->client_company_id, $membership->access_scope]);
        $this->assertSame([$this->alpha->id], $this->limits($membership));
        $invitation->refresh();
        $this->assertSame(['accepted', $user->id, null, null], [$invitation->status, $invitation->accepted_user_id, $invitation->link, $invitation->pending_email]);
        $granted = AccessAuditEvent::query()->where('action', 'portal.granted')->sole();
        $this->assertSame([$this->admin->id, $user->id], [$granted->actor_user_id, $granted->subject_user_id]);
        $this->assertSame(['scope' => 'projects', 'projects' => [$this->alpha->public_id], 'invitation' => $invitation->public_id], $granted->after_state);

        // A second sign-in changes nothing.
        $this->signIn('2001', 'invitee@client.example.test');
        $this->assertSame(1, ClientCompanyMembership::query()->count());
        $this->assertSame(1, AccessAuditEvent::query()->where('action', 'portal.granted')->count());
    }

    public function test_a_different_address_gets_nothing_and_the_invitation_stays_pending(): void
    {
        $invitation = $this->invite($this->admin, 'invitee@client.example.test', PortalAccessScope::Company);

        $this->signIn('2002', 'someone.else@client.example.test')->assertRedirect();
        $this->signIn('2003', 'invitee@client.example.test.other')->assertRedirect();

        $this->assertSame(0, ClientCompanyMembership::query()->count());
        $this->assertSame('pending', $invitation->fresh()->status);
        $this->assertSame(0, AccessAuditEvent::query()->where('action', '!=', 'portal.invited')->count());
    }

    public function test_an_inviter_who_lost_authority_leaves_it_not_applied_with_nothing_granted(): void
    {
        $demoted = $this->invite($this->admin, 'demoted@client.example.test', PortalAccessScope::Company);
        WorkspaceMembership::query()->where('workspace_id', $this->workspace->id)->where('user_id', $this->admin->id)->update(['role' => 'member']);
        $gone = $this->invite($this->owner, 'gone@client.example.test', PortalAccessScope::Company);
        $other = $this->boundStaff('admin', '1003');
        $removed = $this->invite($other, 'removed@client.example.test', PortalAccessScope::Company);
        WorkspaceMembership::query()->where('workspace_id', $this->workspace->id)->where('user_id', $other->id)->delete();
        $owner = $this->owner;
        // The inviter's account deleted outright.
        $this->workspace->memberships()->where('user_id', $owner->id)->delete();
        $owner->delete();

        $this->signIn('2010', 'demoted@client.example.test');
        $this->signIn('2011', 'gone@client.example.test');
        $this->signIn('2012', 'removed@client.example.test');

        $this->assertSame(0, ClientCompanyMembership::query()->count());
        foreach ([$demoted, $gone, $removed] as $invitation) {
            $invitation->refresh();
            $this->assertSame(['not_applied', 'inviter_lost_authority', null], [$invitation->status, $invitation->not_applied_reason, $invitation->link]);
        }
        $this->assertSame(3, AccessAuditEvent::query()->where('action', 'portal.invitation_not_applied')->count());
        $this->assertSame(0, AccessAuditEvent::query()->where('action', 'portal.granted')->count());
    }

    public function test_authority_is_decided_under_the_workspace_lock_at_sign_in(): void
    {
        $invitation = $this->invite($this->admin, 'raced@client.example.test', PortalAccessScope::Company);
        $this->onceTheWorkspaceIsLocked(fn () => WorkspaceMembership::query()->where('workspace_id', $this->workspace->id)->where('user_id', $this->admin->id)->update(['role' => 'member']));

        $this->signIn('2013', 'raced@client.example.test');

        $this->assertSame('not_applied', $invitation->fresh()->status);
        $this->assertSame(0, ClientCompanyMembership::query()->count());
    }

    public function test_moved_projects_are_dropped_and_an_invitation_left_with_none_is_not_applied(): void
    {
        $elsewhere = $this->company($this->workspace, 'elsewhere-client');
        $partly = $this->invite($this->admin, 'partly@client.example.test', PortalAccessScope::Projects, [$this->alpha, $this->beta]);
        $wholly = $this->invite($this->admin, 'wholly@client.example.test', PortalAccessScope::Projects, [$this->beta]);
        $deleted = $this->project($this->company, 'Deleted');
        $vanished = $this->invite($this->admin, 'vanished@client.example.test', PortalAccessScope::Projects, [$deleted]);
        $this->beta->forceFill(['client_company_id' => $elsewhere->id])->save();
        $deleted->delete();

        $this->signIn('2020', 'partly@client.example.test');
        $this->signIn('2021', 'wholly@client.example.test');
        $this->signIn('2022', 'vanished@client.example.test');

        $partlyMember = ClientCompanyMembership::query()->where('user_id', User::query()->where('oauth_subject', '2020')->sole()->id)->sole();
        $this->assertSame([$this->alpha->id], $this->limits($partlyMember));
        $this->assertSame('accepted', $partly->fresh()->status);
        foreach ([$wholly, $vanished] as $invitation) {
            $this->assertSame(['not_applied', 'projects_moved'], [$invitation->fresh()->status, $invitation->fresh()->not_applied_reason]);
        }
        $this->assertSame(1, ClientCompanyMembership::query()->count());
        $this->assertFalse(ClientCompanyMembership::query()->where('client_company_id', $elsewhere->id)->exists());
    }

    public function test_revoke_reaches_the_provider_and_a_revoked_invitation_admits_nothing(): void
    {
        $invitation = $this->invite($this->admin, 'revoked@client.example.test', PortalAccessScope::Company);

        $revoked = app(PortalInvitations::class)->revoke(AccessMutationContext::user($this->owner), $this->company, $invitation);

        $this->provider->assertRevoked(1, actor: '1001');
        $this->assertSame('revoked', $this->provider->invitation(1)['status']);
        $this->assertSame(['revoked', null, null], [$revoked->status, $revoked->fresh()->link, $revoked->fresh()->pending_email]);
        $this->signIn('2030', 'revoked@client.example.test');
        $this->assertSame(0, ClientCompanyMembership::query()->count());
        $this->assertRefused(AccessRefusal::InvitationNotOpen, fn () => app(PortalInvitations::class)->revoke(AccessMutationContext::user($this->owner), $this->company, $invitation->fresh()));
        $this->assertSame(['portal.invited', 'portal.invitation_revoked'], AccessAuditEvent::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_revoking_one_the_person_already_accepted_at_the_provider_still_gives_nothing_here(): void
    {
        $invitation = $this->invite($this->admin, 'accepted@client.example.test', PortalAccessScope::Company);
        $this->provider->accept(1);

        app(PortalInvitations::class)->revoke(AccessMutationContext::user($this->admin), $this->company, $invitation);

        $this->signIn('2031', 'accepted@client.example.test');
        $this->assertSame('revoked', $invitation->fresh()->status);
        $this->assertSame(0, ClientCompanyMembership::query()->count());
    }

    public function test_a_provider_that_cannot_revoke_changes_nothing_here(): void
    {
        $invitation = $this->invite($this->admin, 'kept@client.example.test', PortalAccessScope::Company);
        $this->provider->refuseNext('revoke', ApplicationInvitationError::Unavailable);

        try {
            app(PortalInvitations::class)->revoke(AccessMutationContext::user($this->admin), $this->company, $invitation);
            $this->fail('A revoke the provider did not confirm must not be recorded.');
        } catch (PortalInvitationFailed $failed) {
            $this->assertSame(503, $failed->status);
        }

        $this->assertSame('pending', $invitation->fresh()->status);
        $this->assertNotNull($invitation->fresh()->link);
    }

    public function test_resend_reaches_the_provider_with_a_new_link_and_the_old_one_stops(): void
    {
        $invitation = $this->invite($this->admin, 'resent@client.example.test', PortalAccessScope::Company);
        $old = $invitation->fresh()->link;
        $this->travel(8)->days();
        $this->assertSame('expired', $invitation->fresh()->currentStatus()->value);

        $resent = app(PortalInvitations::class)->resend(AccessMutationContext::user($this->owner), $this->company, $invitation->fresh());

        $this->provider->assertResent(1, actor: '1001');
        $this->assertSame('pending', $resent->currentStatus()->value);
        $this->assertSame($this->provider->invitation(1)['link'], $resent->fresh()->link);
        $this->assertNotSame($old, $resent->fresh()->link);
        $this->assertTrue($resent->expires_at->isFuture());
        $this->assertSame('portal.invitation_resent', AccessAuditEvent::query()->latest('id')->first()->action);
    }

    public function test_a_superseded_invitation_is_revoked_and_its_successor_alone_applies(): void
    {
        $first = $this->invite($this->admin, 'twice@client.example.test', PortalAccessScope::Company);
        $second = $this->invite($this->owner, 'twice@client.example.test', PortalAccessScope::Projects, [$this->alpha]);

        $this->assertSame('revoked', $first->fresh()->status);
        $this->assertNull($first->fresh()->link);
        $this->assertSame('revoked', $this->provider->invitation(1)['status'], 'The provider supersedes its own invitation too.');
        $this->assertSame(['invitation' => $first->public_id, 'status' => 'revoked', 'superseded_by' => $second->public_id],
            AccessAuditEvent::query()->where('action', 'portal.invitation_revoked')->sole()->after_state);

        $this->signIn('2040', 'twice@client.example.test');
        $member = ClientCompanyMembership::query()->sole();
        $this->assertSame([$this->alpha->id], $this->limits($member));
        $this->assertSame(['revoked', 'accepted'], [$first->fresh()->status, $second->fresh()->status]);
    }

    public function test_resending_one_the_provider_superseded_elsewhere_issues_a_new_one(): void
    {
        $other = $this->company($this->workspace, 'second-client');
        $first = $this->invite($this->admin, 'shared@client.example.test', PortalAccessScope::Company);
        $this->invite($this->admin, 'shared@client.example.test', PortalAccessScope::Company, [], $other);
        $this->assertSame('revoked', $this->provider->invitation(1)['status']);

        $resent = app(PortalInvitations::class)->resend(AccessMutationContext::user($this->admin), $this->company, $first->fresh());

        $this->assertSame(3, $resent->provider_invitation_id);
        $this->assertSame($this->provider->invitation(3)['link'], $resent->fresh()->link);
        // Both clients' invitations apply to the one address at sign-in.
        $this->signIn('2041', 'shared@client.example.test');
        $this->assertSame(2, ClientCompanyMembership::query()->count());
    }

    public function test_an_address_that_already_signs_in_is_refused_without_asking_the_provider(): void
    {
        User::factory()->create(['email' => 'known@client.example.test', 'oauth_provider' => self::PROVIDER, 'oauth_subject' => '3001']);

        $this->assertRefused(AccessRefusal::AccountExists, fn () => $this->invite($this->admin, 'KNOWN@client.example.test', PortalAccessScope::Company));

        $this->provider->assertNothingRequested();
        $this->assertSame(0, ClientPortalInvitation::query()->count());
    }

    public function test_members_strangers_and_unbound_managers_cannot_invite(): void
    {
        $member = $this->boundStaff('member', '1009');
        $this->assertRefused(AccessRefusal::NotAuthorized, fn () => $this->invite($member, 'a@client.example.test', PortalAccessScope::Company));
        $stranger = User::factory()->create(['oauth_provider' => self::PROVIDER, 'oauth_subject' => '1010']);
        $this->assertRefused(AccessRefusal::NotAuthorized, fn () => $this->invite($stranger, 'a@client.example.test', PortalAccessScope::Company));
        foreach ([['oauth_subject' => null], ['oauth_subject' => 'not-a-subject'], ['oauth_provider' => 'retired']] as $unbound) {
            $manager = $this->staff($this->workspace, 'admin');
            $manager->forceFill(['oauth_provider' => self::PROVIDER, 'oauth_subject' => '1011'.$manager->id, ...$unbound])->save();
            $this->assertRefused(AccessRefusal::InviterNotBound, fn () => $this->invite($manager, 'a@client.example.test', PortalAccessScope::Company));
        }

        $this->provider->assertNothingRequested();
        $this->assertSame(0, ClientPortalInvitation::query()->count());
    }

    public function test_projects_must_belong_to_the_client_and_agree_with_the_scope(): void
    {
        $foreign = $this->project($this->company($this->workspace, 'other-client'), 'Foreign');

        $this->assertRefused(AccessRefusal::ProjectOutsideCompany, fn () => $this->invite($this->admin, 'a@client.example.test', PortalAccessScope::Projects, [$foreign]));
        $this->assertRefused(AccessRefusal::ProjectsWithCompanyScope, fn () => $this->invite($this->admin, 'a@client.example.test', PortalAccessScope::Company, [$this->alpha]));

        $this->provider->assertNothingRequested();
    }

    public function test_a_refusal_under_the_lock_withdraws_what_the_provider_just_issued(): void
    {
        $this->onceTheWorkspaceIsLocked(fn () => WorkspaceMembership::query()->where('workspace_id', $this->workspace->id)->where('user_id', $this->admin->id)->update(['role' => 'member']));

        $this->assertRefused(AccessRefusal::NotAuthorized, fn () => $this->invite($this->admin, 'withdrawn@client.example.test', PortalAccessScope::Company));

        $this->provider->assertCreated('withdrawn@client.example.test');
        $this->provider->assertRevoked(1);
        $this->assertSame(0, ClientPortalInvitation::query()->count());
        $this->assertSame(0, AccessAuditEvent::query()->count());
    }

    public function test_a_provider_refusal_records_nothing(): void
    {
        $this->provider->refuseNext('create', ApplicationInvitationError::RateLimited, retryAfter: 60, limit: 'actor');

        try {
            $this->invite($this->admin, 'limited@client.example.test', PortalAccessScope::Company);
            $this->fail('A refused invitation must not be recorded.');
        } catch (PortalInvitationFailed $failed) {
            $this->assertSame([429, 60], [$failed->status, $failed->retryAfter]);
            $this->assertStringNotContainsString('limited@', $failed->getMessage());
        }

        $this->assertSame(0, ClientPortalInvitation::query()->count());
        $this->assertSame(0, AccessAuditEvent::query()->count());
    }

    public function test_an_expired_invitation_met_at_sign_in_gives_nothing(): void
    {
        $invitation = $this->invite($this->admin, 'late@client.example.test', PortalAccessScope::Company);
        $this->travel(8)->days();

        $this->signIn('2050', 'late@client.example.test');

        $this->assertSame(['expired', null], [$invitation->fresh()->status, $invitation->fresh()->link]);
        $this->assertSame(0, ClientCompanyMembership::query()->count());
    }

    public function test_invitations_from_every_workspace_apply_and_a_disabled_feature_applies_none(): void
    {
        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $elsewhereOwner = $this->boundStaff('owner', '1020', $elsewhere);
        $elsewhereCompany = $this->company($elsewhere, 'elsewhere-client');
        $this->invite($this->admin, 'both@client.example.test', PortalAccessScope::Company);
        $this->invite($elsewhereOwner, 'both@client.example.test', PortalAccessScope::Company, [], $elsewhereCompany);
        $this->invite($this->admin, 'off@client.example.test', PortalAccessScope::Company);

        $this->signIn('2060', 'both@client.example.test');
        $this->assertEqualsCanonicalizing([$this->workspace->id, $elsewhere->id], ClientCompanyMembership::query()->pluck('workspace_id')->all());

        config(['svc.portal_invitations.enabled' => false]);
        $this->signIn('2061', 'off@client.example.test');
        $this->assertSame(2, ClientCompanyMembership::query()->count());
    }

    public function test_an_existing_member_keeps_their_access_and_the_invitation_is_accepted(): void
    {
        $invitation = $this->invite($this->admin, 'member@client.example.test', PortalAccessScope::Projects, [$this->alpha]);
        $user = User::factory()->create(['email' => 'member@client.example.test']);
        $membership = new ClientCompanyMembership;
        $membership->forceFill(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'user_id' => $user->id, 'role' => 'client', 'access_scope' => 'company'])->save();

        $this->assertSame('accepted', app(WorkspaceAccess::class)->applyPortalInvitation($invitation, $user)->value);

        $this->assertSame('company', $membership->fresh()->access_scope);
        $this->assertSame(0, AccessAuditEvent::query()->where('action', 'portal.granted')->count());
    }

    private function boundStaff(string $role, string $subject, ?Workspace $workspace = null): User
    {
        $user = User::factory()->create(['oauth_provider' => self::PROVIDER, 'oauth_subject' => $subject]);
        $this->addStaff($workspace ?? $this->workspace, $user, $role);

        return $user;
    }

    /** @param list<ClientProject> $projects */
    private function invite(User $actor, string $email, PortalAccessScope $scope, array $projects = [], ?ClientCompany $company = null): ClientPortalInvitation
    {
        return app(PortalInvitations::class)->invite(AccessMutationContext::user($actor), $company ?? $this->company, $email, $scope,
            array_map(static fn (ClientProject $project): string => $project->public_id, $projects));
    }

    private function signIn(string $subject, string $email): TestResponse
    {
        auth()->logout();
        $this->providerLoginIdentity = ['sub' => $subject, 'name' => 'Invited '.$subject, 'email' => $email];

        return $this->withSession(['oauth.login.state' => 'provider-state', 'oauth.login.code_verifier' => 'provider-verifier'])
            ->get('/oauth/callback?state=provider-state&code=provider-code');
    }

    /** @return list<int> */
    private function limits(ClientCompanyMembership $membership): array
    {
        return DB::table('client_portal_project_access')->where('client_company_membership_id', $membership->id)->orderBy('client_project_id')->pluck('client_project_id')->map(intval(...))->all();
    }
}
