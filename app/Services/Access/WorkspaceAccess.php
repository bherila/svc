<?php

namespace App\Services\Access;

use App\Exceptions\AccessMutationRefused;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\Access\AccessAuditAction;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\AccessRefusal;
use App\Support\Access\PortalAccessScope;
use App\Support\Access\PortalAccessState;
use App\Support\Access\ProjectAssignment;
use App\Support\Access\StaffAccess;
use App\Support\Access\StaffAuthority;
use App\Support\Access\StaffRemoval;
use App\Support\Access\StaffRole;
use App\Support\AgentApi\ProjectRole;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The one place a workspace's staff and portal access is changed.
 *
 * Every operation is explicit - grant, change, remove - rather than a sync of
 * whatever a caller sends, so each one can say exactly what it refuses and
 * exactly what it wrote. Each one:
 *
 * 1. refuses early from an unlocked read, so an obviously unauthorised call
 *    costs no lock;
 * 2. takes the workspace access lock ({@see AccessCoordinator});
 * 3. re-reads the actor's authority under that lock and decides again, so an
 *    actor demoted in between cannot complete the change;
 * 4. writes, advances the revision of every project whose access changed, and
 *    appends an audit event carrying the context's operation and request ids,
 *    all in the same transaction.
 *
 * Who may do what lives in {@see StaffAuthority}. Refusals are
 * {@see AccessMutationRefused}, and nothing has been written when one is
 * thrown.
 *
 * Staff access and portal access are independent: a person can hold both, and
 * no staff operation touches a portal membership or the other way round.
 */
final class WorkspaceAccess
{
    private const PORTAL_ROLE = 'client';

    public function __construct(
        private readonly AccessCoordinator $coordinator,
        private readonly StaffAuthority $authority,
        private readonly AccessAuditLog $audit,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * A person's staff membership and project assignments, in one query, or
     * null when they have no staff membership here.
     */
    public function staffAccess(Workspace $workspace, User $user): ?StaffAccess
    {
        $rows = DB::table('workspace_memberships')
            ->leftJoin('client_project_memberships', function (JoinClause $join): void {
                $join->on('client_project_memberships.workspace_id', '=', 'workspace_memberships.workspace_id')
                    ->on('client_project_memberships.user_id', '=', 'workspace_memberships.user_id');
            })
            ->leftJoin('client_projects', function (JoinClause $join): void {
                $join->on('client_projects.id', '=', 'client_project_memberships.client_project_id')
                    ->on('client_projects.workspace_id', '=', 'workspace_memberships.workspace_id');
            })
            ->where('workspace_memberships.workspace_id', $workspace->id)
            ->where('workspace_memberships.user_id', $user->id)
            ->orderBy('client_projects.id')
            ->get([
                'workspace_memberships.public_id as membership_public_id',
                'workspace_memberships.role as staff_role',
                'client_projects.public_id as project_public_id',
                'client_project_memberships.role as project_role',
            ]);

        $first = $rows->first();
        if ($first === null) {
            return null;
        }

        $assignments = [];
        foreach ($rows as $row) {
            if ($row->project_public_id !== null) {
                $assignments[] = new ProjectAssignment((string) $row->project_public_id, ProjectRole::from((string) $row->project_role));
            }
        }

        return new StaffAccess((string) $first->membership_public_id, StaffRole::from((string) $first->staff_role), $assignments);
    }

    /**
     * Give a person staff access. They must not already have any - a role
     * change goes through {@see changeStaffRole()}.
     */
    public function grantStaff(AccessMutationContext $context, Workspace $workspace, User $user, StaffRole $role): WorkspaceMembership
    {
        $decide = function (array $staff) use ($context, $user, $role): void {
            $this->refuseIf($this->authority->grant($context, $this->coordinator->actorRole($context, $staff), $role));
            $this->refuseIf(isset($staff[$user->id]) ? AccessRefusal::AlreadyStaff : null);
        };

        $decide($this->coordinator->staff($workspace, [$context->actorId(), $user->id], lock: false));

        return $this->coordinator->locked($workspace, function (Workspace $locked) use ($context, $user, $role, $decide): WorkspaceMembership {
            $decide($this->coordinator->staff($locked, [$context->actorId(), $user->id], lock: true));

            $membership = $locked->memberships()->create(['user_id' => $user->id, 'role' => $role->value]);
            $this->audit->record($context, $locked, AccessAuditAction::StaffGranted, $user, null, ['role' => $role->value]);

            return $membership;
        });
    }

    /**
     * Change a person's staff role in place.
     *
     * An update, never a delete and insert: project assignments hang off the
     * membership through a cascading key, and a member made admin and back
     * again keeps every assignment they had. The revision of each assigned
     * project advances, because the person's effective role on it changed.
     */
    public function changeStaffRole(AccessMutationContext $context, Workspace $workspace, User $user, StaffRole $role): WorkspaceMembership
    {
        $decide = function (array $staff) use ($context, $user, $role): array {
            [$membership, $current] = $this->subject($context, $staff, $user);
            $this->refuseIf($this->authority->change($context, $this->coordinator->actorRole($context, $staff), $user->id, $current, $role));

            return [$membership, $current];
        };

        $decide($this->coordinator->staff($workspace, [$context->actorId(), $user->id], lock: false));

        return $this->coordinator->locked($workspace, function (Workspace $locked) use ($context, $user, $role, $decide): WorkspaceMembership {
            $projects = $this->coordinator->lockProjects($locked, $this->assignedProjectIds($locked, $user));
            /** @var WorkspaceMembership $membership */
            [$membership, $current] = $decide($this->coordinator->staff($locked, [$context->actorId(), $user->id], lock: true));

            if ($current === $role) {
                return $membership;
            }

            [, $assigned] = $this->lockedAssignments($locked, $user, $projects);
            $membership->forceFill(['role' => $role->value])->save();
            foreach ($assigned as $project) {
                $project->advanceAgentRevision();
            }
            $this->audit->record($context, $locked, AccessAuditAction::StaffRoleChanged, $user, ['role' => $current->value], ['role' => $role->value]);

            return $membership;
        });
    }

    /**
     * Remove a person's staff access, and every project assignment with it.
     *
     * The assignments are deleted here, one by one, each advancing its
     * project's revision and leaving its own audit event, before the
     * membership goes. The cascading key from assignments to memberships
     * would delete them too, silently; it stays only as a backstop. Portal
     * memberships are untouched.
     */
    public function removeStaff(AccessMutationContext $context, Workspace $workspace, User $user): StaffRemoval
    {
        $decide = function (array $staff) use ($context, $user): array {
            [$membership, $current] = $this->subject($context, $staff, $user);
            $this->refuseIf($this->authority->remove($context, $this->coordinator->actorRole($context, $staff), $user->id, $current));

            return [$membership, $current];
        };

        $decide($this->coordinator->staff($workspace, [$context->actorId(), $user->id], lock: false));

        return $this->coordinator->locked($workspace, function (Workspace $locked) use ($context, $user, $decide): StaffRemoval {
            $projects = $this->coordinator->lockProjects($locked, $this->assignedProjectIds($locked, $user));
            /** @var WorkspaceMembership $membership */
            [$membership, $current] = $decide($this->coordinator->staff($locked, [$context->actorId(), $user->id], lock: true));

            [$assignments, $projects] = $this->lockedAssignments($locked, $user, $projects);

            $removed = [];
            foreach ($assignments as $assignment) {
                $project = $projects->get($this->projectIdOf($assignment)) ?? throw new LogicException('An assigned project was not locked.');
                $assignment->delete();
                $project->advanceAgentRevision();

                $removedAssignment = new ProjectAssignment($project->public_id, $assignment->role);
                $this->audit->record($context, $locked, AccessAuditAction::StaffAssignmentRemoved, $user, $removedAssignment->toArray(), null);
                $removed[] = $removedAssignment;
            }

            $membership->delete();
            $this->audit->record($context, $locked, AccessAuditAction::StaffRemoved, $user, [
                'role' => $current->value,
                'assignments' => array_map(static fn (ProjectAssignment $assignment): array => $assignment->toArray(), $removed),
            ], null);

            return new StaffRemoval($user->public_id, $current, $removed);
        });
    }

    /**
     * Give a person portal access to a client company.
     *
     * @param  list<string>  $projectPublicIds  the projects a `projects` scope is limited to; empty for `company`
     */
    public function grantPortal(AccessMutationContext $context, ClientCompany $company, User $user, PortalAccessScope $scope, array $projectPublicIds = []): ClientCompanyMembership
    {
        return $this->portal($context, $company, $user, $projectPublicIds, function (Workspace $workspace, ClientCompany $company, Collection $locked) use ($context, $user, $scope, $projectPublicIds): ClientCompanyMembership {
            $this->refuseIf($this->lockedPortalMembership($workspace, $company, $user) instanceof ClientCompanyMembership ? AccessRefusal::AlreadyPortalMember : null);
            $projects = $this->portalProjects($company, $scope, $projectPublicIds, $locked);

            $membership = new ClientCompanyMembership;
            $membership->forceFill([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'user_id' => $user->id,
                'role' => self::PORTAL_ROLE,
                'access_scope' => $scope->value,
            ])->save();
            $after = $this->replaceProjectLimits($workspace, $membership, $scope, $projects);
            $this->audit->record($context, $workspace, AccessAuditAction::PortalGranted, $user, null, $after->toArray(), $company->public_id);

            return $membership;
        });
    }

    /**
     * Change what a portal membership reaches. A `company` scope clears every
     * project limit; a `projects` scope replaces them with exactly the given
     * set.
     *
     * @param  list<string>  $projectPublicIds
     */
    public function changePortalScope(AccessMutationContext $context, ClientCompany $company, User $user, PortalAccessScope $scope, array $projectPublicIds = []): ClientCompanyMembership
    {
        return $this->portal($context, $company, $user, $projectPublicIds, function (Workspace $workspace, ClientCompany $company, Collection $locked) use ($context, $user, $scope, $projectPublicIds): ClientCompanyMembership {
            $membership = $this->lockedPortalMembership($workspace, $company, $user) ?? throw new AccessMutationRefused(AccessRefusal::NotPortalMember);
            $before = $this->lockedPortalState($workspace, $membership, $locked);
            $projects = $this->portalProjects($company, $scope, $projectPublicIds, $locked);

            $membership->forceFill(['access_scope' => $scope->value])->save();
            $after = $this->replaceProjectLimits($workspace, $membership, $scope, $projects);
            $this->audit->record($context, $workspace, AccessAuditAction::PortalScopeChanged, $user, $before->toArray(), $after->toArray(), $company->public_id);

            return $membership;
        });
    }

    /**
     * Take a person's portal access to a client company away, with its project
     * limits. Returns what it reached, for the caller to disclose.
     */
    public function revokePortal(AccessMutationContext $context, ClientCompany $company, User $user): PortalAccessState
    {
        return $this->portal($context, $company, $user, [], function (Workspace $workspace, ClientCompany $company, Collection $locked) use ($context, $user): PortalAccessState {
            $membership = $this->lockedPortalMembership($workspace, $company, $user) ?? throw new AccessMutationRefused(AccessRefusal::NotPortalMember);
            $before = $this->lockedPortalState($workspace, $membership, $locked);

            $this->deleteProjectLimits($workspace, $membership);
            $membership->delete();
            $this->audit->record($context, $workspace, AccessAuditAction::PortalRevoked, $user, $before->toArray(), null, $company->public_id);

            return $before;
        });
    }

    /**
     * The person being changed, and their current role, among memberships read
     * by {@see AccessCoordinator::staff()}.
     *
     * Asks whether the actor may manage access at all before it says anything
     * about the person, so a refused actor learns nothing about who is staff.
     *
     * @param  array<int, WorkspaceMembership>  $staff
     * @return array{WorkspaceMembership, StaffRole}
     */
    private function subject(AccessMutationContext $context, array $staff, User $user): array
    {
        $this->refuseIf($this->authority->manage($context, $this->coordinator->actorRole($context, $staff)));
        $membership = $staff[$user->id] ?? throw new AccessMutationRefused(AccessRefusal::NotStaff);
        $role = StaffRole::tryFrom($membership->role) ?? throw new AccessMutationRefused(AccessRefusal::UnsupportedRole);

        return [$membership, $role];
    }

    /**
     * A person's project assignments as they are now, and the locked project
     * behind each one.
     *
     * Called after the staff re-check, with the projects already locked from
     * {@see assignedProjectIds()}. That was a plain read, and inside an
     * enclosing transaction whose snapshot predates the workspace lock it can
     * miss an assignment committed since, or name one removed since. This is a
     * locking read, which sees the latest committed rows, so it decides: any
     * project only it names is locked here, and a project only the plain read
     * named is left out of what is returned, so no revision is advanced for a
     * project whose assignment is already gone.
     *
     * Locking a further project after the staff memberships only happens in
     * that stale-snapshot case, and cannot deadlock: every other transaction
     * that locks a project and then a membership or assignment does so under
     * the workspace access lock this one holds.
     *
     * @param  Collection<int, ClientProject>  $projects  locked from the plain read, keyed by id
     * @return array{Collection<int, ClientProjectMembership>, Collection<int, ClientProject>}
     */
    private function lockedAssignments(Workspace $workspace, User $user, Collection $projects): array
    {
        $assignments = ClientProjectMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->orderBy('client_project_id')
            ->tap(Locks::forUpdate())
            ->get();

        $current = $this->projectIdsOf($assignments);
        $missing = array_values(array_diff($current, $projects->keys()->all()));
        // Filtered by key rather than with `only()`, which on an Eloquent
        // collection re-indexes the result and loses the id keys.
        $projects = $projects->union($this->coordinator->lockProjects($workspace, $missing))
            ->filter(static fn (ClientProject $project, int $id): bool => in_array($id, $current, true))
            ->sortKeys();

        return [$assignments, $projects];
    }

    /** @return list<int> */
    private function assignedProjectIds(Workspace $workspace, User $user): array
    {
        return $this->projectIdsOf(ClientProjectMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->get(['client_project_id']));
    }

    /**
     * @param  Collection<int, ClientProjectMembership>  $assignments
     * @return list<int>
     */
    private function projectIdsOf(Collection $assignments): array
    {
        return array_values($assignments->map(fn (ClientProjectMembership $assignment): int => $this->projectIdOf($assignment))->all());
    }

    private function projectIdOf(ClientProjectMembership $assignment): int
    {
        return (int) $assignment->getAttribute('client_project_id');
    }

    /**
     * Portal work, refused early from an unlocked read and then re-authorised
     * under the workspace access lock against the company as stored.
     *
     * Before the re-check it locks, in id order, every project the change can
     * name: the ones the person's portal limits point at now, and the ones
     * the caller asked for. Locking them is what makes their reads current -
     * the limits' public ids for the audit's before state, and the check that
     * each requested project belongs to this company - rather than a plain
     * read that an enclosing transaction's older snapshot could answer.
     *
     * @template TResult
     *
     * @param  list<string>  $requestedPublicIds
     * @param  \Closure(Workspace, ClientCompany, Collection<int, ClientProject>): TResult  $work
     * @return TResult
     */
    private function portal(AccessMutationContext $context, ClientCompany $company, User $user, array $requestedPublicIds, \Closure $work): mixed
    {
        $workspace = Workspace::query()->whereKey($company->workspace_id)->firstOrFail();
        $authorize = function (Workspace $workspace, bool $lock) use ($context): void {
            $staff = $this->coordinator->staff($workspace, [$context->actorId()], $lock);
            $this->refuseIf($this->authority->manage($context, $this->coordinator->actorRole($context, $staff)));
        };

        $authorize($workspace, false);

        return $this->coordinator->locked($workspace, function (Workspace $locked) use ($company, $user, $requestedPublicIds, $work, $authorize): mixed {
            // A plain read, so it fails closed: a company it cannot see is not found.
            $stored = ClientCompany::query()->where('workspace_id', $locked->id)->whereKey($company->id)->firstOrFail();
            $projects = $this->lockPortalProjects($locked, $stored, $user, $requestedPublicIds);
            $authorize($locked, true);

            return $work($locked, $stored, $projects);
        });
    }

    /**
     * Lock the projects a portal change can name, keyed by id.
     *
     * Which limits exist comes from a plain read here; the locking read in
     * {@see lockedPortalState()} is what decides, and locks any project only
     * it finds.
     *
     * @param  list<string>  $requestedPublicIds
     * @return Collection<int, ClientProject>
     */
    private function lockPortalProjects(Workspace $workspace, ClientCompany $company, User $user, array $requestedPublicIds): Collection
    {
        $limitIds = DB::table('client_portal_project_access')
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_company_membership_id', ClientCompanyMembership::query()
                ->select('id')
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $company->id)
                ->where('user_id', $user->id))
            ->pluck('client_project_id')
            ->all();

        if ($limitIds === [] && $requestedPublicIds === []) {
            return new Collection;
        }

        return ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->where(function (Builder $projects) use ($limitIds, $company, $requestedPublicIds): void {
                $projects->whereIn('id', $limitIds)->orWhere(function (Builder $requested) use ($company, $requestedPublicIds): void {
                    $requested->where('client_company_id', $company->id)->whereIn('public_id', $requestedPublicIds);
                });
            })
            ->orderBy('id')
            ->tap(Locks::forUpdate())
            ->get()
            ->keyBy('id');
    }

    private function lockedPortalMembership(Workspace $workspace, ClientCompany $company, User $user): ?ClientCompanyMembership
    {
        return ClientCompanyMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->where('user_id', $user->id)
            ->tap(Locks::forUpdate())
            ->first();
    }

    /**
     * A portal membership's current reach, from a locking read of its project
     * limits and the locked project rows they point at.
     *
     * A limit the plain read in {@see lockPortalProjects()} missed - possible
     * only inside an enclosing transaction whose snapshot predates the
     * workspace lock - has its project locked here, so its public id is read
     * current too. That later project lock cannot deadlock: every other
     * transaction that locks a project and then a membership does so under the
     * workspace access lock this one holds.
     *
     * @param  Collection<int, ClientProject>  $projects  locked, keyed by id
     */
    private function lockedPortalState(Workspace $workspace, ClientCompanyMembership $membership, Collection $projects): PortalAccessState
    {
        $projectIds = array_map(intval(...), DB::table('client_portal_project_access')
            ->where('workspace_id', $workspace->id)
            ->where('client_company_membership_id', $membership->id)
            ->orderBy('id')
            ->tap(Locks::forUpdate())
            ->pluck('client_project_id')
            ->all());

        $missing = array_values(array_diff($projectIds, $projects->keys()->all()));
        $publicIds = array_values($projects->union($this->coordinator->lockProjects($workspace, $missing))
            ->filter(static fn (ClientProject $project, int $id): bool => in_array($id, $projectIds, true))
            ->map(static fn (ClientProject $project): string => $project->public_id)
            ->sort()
            ->all());

        // Anything but `projects` reaches the whole company, as PortalAccess reads it.
        $scope = $membership->access_scope === PortalAccessScope::Projects->value ? PortalAccessScope::Projects : PortalAccessScope::Company;

        return new PortalAccessState($scope, $publicIds);
    }

    /**
     * The projects a portal scope is limited to, each checked - against the
     * locked rows from {@see lockPortalProjects()} - to belong to this company
     * in this workspace.
     *
     * @param  list<string>  $projectPublicIds
     * @param  Collection<int, ClientProject>  $locked
     * @return Collection<int, ClientProject>
     */
    private function portalProjects(ClientCompany $company, PortalAccessScope $scope, array $projectPublicIds, Collection $locked): Collection
    {
        $publicIds = array_values(array_unique($projectPublicIds));

        if ($scope === PortalAccessScope::Company) {
            $this->refuseIf($publicIds === [] ? null : AccessRefusal::ProjectsWithCompanyScope);

            return new Collection;
        }

        $projects = $locked
            ->filter(static fn (ClientProject $project): bool => $project->client_company_id === $company->id && in_array($project->public_id, $publicIds, true))
            ->sortBy('public_id')
            ->values();

        $this->refuseIf($projects->count() === count($publicIds) ? null : AccessRefusal::ProjectOutsideCompany);

        return $projects;
    }

    /**
     * Replace a portal membership's project limits with exactly these.
     *
     * @param  Collection<int, ClientProject>  $projects
     */
    private function replaceProjectLimits(Workspace $workspace, ClientCompanyMembership $membership, PortalAccessScope $scope, Collection $projects): PortalAccessState
    {
        $this->deleteProjectLimits($workspace, $membership);

        $now = $this->clock->now($workspace);
        foreach ($projects as $project) {
            DB::table('client_portal_project_access')->insert([
                'workspace_id' => $workspace->id,
                'client_company_membership_id' => $membership->id,
                'client_project_id' => $project->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return new PortalAccessState($scope, array_values($projects->pluck('public_id')->map(strval(...))->all()));
    }

    private function deleteProjectLimits(Workspace $workspace, ClientCompanyMembership $membership): void
    {
        DB::table('client_portal_project_access')
            ->where('workspace_id', $workspace->id)
            ->where('client_company_membership_id', $membership->id)
            ->delete();
    }

    private function refuseIf(?AccessRefusal $refusal): void
    {
        if ($refusal !== null) {
            throw new AccessMutationRefused($refusal);
        }
    }
}
