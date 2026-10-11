<?php

namespace Tests\Feature\Access;

use App\Models\AccessAuditEvent;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Access\DelegatedStaffAccess;
use App\Support\AgentApi\ProjectRole;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedAccessException;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedContract;
use BWH\Auth\OAuth\PendingAccount;
use BWH\Auth\Testing\AssertsDelegatedAccessAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAccessFixtures;
use Tests\TestCase;

/**
 * What svc's staff adapter adds to the contract: which roles each actor may
 * give, owner and self protection, the assignments a removal discloses, a
 * revision that follows the actor's own role, provisioning, and an audit trail
 * that carries the provider's ids.
 */
final class DelegatedStaffAccessTest extends TestCase
{
    use AssertsDelegatedAccessAdapter;
    use BuildsAccessFixtures;
    use RefreshDatabase;

    private const PROVIDER = 'example-provider';

    private Workspace $workspace;

    private User $owner;

    private User $admin;

    private User $member;

    private User $colleague;

    private ClientProject $alpha;

    private ClientProject $beta;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bherila-auth.oauth_client.provider' => self::PROVIDER,
            'bherila-auth.delegated_access.application' => 'svc',
            'bherila-auth.delegated_access.issuer' => 'https://identity.example.test',
            'bherila-auth.delegated_access.oauth_provider' => self::PROVIDER,
        ]);

        $this->workspace = Workspace::query()->create(['name' => 'Example Workspace', 'slug' => 'example']);
        $this->owner = $this->bound('owner-subject', 'Example Owner', 'owner');
        $this->admin = $this->bound('admin-subject', 'Example Admin', 'admin');
        $this->member = $this->bound('member-subject', 'Example Member', 'member');
        $this->colleague = $this->bound('colleague-subject', 'Example Colleague', 'admin');
        $company = $this->company($this->workspace, 'example-client');
        $this->alpha = $this->project($company, 'Alpha');
        $this->beta = $this->project($company, 'Beta');
        $this->assign($this->member, $this->alpha, ProjectRole::Manager);
        $this->assign($this->member, $this->beta, ProjectRole::Viewer);
        $this->assign($this->colleague, $this->alpha, ProjectRole::Contributor);
    }

    public function test_capabilities_offer_admin_and_member_and_never_owner(): void
    {
        $roles = $this->delegatedAccessCall('owner-subject', ['operation' => 'capabilities'])['controls']['workspace_roles'];

        $this->assertSame(['admin', 'member'], array_column($roles, 'id'));
    }

    public function test_a_read_discloses_each_removable_memberships_assignments_and_the_portal_notice(): void
    {
        $member = $this->read('owner-subject', 'member-subject');
        $this->assertSame([['kind' => 'project_assignments', 'label' => 'Project assignments', 'count' => 2]], $member['access']['workspaces'][0]['removal_effects']);
        $this->assertSame(DelegatedStaffAccess::REMOVAL_NOTICE, $member['access']['removal_notice']);
        $this->assertTrue($member['allowed_edits']['remove']);

        // An admin's assignments are kept for a later change back to member, and go with a removal too.
        $this->assertSame(1, $this->read('owner-subject', 'colleague-subject')['access']['workspaces'][0]['removal_effects'][0]['count']);

        // Nothing to disclose where the actor may not remove the membership.
        $asAdmin = $this->read('admin-subject', 'colleague-subject');
        $this->assertFalse($asAdmin['access']['workspaces'][0]['editable']);
        $this->assertArrayNotHasKey('removal_effects', $asAdmin['access']['workspaces'][0]);
        $this->assertFalse($asAdmin['allowed_edits']['remove']);
    }

    public function test_owners_and_the_actors_own_membership_are_never_editable(): void
    {
        $owner = $this->read('admin-subject', 'owner-subject');
        $this->assertSame(['id' => $this->workspace->public_id, 'role' => 'owner', 'editable' => false], $owner['access']['workspaces'][0]);
        $this->assertFalse($owner['allowed_edits']['remove']);

        $self = $this->read('owner-subject', 'owner-subject');
        $this->assertFalse($self['access']['workspaces'][0]['editable']);
        $this->assertRefusedWithNothingChanged('protected_membership', 'owner-subject', ['operation' => 'remove', 'subject' => 'owner-subject', 'expected_revision' => $self['revision']]);
    }

    public function test_an_admin_manages_members_but_never_grants_or_changes_admin(): void
    {
        $state = $this->read('admin-subject', 'member-subject');
        $this->assertTrue($state['access']['workspaces'][0]['editable']);

        $this->assertRefusedWithNothingChanged('role_not_grantable', 'admin-subject',
            $this->updateTo('member-subject', $state['revision'], [['id' => $this->workspace->public_id, 'role' => 'admin']]));
        $this->assertRefusedWithNothingChanged('protected_membership', 'admin-subject',
            $this->updateTo('colleague-subject', $this->read('admin-subject', 'colleague-subject')['revision'], []));

        // Provisioning an admin is refused before any account exists.
        $this->assertRefusedWithNothingChanged('role_not_grantable', 'admin-subject',
            $this->updateTo('new-subject', null, [['id' => $this->workspace->public_id, 'role' => 'admin']]));
        $this->assertNull(User::query()->where('oauth_subject', 'new-subject')->first());
    }

    public function test_an_owner_promotes_a_member_and_the_assignments_stay(): void
    {
        $state = $this->read('owner-subject', 'member-subject');

        $after = $this->delegatedAccessCall('owner-subject', $this->updateTo('member-subject', $state['revision'], [['id' => $this->workspace->public_id, 'role' => 'admin']]));

        $this->assertSame('admin', $after['access']['workspaces'][0]['role']);
        $this->assertSame(2, ClientProjectMembership::query()->where('user_id', $this->member->id)->count());
    }

    public function test_the_actors_demotion_after_the_read_refuses_the_write(): void
    {
        $state = $this->read('owner-subject', 'colleague-subject');
        $this->assertTrue($state['allowed_edits']['remove']);

        // Nothing about the target changes: only what the actor may do to it.
        WorkspaceMembership::query()->where('workspace_id', $this->workspace->id)->where('user_id', $this->owner->id)->update(['role' => 'admin']);
        $this->assertNotSame($state['revision'], $this->read('owner-subject', 'colleague-subject')['revision']);

        $this->assertRefusedWithNothingChanged('revision_conflict', 'owner-subject', ['operation' => 'remove', 'subject' => 'colleague-subject', 'expected_revision' => $state['revision']]);
    }

    /** Codex review on #422: a workspace the actor gains during the write is part of what it would take. */
    public function test_a_workspace_the_actor_gains_during_the_write_refuses_it(): void
    {
        $other = Workspace::query()->create(['name' => 'Other Workspace', 'slug' => 'other']);
        $this->addStaff($other, $this->member, 'member');
        $state = $this->read('owner-subject', 'member-subject');
        $this->assertCount(1, $state['access']['workspaces']);

        // Committed by someone else after this request listed the actor's workspaces.
        $this->onceTheWorkspaceIsLocked(fn () => $this->addStaff($other, $this->owner, 'admin'));

        $this->assertRefusedWithNothingChanged('revision_conflict', 'owner-subject', ['operation' => 'remove', 'subject' => 'member-subject', 'expected_revision' => $state['revision']]);
    }

    public function test_an_assignment_role_changed_after_the_read_refuses_the_removal(): void
    {
        $state = $this->read('owner-subject', 'member-subject');

        // Same projects, same count; only what one of them is changes.
        ClientProjectMembership::query()->where('user_id', $this->member->id)->where('client_project_id', $this->beta->id)->update(['role' => ProjectRole::Contributor->value]);
        $this->assertSame($state['access']['workspaces'], $this->read('owner-subject', 'member-subject')['access']['workspaces']);

        $this->assertRefusedWithNothingChanged('revision_conflict', 'owner-subject', ['operation' => 'remove', 'subject' => 'member-subject', 'expected_revision' => $state['revision']]);
    }

    public function test_a_removal_through_the_endpoint_takes_the_assignments_keeps_portal_access_and_audits_the_providers_ids(): void
    {
        $portal = new ClientCompanyMembership;
        $portal->forceFill(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->alpha->client_company_id,
            'user_id' => $this->member->id, 'role' => 'client', 'access_scope' => 'company'])->save();
        $read = $this->delegatedAccessEndpoint('owner-subject', ['operation' => 'read', 'subject' => 'member-subject'])->assertOk();
        $operationId = DelegatedContract::operationId();

        $this->delegatedAccessEndpoint('owner-subject', ['operation' => 'remove', 'subject' => 'member-subject',
            'expected_revision' => $read->json('revision'), 'operation_id' => $operationId])->assertOk()
            ->assertJsonPath('access.workspaces', []);

        $this->assertNull(WorkspaceMembership::query()->where('user_id', $this->member->id)->first());
        $this->assertSame(0, ClientProjectMembership::query()->where('user_id', $this->member->id)->count());
        $this->assertTrue(ClientCompanyMembership::query()->where('user_id', $this->member->id)->exists());

        $events = AccessAuditEvent::query()->where('subject_user_id', $this->member->id)->orderBy('id')->get();
        $this->assertSame(['staff.assignment_removed', 'staff.assignment_removed', 'staff.removed'], $events->pluck('action')->all());
        foreach ($events as $event) {
            $this->assertSame($this->owner->id, $event->actor_user_id);
            $this->assertSame($operationId, $event->operation_id);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $event->request_id);
        }
    }

    public function test_provisioning_creates_an_account_bound_to_the_subject_and_never_adopts_one(): void
    {
        $answer = $this->delegatedAccessCall('owner-subject', [
            ...$this->updateTo('new-subject', null, [['id' => $this->workspace->public_id, 'role' => 'member']]),
            'display_name' => 'Example Newcomer',
        ]);

        $this->assertSame([['id' => $this->workspace->public_id, 'role' => 'member', 'editable' => true]], $answer['access']['workspaces']);
        $user = User::query()->where('oauth_provider', self::PROVIDER)->where('oauth_subject', 'new-subject')->sole();
        $this->assertSame('Example Newcomer', $user->name);
        $this->assertSame(PendingAccount::email(self::PROVIDER, 'new-subject'), $user->email);
        $this->assertSame(['staff.account_provisioned', 'staff.granted'],
            AccessAuditEvent::query()->where('subject_user_id', $user->id)->orderBy('id')->pluck('action')->all());

        // Bound already: stale, whatever is asked.
        $this->assertRefusedWithNothingChanged('revision_conflict', 'owner-subject',
            $this->updateTo('new-subject', null, [['id' => $this->workspace->public_id, 'role' => 'member']]));

        // An unbound row holding the derived address is never adopted.
        User::factory()->create(['email' => PendingAccount::email(self::PROVIDER, 'orphan-subject')]);
        $this->assertRefusedWithNothingChanged('invalid_request', 'owner-subject',
            $this->updateTo('orphan-subject', null, [['id' => $this->workspace->public_id, 'role' => 'member']]));
        $this->assertNull(User::query()->where('oauth_subject', 'orphan-subject')->first());
    }

    public function test_an_actor_without_a_managed_workspace_learns_nothing(): void
    {
        foreach ([['operation' => 'capabilities'], ['operation' => 'read', 'subject' => 'owner-subject'], ['operation' => 'subjects', 'limit' => 50]] as $payload) {
            $this->assertRefusedWithNothingChanged('not_authorized', 'member-subject', $payload);
        }
        $this->assertRefusedWithNothingChanged('not_authorized', 'unbound-subject', ['operation' => 'capabilities']);
    }

    protected function delegatedAccessTruth(string $subject): array
    {
        $user = User::query()->where('oauth_provider', self::PROVIDER)->where('oauth_subject', $subject)->value('id');
        $workspaces = [];
        foreach (WorkspaceMembership::query()->where('user_id', $user)->with('workspace')->get() as $membership) {
            $workspaces[$membership->workspace->public_id] = $membership->role;
        }

        return ['application_admin' => false, 'workspaces' => $workspaces];
    }

    protected function delegatedAccessManager(): string
    {
        return 'owner-subject';
    }

    /** @return array<string, mixed> */
    private function read(string $actor, string $subject): array
    {
        return $this->delegatedAccessCall($actor, ['operation' => 'read', 'subject' => $subject]);
    }

    /**
     * @param  list<array{id: string, role: string}>  $workspaces
     * @return array<string, mixed>
     */
    private function updateTo(string $subject, ?string $revision, array $workspaces): array
    {
        return ['operation' => 'update', 'subject' => $subject, 'expected_revision' => $revision,
            'access' => ['application_admin' => false, 'workspaces' => $workspaces], 'operation_id' => DelegatedContract::operationId()];
    }

    /** @param array<string, mixed> $payload */
    private function assertRefusedWithNothingChanged(string $outcome, string $actor, array $payload): void
    {
        $before = [
            WorkspaceMembership::query()->orderBy('id')->get(['workspace_id', 'user_id', 'role'])->toArray(),
            ClientProjectMembership::query()->orderBy('id')->get(['client_project_id', 'user_id', 'role'])->toArray(),
            User::query()->count(),
        ];

        try {
            app(DelegatedStaffAccess::class)->handle($actor, $payload);
            $this->fail("Expected {$payload['operation']} to be refused as {$outcome}.");
        } catch (DelegatedAccessException $refusal) {
            $this->assertSame($outcome, $refusal->outcome);
        }

        $this->assertSame($before, [
            WorkspaceMembership::query()->orderBy('id')->get(['workspace_id', 'user_id', 'role'])->toArray(),
            ClientProjectMembership::query()->orderBy('id')->get(['client_project_id', 'user_id', 'role'])->toArray(),
            User::query()->count(),
        ]);
    }

    private function bound(string $subject, string $name, string $role): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => $subject.'@directory.test', 'oauth_provider' => self::PROVIDER, 'oauth_subject' => $subject]);
        $this->addStaff($this->workspace, $user, $role);

        return $user;
    }
}
