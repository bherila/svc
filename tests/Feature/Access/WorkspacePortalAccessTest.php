<?php

namespace Tests\Feature\Access;

use App\Models\AccessAuditEvent;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\WorkspaceAccess;
use App\Services\Authorization\PortalAccess;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\AccessRefusal;
use App\Support\Access\PortalAccessScope;
use App\Support\Access\PortalAccessState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsAccessFixtures;
use Tests\Concerns\CapturesTenantOwnedWrites;
use Tests\TestCase;

final class WorkspacePortalAccessTest extends TestCase
{
    use BuildsAccessFixtures;
    use CapturesTenantOwnedWrites;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $admin;

    private User $member;

    private User $client;

    private ClientCompany $company;

    private ClientProject $first;

    private ClientProject $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->create(['name' => 'Portal access', 'slug' => 'portal-access']);
        $this->owner = $this->staff($this->workspace, 'owner');
        $this->admin = $this->staff($this->workspace, 'admin');
        $this->member = $this->staff($this->workspace, 'member');
        $this->client = User::factory()->create();
        $this->company = $this->company($this->workspace, 'portal-client');
        $this->first = $this->project($this->company, 'First');
        $this->second = $this->project($this->company, 'Second');
    }

    public function test_an_owner_grants_company_wide_portal_access(): void
    {
        $membership = $this->access()->grantPortal(
            AccessMutationContext::user($this->owner, str_repeat('p', 40), 'request-portal-1'),
            $this->company,
            $this->client,
            PortalAccessScope::Company,
        );

        $this->assertSame('client', $membership->role);
        $this->assertSame('company', $membership->access_scope);
        $this->assertSame($this->workspace->id, $membership->workspace_id);
        $this->assertNull(app(PortalAccess::class)->visibleProjectIds($this->company, $this->client));
        $this->assertSame(0, DB::table('client_portal_project_access')->count());

        $event = AccessAuditEvent::query()->sole();
        $this->assertSame('portal.granted', $event->action);
        $this->assertSame($this->company->public_id, $event->client_company_public_id);
        $this->assertNull($event->before);
        $this->assertSame(['scope' => 'company', 'projects' => []], $event->after);
        $this->assertSame(str_repeat('p', 40), $event->operation_id);
        $this->assertSame('request-portal-1', $event->request_id);
        $this->assertSame($this->client->id, $event->subject_user_id);
    }

    public function test_an_admin_grants_portal_access_limited_to_projects(): void
    {
        $this->access()->grantPortal(
            AccessMutationContext::user($this->admin),
            $this->company,
            $this->client,
            PortalAccessScope::Projects,
            [$this->second->public_id, $this->second->public_id],
        );

        $this->assertSame([$this->second->id], app(PortalAccess::class)->visibleProjectIds($this->company, $this->client));
        $this->assertSame(['scope' => 'projects', 'projects' => [$this->second->public_id]], AccessAuditEvent::query()->sole()->after);
    }

    public function test_only_owners_admins_and_the_system_manage_portal_access(): void
    {
        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $foreignAdmin = $this->staff($elsewhere, 'admin');

        foreach ([$this->member, $foreignAdmin, User::factory()->create()] as $actor) {
            $this->assertRefused(AccessRefusal::NotAuthorized, fn () => $this->access()->grantPortal(
                AccessMutationContext::user($actor), $this->company, $this->client, PortalAccessScope::Company,
            ));
        }
        $this->assertFalse($this->portalMembershipExists());

        $this->access()->grantPortal(AccessMutationContext::system(), $this->company, $this->client, PortalAccessScope::Company);
        $this->assertTrue($this->portalMembershipExists());
        $this->assertSame('system', AccessAuditEvent::query()->sole()->actor_type);

        $this->assertRefused(AccessRefusal::NotAuthorized, fn () => $this->access()->revokePortal(AccessMutationContext::user($this->member), $this->company, $this->client));
        $this->assertRefused(AccessRefusal::NotAuthorized, fn () => $this->access()->changePortalScope(AccessMutationContext::user($this->member), $this->company, $this->client, PortalAccessScope::Company));
        $this->assertTrue($this->portalMembershipExists());
    }

    public function test_granting_twice_is_refused(): void
    {
        $context = AccessMutationContext::user($this->owner);
        $this->access()->grantPortal($context, $this->company, $this->client, PortalAccessScope::Company);

        $this->assertRefused(AccessRefusal::AlreadyPortalMember, fn () => $this->access()->grantPortal($context, $this->company, $this->client, PortalAccessScope::Projects, [$this->first->public_id]));
        $this->assertSame(1, AccessAuditEvent::query()->count());
    }

    public function test_company_wide_access_carries_no_project_limits(): void
    {
        $this->assertRefused(AccessRefusal::ProjectsWithCompanyScope, fn () => $this->access()->grantPortal(
            AccessMutationContext::user($this->owner), $this->company, $this->client, PortalAccessScope::Company, [$this->first->public_id],
        ));
        $this->assertFalse($this->portalMembershipExists());
    }

    public function test_a_project_of_another_client_or_workspace_is_refused(): void
    {
        $sibling = $this->project($this->company($this->workspace, 'sibling-client'), 'Sibling');
        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $foreign = $this->project($this->company($elsewhere, 'foreign-client'), 'Foreign');
        $context = AccessMutationContext::user($this->owner);

        foreach ([$sibling, $foreign] as $outside) {
            $this->assertRefused(AccessRefusal::ProjectOutsideCompany, fn () => $this->access()->grantPortal(
                $context, $this->company, $this->client, PortalAccessScope::Projects, [$this->first->public_id, $outside->public_id],
            ));
        }
        $this->assertFalse($this->portalMembershipExists());

        $this->access()->grantPortal($context, $this->company, $this->client, PortalAccessScope::Projects, [$this->first->public_id]);
        $this->assertRefused(AccessRefusal::ProjectOutsideCompany, fn () => $this->access()->changePortalScope(
            $context, $this->company, $this->client, PortalAccessScope::Projects, [$foreign->public_id],
        ));
        $this->assertSame([$this->first->id], app(PortalAccess::class)->visibleProjectIds($this->company, $this->client));
        $this->assertSame(0, DB::table('client_portal_project_access')->where('workspace_id', $elsewhere->id)->count());
    }

    public function test_scope_changes_replace_or_clear_the_project_limits(): void
    {
        $context = AccessMutationContext::user($this->admin);
        $this->access()->grantPortal($context, $this->company, $this->client, PortalAccessScope::Projects, [$this->first->public_id]);

        $membership = $this->access()->changePortalScope($context, $this->company, $this->client, PortalAccessScope::Projects, [$this->second->public_id]);
        $this->assertSame('projects', $membership->access_scope);
        $this->assertSame([$this->second->id], app(PortalAccess::class)->visibleProjectIds($this->company, $this->client));

        $this->access()->changePortalScope($context, $this->company, $this->client, PortalAccessScope::Company);
        $this->assertNull(app(PortalAccess::class)->visibleProjectIds($this->company, $this->client));
        $this->assertSame(0, DB::table('client_portal_project_access')->count());

        $changes = AccessAuditEvent::query()->where('action', 'portal.scope_changed')->orderBy('id')->get();
        $this->assertSame([
            [['scope' => 'projects', 'projects' => [$this->first->public_id]], ['scope' => 'projects', 'projects' => [$this->second->public_id]]],
            [['scope' => 'projects', 'projects' => [$this->second->public_id]], ['scope' => 'company', 'projects' => []]],
        ], $changes->map(fn (AccessAuditEvent $event): array => [$event->before, $event->after])->all());
    }

    public function test_changing_or_revoking_access_nobody_holds_is_refused(): void
    {
        $context = AccessMutationContext::user($this->owner);

        $this->assertRefused(AccessRefusal::NotPortalMember, fn () => $this->access()->changePortalScope($context, $this->company, $this->client, PortalAccessScope::Company));
        $this->assertRefused(AccessRefusal::NotPortalMember, fn () => $this->access()->revokePortal($context, $this->company, $this->client));
        $this->assertSame(0, AccessAuditEvent::query()->count());
    }

    public function test_revoking_removes_the_membership_and_its_limits_and_leaves_staff_access_alone(): void
    {
        $context = AccessMutationContext::user($this->owner);
        $this->access()->grantPortal($context, $this->company, $this->member, PortalAccessScope::Projects, [$this->second->public_id, $this->first->public_id]);
        $expected = [$this->first->public_id, $this->second->public_id];
        sort($expected);

        $revoked = $this->access()->revokePortal($context, $this->company, $this->member);

        $this->assertEquals(new PortalAccessState(PortalAccessScope::Projects, $expected), $revoked);
        $this->assertFalse(ClientCompanyMembership::query()->where('user_id', $this->member->id)->exists());
        $this->assertSame(0, DB::table('client_portal_project_access')->count());
        $this->assertSame('member', $this->workspace->memberships()->where('user_id', $this->member->id)->value('role'));

        $event = AccessAuditEvent::query()->where('action', 'portal.revoked')->sole();
        $this->assertSame(['scope' => 'projects', 'projects' => $expected], $event->before);
        $this->assertNull($event->after);
    }

    public function test_an_actor_demoted_after_the_fast_check_cannot_change_portal_access(): void
    {
        $this->access()->grantPortal(AccessMutationContext::system(), $this->company, $this->client, PortalAccessScope::Company);
        $this->onceTheWorkspaceIsLocked(fn () => DB::table('workspace_memberships')
            ->where('workspace_id', $this->workspace->id)->where('user_id', $this->admin->id)->update(['role' => 'member']));

        $this->assertRefused(AccessRefusal::NotAuthorized, fn () => $this->access()->revokePortal(AccessMutationContext::user($this->admin), $this->company, $this->client));

        $this->assertTrue($this->portalMembershipExists());
        $this->assertSame(1, AccessAuditEvent::query()->count());
    }

    public function test_portal_writes_stay_inside_the_companys_workspace(): void
    {
        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $elsewhereCompany = $this->company($elsewhere, 'elsewhere-client');
        $elsewhereProject = $this->project($elsewhereCompany, 'Elsewhere');
        $this->access()->grantPortal(AccessMutationContext::system(), $elsewhereCompany, $this->client, PortalAccessScope::Projects, [$elsewhereProject->public_id]);
        $context = AccessMutationContext::user($this->owner);

        // This workspace's owner reaches nothing of another workspace's client.
        $this->assertRefused(AccessRefusal::NotAuthorized, fn () => $this->access()->revokePortal($context, $elsewhereCompany, $this->client));

        $writes = $this->writesIssuedBy(function () use ($context): void {
            $this->access()->grantPortal($context, $this->company, $this->client, PortalAccessScope::Projects, [$this->first->public_id]);
            $this->access()->changePortalScope($context, $this->company, $this->client, PortalAccessScope::Projects, [$this->second->public_id]);
            $this->access()->revokePortal($context, $this->company, $this->client);
        });
        $this->assertEveryTenantOwnedWriteNamesItsWorkspace($writes, ['client_company_memberships', 'client_portal_project_access']);

        $this->assertSame([$elsewhereProject->id], app(PortalAccess::class)->visibleProjectIds($elsewhereCompany, $this->client));
        $this->assertSame(1, AccessAuditEvent::query()->where('workspace_id', $elsewhere->id)->count());
    }

    private function access(): WorkspaceAccess
    {
        return app(WorkspaceAccess::class);
    }

    private function portalMembershipExists(): bool
    {
        return ClientCompanyMembership::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('client_company_id', $this->company->id)
            ->where('user_id', $this->client->id)
            ->exists();
    }
}
