<?php

namespace Tests\Feature\Access;

use App\Models\AccessAuditEvent;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Access\WorkspaceAccess;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\AccessRefusal;
use App\Support\Access\ProjectAssignment;
use App\Support\Access\StaffRole;
use App\Support\AgentApi\ProjectRole;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsAccessFixtures;
use Tests\Concerns\CapturesTenantOwnedWrites;
use Tests\TestCase;

final class WorkspaceStaffAccessTest extends TestCase
{
    use BuildsAccessFixtures;
    use CapturesTenantOwnedWrites;
    use RefreshDatabase;

    private const OPERATION = 'op-staff-access-0000000000000000001';

    private Workspace $workspace;

    private User $owner;

    private User $admin;

    private User $member;

    private ClientCompany $company;

    private ClientProject $first;

    private ClientProject $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::query()->create(['name' => 'Access', 'slug' => 'access']);
        $this->owner = $this->staff($this->workspace, 'owner');
        $this->admin = $this->staff($this->workspace, 'admin');
        $this->member = $this->staff($this->workspace, 'member');
        $this->company = $this->company($this->workspace, 'access-client');
        $this->first = $this->project($this->company, 'First');
        $this->second = $this->project($this->company, 'Second');
        $this->assign($this->member, $this->second, ProjectRole::Viewer);
        $this->assign($this->member, $this->first, ProjectRole::Manager);
    }

    public function test_an_admin_grants_member_access_and_the_grant_is_audited(): void
    {
        $person = User::factory()->create();

        $membership = $this->access()->grantStaff(
            AccessMutationContext::user($this->admin, self::OPERATION, 'request-grant-1'),
            $this->workspace,
            $person,
            StaffRole::Member,
        );

        $this->assertSame('member', $membership->role);
        $this->assertSame($this->workspace->id, $membership->workspace_id);
        $this->assertSame('member', $this->roleOf($person));

        $event = AccessAuditEvent::query()->sole();
        $this->assertSame([
            'workspace_id' => $this->workspace->id,
            'actor_type' => 'user',
            'actor_user_id' => $this->admin->id,
            'subject_user_id' => $person->id,
            'action' => 'staff.granted',
            'client_company_public_id' => null,
            'before_state' => null,
            'after_state' => ['role' => 'member'],
            'operation_id' => self::OPERATION,
            'request_id' => 'request-grant-1',
        ], $event->only(['workspace_id', 'actor_type', 'actor_user_id', 'subject_user_id', 'action', 'client_company_public_id', 'before_state', 'after_state', 'operation_id', 'request_id']));
    }

    public function test_the_system_grants_admin_access_and_is_recorded_as_the_system(): void
    {
        $person = User::factory()->create();

        $this->access()->grantStaff(AccessMutationContext::system(), $this->workspace, $person, StaffRole::Admin);

        $this->assertSame('admin', $this->roleOf($person));
        $event = AccessAuditEvent::query()->sole();
        $this->assertSame('system', $event->actor_type);
        $this->assertNull($event->actor_user_id);
    }

    public function test_granting_someone_who_is_already_staff_is_refused(): void
    {
        $this->assertRefused(AccessRefusal::AlreadyStaff, fn () => $this->access()->grantStaff(
            AccessMutationContext::user($this->owner),
            $this->workspace,
            $this->member,
            StaffRole::Admin,
        ));

        $this->assertSame('member', $this->roleOf($this->member));
    }

    public function test_a_role_change_happens_in_place_and_keeps_every_assignment(): void
    {
        $membershipId = $this->membership($this->member)->id;
        $assignmentIds = $this->assignmentIds($this->member);
        $revisions = $this->revisions();
        $context = AccessMutationContext::user($this->owner);

        $this->access()->changeStaffRole($context, $this->workspace, $this->member, StaffRole::Admin);
        $this->assertSame('admin', $this->roleOf($this->member));
        $this->assertSame($membershipId, $this->membership($this->member)->id);
        $this->assertSame($assignmentIds, $this->assignmentIds($this->member));

        $this->access()->changeStaffRole($context, $this->workspace, $this->member, StaffRole::Member);
        $this->assertSame('member', $this->roleOf($this->member));
        $this->assertSame($membershipId, $this->membership($this->member)->id);
        $this->assertSame($assignmentIds, $this->assignmentIds($this->member));

        // Each change moved the effective role on both assigned projects.
        $this->assertSame([$revisions[0] + 2, $revisions[1] + 2], $this->revisions());
        $this->assertSame(
            [[['role' => 'member'], ['role' => 'admin']], [['role' => 'admin'], ['role' => 'member']]],
            AccessAuditEvent::query()->orderBy('id')->get()->map(fn (AccessAuditEvent $event): array => [$event->before_state, $event->after_state])->all(),
        );
        $this->assertSame(['staff.role_changed', 'staff.role_changed'], AccessAuditEvent::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_changing_to_the_role_already_held_writes_nothing(): void
    {
        $revisions = $this->revisions();

        $membership = $this->access()->changeStaffRole(AccessMutationContext::user($this->admin), $this->workspace, $this->member, StaffRole::Member);

        $this->assertSame('member', $membership->role);
        $this->assertSame($revisions, $this->revisions());
        $this->assertSame(0, AccessAuditEvent::query()->count());
    }

    public function test_removing_staff_deletes_each_assignment_explicitly_and_reports_it(): void
    {
        ClientCompanyMembership::query()->create([
            'client_company_id' => $this->company->id,
            'user_id' => $this->member->id,
            'role' => 'client',
            'access_scope' => 'company',
        ]);
        $revisions = $this->revisions();
        $deletes = [];
        DB::listen(static function (QueryExecuted $query) use (&$deletes): void {
            if (str_starts_with(strtolower($query->sql), 'delete from "client_project_memberships"')) {
                $deletes[] = $query->sql;
            }
        });

        $removal = $this->access()->removeStaff(AccessMutationContext::user($this->admin, self::OPERATION), $this->workspace, $this->member);

        $this->assertSame($this->member->public_id, $removal->userPublicId);
        $this->assertSame(StaffRole::Member, $removal->role);
        $this->assertEquals([
            new ProjectAssignment($this->first->public_id, ProjectRole::Manager),
            new ProjectAssignment($this->second->public_id, ProjectRole::Viewer),
        ], $removal->removedAssignments);

        $this->assertNull($this->membership($this->member));
        $this->assertSame([], $this->assignmentIds($this->member));
        $this->assertCount(2, $deletes, 'Each assignment is deleted by the boundary, not left to the cascading key.');
        $this->assertSame([$revisions[0] + 1, $revisions[1] + 1], $this->revisions());
        $this->assertTrue(
            ClientCompanyMembership::query()->where('user_id', $this->member->id)->where('client_company_id', $this->company->id)->exists(),
            'Removing staff access leaves portal access alone.',
        );

        $events = AccessAuditEvent::query()->orderBy('id')->get();
        $this->assertSame(['staff.assignment_removed', 'staff.assignment_removed', 'staff.removed'], $events->pluck('action')->all());
        $this->assertSame(['project' => $this->first->public_id, 'role' => 'manager'], $events[0]->before_state);
        $this->assertSame(['project' => $this->second->public_id, 'role' => 'viewer'], $events[1]->before_state);
        $this->assertSame([
            'role' => 'member',
            'assignments' => [
                ['project' => $this->first->public_id, 'role' => 'manager'],
                ['project' => $this->second->public_id, 'role' => 'viewer'],
            ],
        ], $events[2]->before_state);
        $this->assertSame([self::OPERATION], $events->pluck('operation_id')->unique()->values()->all());
        $this->assertSame([$this->member->id], $events->pluck('subject_user_id')->unique()->values()->all());
    }

    public function test_an_owner_removes_an_admin_who_has_no_assignments(): void
    {
        $removal = $this->access()->removeStaff(AccessMutationContext::user($this->owner), $this->workspace, $this->admin);

        $this->assertSame(StaffRole::Admin, $removal->role);
        $this->assertSame([], $removal->removedAssignments);
        $this->assertNull($this->membership($this->admin));
        $this->assertSame(['staff.removed'], AccessAuditEvent::query()->pluck('action')->all());
    }

    public function test_ownership_is_never_changed_or_removed(): void
    {
        $system = AccessMutationContext::system();

        $this->assertRefused(AccessRefusal::OwnerProtected, fn () => $this->access()->removeStaff($system, $this->workspace, $this->owner));
        $this->assertRefused(AccessRefusal::OwnerProtected, fn () => $this->access()->changeStaffRole($system, $this->workspace, $this->owner, StaffRole::Admin));
        $this->assertRefused(AccessRefusal::OwnerProtected, fn () => $this->access()->changeStaffRole($system, $this->workspace, $this->member, StaffRole::Owner));
        $this->assertRefused(AccessRefusal::OwnerProtected, fn () => $this->access()->grantStaff($system, $this->workspace, User::factory()->create(), StaffRole::Owner));

        $this->assertSame('owner', $this->roleOf($this->owner));
        $this->assertSame('member', $this->roleOf($this->member));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function authorityMatrix(): iterable
    {
        foreach (['owner' => ['member' => true, 'admin' => true], 'admin' => ['member' => true, 'admin' => false], 'member' => ['member' => false, 'admin' => false]] as $actor => $targets) {
            foreach ($targets as $target => $allowed) {
                yield "{$actor} on {$target}" => [$actor, $target, $allowed];
            }
        }
    }

    #[DataProvider('authorityMatrix')]
    public function test_the_authority_matrix_holds_for_grant_change_and_removal(string $actorRole, string $targetRole, bool $allowed): void
    {
        $actor = $this->staff($this->workspace, $actorRole);
        $context = AccessMutationContext::user($actor);
        $role = StaffRole::from($targetRole);
        $other = $role === StaffRole::Admin ? StaffRole::Member : StaffRole::Admin;

        $grantee = User::factory()->create();
        $changed = $this->staff($this->workspace, $targetRole);
        $removed = $this->staff($this->workspace, $targetRole);

        $operations = [
            'grant' => fn () => $this->access()->grantStaff($context, $this->workspace, $grantee, $role),
            'change' => fn () => $this->access()->changeStaffRole($context, $this->workspace, $changed, $other),
            'remove' => fn () => $this->access()->removeStaff($context, $this->workspace, $removed),
        ];

        foreach ($operations as $name => $operation) {
            if ($allowed && ($name !== 'change' || $actorRole === 'owner')) {
                $operation();

                continue;
            }

            $this->assertRefused(AccessRefusal::NotAuthorized, $operation, $name);
        }

        if ($allowed) {
            $this->assertSame($targetRole, $this->roleOf($grantee));
            $this->assertNull($this->membership($removed));
        } else {
            $this->assertNull($this->membership($grantee));
            $this->assertSame($targetRole, $this->roleOf($changed));
            $this->assertSame($targetRole, $this->roleOf($removed));
        }
    }

    public function test_an_actor_cannot_change_or_remove_their_own_staff_access(): void
    {
        $self = AccessMutationContext::user($this->admin);

        $this->assertRefused(AccessRefusal::SelfChange, fn () => $this->access()->changeStaffRole($self, $this->workspace, $this->admin, StaffRole::Member));
        $this->assertRefused(AccessRefusal::SelfChange, fn () => $this->access()->removeStaff($self, $this->workspace, $this->admin));

        $this->assertSame('admin', $this->roleOf($this->admin));
    }

    public function test_changing_or_removing_someone_who_is_not_staff_is_refused(): void
    {
        $stranger = User::factory()->create();
        $context = AccessMutationContext::user($this->owner);

        $this->assertRefused(AccessRefusal::NotStaff, fn () => $this->access()->changeStaffRole($context, $this->workspace, $stranger, StaffRole::Admin));
        $this->assertRefused(AccessRefusal::NotStaff, fn () => $this->access()->removeStaff($context, $this->workspace, $stranger));
    }

    public function test_a_stored_role_the_boundary_does_not_know_is_refused_rather_than_guessed(): void
    {
        $odd = $this->staff($this->workspace, 'auditor');

        $this->assertRefused(AccessRefusal::UnsupportedRole, fn () => $this->access()->removeStaff(AccessMutationContext::system(), $this->workspace, $odd));
        $this->assertSame('auditor', $this->roleOf($odd));
    }

    public function test_an_actor_demoted_after_the_fast_check_cannot_remove_staff(): void
    {
        $revisions = $this->revisions();
        $this->onceTheWorkspaceIsLocked(fn () => DB::table('workspace_memberships')
            ->where('workspace_id', $this->workspace->id)->where('user_id', $this->admin->id)->update(['role' => 'member']));

        $this->assertRefused(AccessRefusal::NotAuthorized, fn () => $this->access()->removeStaff(AccessMutationContext::user($this->admin), $this->workspace, $this->member));

        $this->assertSame('member', $this->roleOf($this->member));
        $this->assertCount(2, $this->assignmentIds($this->member));
        $this->assertSame($revisions, $this->revisions());
        $this->assertSame(0, AccessAuditEvent::query()->count());
    }

    public function test_an_actor_removed_after_the_fast_check_cannot_grant_or_change_staff(): void
    {
        $person = User::factory()->create();
        $this->onceTheWorkspaceIsLocked(fn () => DB::table('workspace_memberships')
            ->where('workspace_id', $this->workspace->id)->where('user_id', $this->admin->id)->delete());

        $this->assertRefused(AccessRefusal::NotAuthorized, fn () => $this->access()->grantStaff(AccessMutationContext::user($this->admin), $this->workspace, $person, StaffRole::Member));
        $this->assertNull($this->membership($person));

        $this->onceTheWorkspaceIsLocked(fn () => DB::table('workspace_memberships')
            ->where('workspace_id', $this->workspace->id)->where('user_id', $this->owner->id)->update(['role' => 'admin']));

        $this->assertRefused(AccessRefusal::NotAuthorized, fn () => $this->access()->changeStaffRole(AccessMutationContext::user($this->owner), $this->workspace, $this->admin, StaffRole::Member));
        $this->assertSame('admin', $this->roleOf($this->admin));
        $this->assertSame(0, AccessAuditEvent::query()->count());
    }

    public function test_a_target_that_changed_after_the_fast_check_is_decided_on_its_current_role(): void
    {
        // The fast check sees a member an admin may remove; under the lock the
        // person is an admin, whom only an owner may remove.
        $this->onceTheWorkspaceIsLocked(fn () => DB::table('workspace_memberships')
            ->where('workspace_id', $this->workspace->id)->where('user_id', $this->member->id)->update(['role' => 'admin']));

        $this->assertRefused(AccessRefusal::NotAuthorized, fn () => $this->access()->removeStaff(AccessMutationContext::user($this->admin), $this->workspace, $this->member));
        $this->assertCount(2, $this->assignmentIds($this->member));
    }

    public function test_staff_writes_stay_inside_their_workspace(): void
    {
        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $elsewhereAdmin = $this->staff($elsewhere, 'admin');
        $elsewhereProject = $this->project($this->company($elsewhere, 'elsewhere-client'), 'Elsewhere');
        $this->addStaff($elsewhere, $this->member, 'member');
        $this->assign($this->member, $elsewhereProject, ProjectRole::Contributor);
        $elsewhereRevision = $elsewhereProject->refresh()->lock_version;

        // An admin of another workspace has no authority here.
        $this->assertRefused(AccessRefusal::NotAuthorized, fn () => $this->access()->removeStaff(AccessMutationContext::user($elsewhereAdmin), $this->workspace, $this->member));

        $writes = $this->writesIssuedBy(fn () => $this->access()->removeStaff(AccessMutationContext::user($this->admin), $this->workspace, $this->member));
        $this->assertEveryTenantOwnedWriteNamesItsWorkspace($writes, ['client_project_memberships', 'workspace_memberships', 'client_projects']);

        $this->assertNull($this->membership($this->member));
        $this->assertSame('member', WorkspaceMembership::query()->where('workspace_id', $elsewhere->id)->where('user_id', $this->member->id)->value('role'));
        $this->assertSame(1, ClientProjectMembership::query()->where('workspace_id', $elsewhere->id)->where('user_id', $this->member->id)->count());
        $this->assertSame($elsewhereRevision, $elsewhereProject->refresh()->lock_version);
        $this->assertSame(0, AccessAuditEvent::query()->where('workspace_id', $elsewhere->id)->count());

        // And this workspace's admin cannot reach the other one.
        $this->assertRefused(AccessRefusal::NotAuthorized, fn () => $this->access()->removeStaff(AccessMutationContext::user($this->admin), $elsewhere, $this->member));
    }

    public function test_grants_and_role_changes_name_their_workspace(): void
    {
        $person = User::factory()->create();
        $context = AccessMutationContext::user($this->owner);

        $writes = $this->writesIssuedBy(function () use ($context, $person): void {
            $this->access()->grantStaff($context, $this->workspace, $person, StaffRole::Member);
            $this->access()->changeStaffRole($context, $this->workspace, $this->member, StaffRole::Admin);
        });

        $this->assertEveryTenantOwnedWriteNamesItsWorkspace($writes, ['workspace_memberships', 'client_projects']);
    }

    public function test_the_staff_read_is_one_bounded_query_over_this_workspace(): void
    {
        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $this->addStaff($elsewhere, $this->member, 'admin');
        $this->assign($this->member, $this->project($this->company($elsewhere, 'elsewhere-client'), 'Elsewhere'), ProjectRole::Owner);

        DB::enableQueryLog();
        $access = $this->access()->staffAccess($this->workspace, $this->member);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertNotNull($access);
        $this->assertSame($this->membership($this->member)?->public_id, $access->membershipPublicId);
        $this->assertSame(StaffRole::Member, $access->role);
        $this->assertEquals([
            new ProjectAssignment($this->first->public_id, ProjectRole::Manager),
            new ProjectAssignment($this->second->public_id, ProjectRole::Viewer),
        ], $access->assignments);

        $admin = $this->access()->staffAccess($this->workspace, $this->admin);
        $this->assertSame(StaffRole::Admin, $admin?->role);
        $this->assertSame([], $admin->assignments);
        $this->assertNull($this->access()->staffAccess($this->workspace, User::factory()->create()));
    }

    private function access(): WorkspaceAccess
    {
        return app(WorkspaceAccess::class);
    }

    private function membership(User $user): ?WorkspaceMembership
    {
        return WorkspaceMembership::query()->where('workspace_id', $this->workspace->id)->where('user_id', $user->id)->first();
    }

    private function roleOf(User $user): ?string
    {
        return $this->membership($user)?->role;
    }

    /** @return list<int> */
    private function assignmentIds(User $user): array
    {
        return ClientProjectMembership::query()->where('workspace_id', $this->workspace->id)->where('user_id', $user->id)->orderBy('id')->pluck('id')->all();
    }

    /** @return array{int, int} */
    private function revisions(): array
    {
        return [$this->first->refresh()->lock_version, $this->second->refresh()->lock_version];
    }
}
