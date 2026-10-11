<?php

namespace App\Services\Access;

use App\Exceptions\AccessMutationRefused;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\Access\AccessAuditAction;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\AccessRefusal;
use App\Support\Access\StaffAuthority;
use App\Support\Access\StaffRole;
use App\Support\Concurrency\Locks;
use BWH\Auth\OAuth\DelegatedAccess\ApplicationAccessAdapter;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedAccessException;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedAccessSettings;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedCursor;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedRefusal;
use BWH\Auth\OAuth\DelegatedAccess\DelegatedRequestContext;
use BWH\Auth\OAuth\PendingAccount;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A workspace's staff, presented to the identity provider through delegated
 * access (contract version 3).
 *
 * The provider proves who is acting; this decides what they may see and change,
 * and every change goes through {@see WorkspaceAccess}, so the rules and the
 * audit trail are the ones every other access change has.
 *
 * - **Who may act.** The verified subject resolves to a local account through the
 *   same `oauth_provider`/`oauth_subject` binding sign-in uses, and that account
 *   must own or administer at least one workspace.
 * - **What is visible.** Only workspaces the actor owns or administers, and only
 *   the staff memberships people hold in them. Portal access, and staff access in
 *   any other workspace, is neither shown nor changed from here.
 * - **What is editable.** What {@see StaffAuthority} allows:
 *   never an owner, never the actor's own membership, an admin only by an owner.
 *   Owners and admins may grant `member`; only an owner may grant `admin`.
 * - **What a removal takes.** Removing a staff membership, by `remove` or by an
 *   update that drops it, also removes the person's project assignments in that
 *   workspace. Each editable membership discloses them as `removal_effects`, and
 *   the revision covers every assignment, so a confirmation shown against
 *   different assignments - even the same number of them - is refused. A role
 *   change keeps every assignment.
 * - **Revisions.** A digest of the cascade rules, the actor's reach (which
 *   workspaces they manage, and with which role) and the target's memberships
 *   and assignments there. Reads take them from one snapshot; writes compare them
 *   under the workspace access locks the changes take.
 * - **Provisioning.** An update with a null revision creates an account bound to
 *   the provider's subject, with a placeholder address sign-in replaces, and
 *   grants the memberships asked for. It never adopts an existing account.
 */
final class DelegatedStaffAccess implements ApplicationAccessAdapter
{
    public const PAGE_SIZE = 50;

    /**
     * What a staff removal takes with it, as part of every revision: a deploy
     * that changes the cascade changes this, so no confirmation made under the
     * old rules is accepted under the new ones.
     */
    public const CASCADE_RULES = 'staff-removal:project-assignments:v1';

    public const REMOVAL_NOTICE = 'Client portal access is unchanged.';

    private const ASSIGNMENTS_LABEL = 'Project assignments';

    public function __construct(
        private readonly WorkspaceAccess $access,
        private readonly AccessAuditLog $audit,
        private readonly DelegatedCursor $cursors,
        private readonly DelegatedAccessSettings $settings,
        private readonly Container $container,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  a request the package has validated
     * @return array<string, mixed> the operation's fields; the package adds the envelope
     *
     * @throws DelegatedAccessException
     */
    public function handle(string $actorSubject, array $payload): array
    {
        $actor = $this->boundUser($actorSubject) ?? throw self::refusal(DelegatedRefusal::NOT_AUTHORIZED);
        $reach = $this->reach($actor);

        if ($reach === []) {
            throw self::refusal(DelegatedRefusal::NOT_AUTHORIZED);
        }

        return match ((string) $payload['operation']) {
            'capabilities' => $this->capabilities(),
            'workspaces' => $this->workspacesPage($actorSubject, $reach, $payload),
            'subjects' => $this->subjectsPage($actorSubject, $reach, $payload),
            'read' => $this->state($actor, $reach, (string) $payload['subject']),
            'update' => $this->update($actor, $reach, $payload),
            'remove' => $this->remove($actor, $reach, $payload),
            default => throw self::refusal(DelegatedRefusal::INVALID_REQUEST),
        };
    }

    /** @return array<string, mixed> */
    private function capabilities(): array
    {
        // Owner is never granted here, so it is not advertised; an owner's
        // membership is still reported, as not editable.
        return ['controls' => [
            'application_admin' => false,
            'workspace_roles' => [
                ['id' => StaffRole::Admin->value, 'label' => 'Admin', 'description' => 'Reaches every client and project, and manages staff and portal access. Only an owner may grant it.'],
                ['id' => StaffRole::Member->value, 'label' => 'Member', 'description' => 'Reaches only the projects they are assigned to.'],
            ],
            'provisioning' => true,
        ]];
    }

    /**
     * @param  array<int, ManagedWorkspace>  $reach
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function workspacesPage(string $actorSubject, array $reach, array $payload): array
    {
        $after = $this->cursors->after($actorSubject, 'workspaces', $payload);
        $limit = (int) ($payload['limit'] ?? self::PAGE_SIZE);
        $query = self::query($payload);
        // A search filters the managed workspaces and nothing else.
        $remaining = array_values(array_filter($reach, static fn (ManagedWorkspace $managed): bool => $managed->workspace->id > $after
            && ($query === null || mb_stripos($managed->workspace->name, $query) !== false)));
        $page = array_slice($remaining, 0, $limit);
        $last = end($page);

        return [
            'workspaces' => array_map(static fn (ManagedWorkspace $managed): array => [
                'id' => $managed->workspace->public_id,
                'label' => Str::limit($managed->workspace->name, 250, ''),
            ], $page),
            'next_cursor' => count($remaining) > $limit && $last instanceof ManagedWorkspace ? $this->cursors->encode($actorSubject, 'workspaces', $last->workspace->id, $query) : null,
        ];
    }

    /**
     * People bound to this provider with staff access in a workspace the actor
     * manages, optionally those whose name or address contains the query.
     *
     * @param  array<int, ManagedWorkspace>  $reach
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function subjectsPage(string $actorSubject, array $reach, array $payload): array
    {
        $after = $this->cursors->after($actorSubject, 'subjects', $payload);
        $limit = (int) ($payload['limit'] ?? self::PAGE_SIZE);
        $query = self::query($payload);

        $users = $this->boundUsers()
            ->whereIn('id', WorkspaceMembership::query()->select('user_id')->whereIn('workspace_id', array_keys($reach)))
            // Narrows the scoped listing, never replaces it. Lowered on both
            // sides so the match ignores case whatever the collation.
            ->when($query !== null, static function (Builder $users) use ($query): void {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower((string) $query)).'%';
                $users->where(static fn (Builder $match): Builder => $match
                    ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$pattern]));
            })
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $page = $users->take($limit);

        return [
            'subjects' => $page->map(static fn (User $user): array => [
                'subject' => (string) $user->oauth_subject,
                'label' => Str::limit($user->name, 250, '') ?: (string) $user->oauth_subject,
                ...self::observations($user),
            ])->values()->all(),
            'next_cursor' => $users->count() > $limit ? $this->cursors->encode($actorSubject, 'subjects', (int) $page->last()?->id, $query) : null,
        ];
    }

    /**
     * The target's staff access as the actor may see it, read from one snapshot.
     *
     * @param  array<int, ManagedWorkspace>  $reach
     * @return array<string, mixed>
     */
    private function state(User $actor, array $reach, string $subject): array
    {
        $target = $this->boundUser($subject);

        if (! $target instanceof User) {
            return [
                'subject' => $subject,
                'provisioned' => false,
                'revision' => null,
                'access' => null,
                'allowed_edits' => ['application_admin' => false, 'workspaces' => false, 'provision' => true, 'remove' => false],
            ];
        }

        $view = DB::transaction(fn (): StaffView => $this->view($actor, $target, $reach, lock: false));

        return [
            'subject' => $subject,
            'provisioned' => true,
            ...self::observations($target),
            'revision' => $view->revision(),
            'access' => [
                'application_admin' => false,
                'workspaces' => array_map(fn (WorkspaceMembership $membership): array => [
                    'id' => $reach[(int) $membership->workspace_id]->workspace->public_id,
                    'role' => $membership->role,
                    'editable' => $view->editable($membership),
                    ...self::effects($view, $membership),
                ], $view->memberships),
                'removal_notice' => self::REMOVAL_NOTICE,
            ],
            'allowed_edits' => [
                'application_admin' => false,
                'workspaces' => true,
                'provision' => false,
                // Exactly when remove() goes through: everything shown may be
                // removed, or there is nothing to remove.
                'remove' => $view->allEditable(),
            ],
        ];
    }

    /**
     * @param  array<int, ManagedWorkspace>  $reach
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws DelegatedAccessException
     */
    private function update(User $actor, array $reach, array $payload): array
    {
        $subject = (string) $payload['subject'];
        $access = (array) $payload['access'];

        // No application-wide administrator here, as capabilities say.
        if (($access['application_admin'] ?? null) !== false) {
            throw self::refusal(DelegatedRefusal::INVALID_REQUEST);
        }

        $byPublicId = [];
        foreach ($reach as $managed) {
            $byPublicId[$managed->workspace->public_id] = $managed;
        }

        /** @var array<int, string> $desired workspace id => role, as asked */
        $desired = [];
        foreach ((array) $access['workspaces'] as $entry) {
            $managed = $byPublicId[$entry['id']] ?? throw self::refusal(DelegatedRefusal::NOT_AUTHORIZED);
            $desired[$managed->workspace->id] = (string) $entry['role'];
        }
        ksort($desired);

        if ($payload['expected_revision'] === null) {
            $this->provision($actor, $reach, $subject, $desired, $payload['display_name'] ?? null);
        } else {
            $target = $this->boundUser($subject) ?? throw self::refusal(DelegatedRefusal::NOT_PROVISIONED);
            $this->write($actor, $target, $reach, (string) $payload['expected_revision'], $desired);
        }

        return $this->state($actor, $reach, $subject);
    }

    /**
     * @param  array<int, ManagedWorkspace>  $reach
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws DelegatedAccessException
     */
    private function remove(User $actor, array $reach, array $payload): array
    {
        $subject = (string) $payload['subject'];
        $target = $this->boundUser($subject) ?? throw self::refusal(DelegatedRefusal::NOT_PROVISIONED);

        $this->write($actor, $target, $reach, (string) $payload['expected_revision'], null);

        return $this->state($actor, $reach, $subject);
    }

    /**
     * Compare the revision under the workspace access locks, decide every change
     * against the locked rows, then make them through {@see WorkspaceAccess}, all
     * in one transaction: a refusal anywhere leaves nothing changed.
     *
     * @param  array<int, ManagedWorkspace>  $reach
     * @param  array<int, string>|null  $desired  workspace id => role; null removes everything shown
     *
     * @throws DelegatedAccessException
     */
    private function write(User $actor, User $target, array $reach, string $expectedRevision, ?array $desired): void
    {
        $this->transaction(function () use ($actor, $target, $reach, $expectedRevision, $desired): void {
            $view = $this->lockedView($actor, $target, $reach);

            if (! hash_equals($view->revision(), $expectedRevision)) {
                throw self::refusal(DelegatedRefusal::REVISION_CONFLICT);
            }

            $current = [];
            foreach ($view->memberships as $membership) {
                $current[(int) $membership->workspace_id] = $membership;
            }
            $desired ??= [];

            // Every decision before any write.
            $removals = $changes = $grants = [];
            foreach ($current as $workspaceId => $membership) {
                if (! array_key_exists($workspaceId, $desired)) {
                    $view->editable($membership) || throw self::refusal(DelegatedRefusal::PROTECTED_MEMBERSHIP);
                    $removals[] = $workspaceId;
                } elseif ($desired[$workspaceId] !== $membership->role) {
                    $view->editable($membership) || throw self::refusal(DelegatedRefusal::PROTECTED_MEMBERSHIP);
                    $changes[$workspaceId] = $this->grantable($view, $workspaceId, $desired[$workspaceId]);
                }
            }
            foreach ($desired as $workspaceId => $role) {
                if (! isset($current[$workspaceId])) {
                    $grants[$workspaceId] = $this->grantable($view, $workspaceId, $role);
                }
            }

            $context = $this->context($actor);
            foreach ($removals as $workspaceId) {
                $this->access->removeStaff($context, $reach[$workspaceId]->workspace, $target);
            }
            foreach ($changes as $workspaceId => $role) {
                $this->access->changeStaffRole($context, $reach[$workspaceId]->workspace, $target, $role);
            }
            foreach ($grants as $workspaceId => $role) {
                $this->access->grantStaff($context, $reach[$workspaceId]->workspace, $target, $role);
            }
        });
    }

    /**
     * Create the account for a subject nobody has bound yet, and grant it the
     * memberships asked for.
     *
     * @param  array<int, ManagedWorkspace>  $reach
     * @param  array<int, string>  $desired
     *
     * @throws DelegatedAccessException
     */
    private function provision(User $actor, array $reach, string $subject, array $desired, mixed $displayName): void
    {
        // Provisioning creates staff, so it grants at least one membership.
        if ($desired === []) {
            throw self::refusal(DelegatedRefusal::INVALID_REQUEST);
        }

        $issuer = $this->settings->bindingIssuer();

        try {
            $this->transaction(function () use ($actor, $reach, $subject, $desired, $displayName, $issuer): void {
                $workspaces = $this->lockWorkspaces(array_keys($desired));
                $roles = $this->actorRoles($actor, array_keys($desired), lock: true);
                foreach ($desired as $workspaceId => $role) {
                    $this->roleGrantable($roles[$workspaceId] ?? null, $role) || throw self::refusal(
                        isset($roles[$workspaceId]) && $roles[$workspaceId]->managesWorkspace() ? DelegatedRefusal::ROLE_NOT_GRANTABLE : DelegatedRefusal::NOT_AUTHORIZED,
                    );
                }

                // Bound since the read: the provider's view is stale, and a null
                // revision never overwrites what exists.
                if ($this->boundUsers()->where('oauth_subject', $subject)->exists()) {
                    throw self::refusal(DelegatedRefusal::REVISION_CONFLICT);
                }

                // A row holding the derived address with no binding is an orphan;
                // adopting it would be linking an account by address.
                $email = PendingAccount::email($issuer, $subject);
                if (User::query()->where('email', $email)->exists()) {
                    throw self::refusal(DelegatedRefusal::INVALID_REQUEST);
                }

                $user = User::query()->create([
                    'name' => is_string($displayName) && trim($displayName) !== '' ? trim($displayName) : PendingAccount::name('Pending staff member', $subject),
                    'email' => $email,
                    'password' => Hash::make(Str::random(64)),
                    'oauth_provider' => $issuer,
                    'oauth_subject' => $subject,
                ]);

                $context = $this->context($actor);
                foreach ($desired as $workspaceId => $role) {
                    $this->audit->record($context, $workspaces[$workspaceId], AccessAuditAction::StaffAccountProvisioned, $user, null, ['provider' => $issuer]);
                    $this->access->grantStaff($context, $reach[$workspaceId]->workspace, $user, StaffRole::from($role));
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Bound first by another provisioning request or the person's own
            // first sign-in: stale, as for an existing binding.
            throw self::refusal(DelegatedRefusal::REVISION_CONFLICT);
        }
    }

    /**
     * The target's staff access in the actor's reach, read under the workspace
     * access locks in `LockResource` order: the workspaces, the projects the
     * target is assigned to there, the actor's and target's memberships, then
     * the assignments. Every access mutation in these workspaces waits on the
     * first of those, so nothing the revision describes can change until this
     * transaction ends, and the locking reads see the latest committed rows.
     *
     * @param  array<int, ManagedWorkspace>  $reach
     */
    private function lockedView(User $actor, User $target, array $reach): StaffView
    {
        $workspaceIds = array_keys($reach);
        $this->lockWorkspaces($workspaceIds);
        ClientProject::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->whereIn('id', ClientProjectMembership::query()->select('client_project_id')->whereIn('workspace_id', $workspaceIds)->where('user_id', $target->id))
            ->orderBy('id')
            ->tap(Locks::forUpdate())
            ->get(['id']);

        return $this->view($actor, $target, $reach, lock: true);
    }

    /**
     * @param  array<int, ManagedWorkspace>  $reach  as read before any lock
     */
    private function view(User $actor, User $target, array $reach, bool $lock): StaffView
    {
        $workspaceIds = array_keys($reach);
        // Re-read with the rest, so a demotion since the request began is part
        // of what the revision describes.
        $roles = $this->actorRoles($actor, $workspaceIds, $lock);

        $memberships = array_values(WorkspaceMembership::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('user_id', $target->id)
            ->orderBy('workspace_id')
            ->when($lock, static fn (Builder $query): Builder => $query->tap(Locks::forUpdate()))
            ->get()
            ->all());

        $assignments = array_values(ClientProjectMembership::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('user_id', $target->id)
            ->orderBy('workspace_id')
            ->orderBy('client_project_id')
            ->when($lock, static fn (Builder $query): Builder => $query->tap(Locks::forUpdate()))
            ->get()
            ->all());

        return new StaffView(self::CASCADE_RULES, $actor->id, $target->id, $roles, $memberships, $assignments);
    }

    /**
     * Workspaces the actor owns or administers, keyed by id, in id order.
     *
     * @return array<int, ManagedWorkspace>
     */
    private function reach(User $actor): array
    {
        $roles = WorkspaceMembership::query()
            ->where('user_id', $actor->id)
            ->whereIn('role', [StaffRole::Owner->value, StaffRole::Admin->value])
            ->orderBy('workspace_id')
            ->pluck('role', 'workspace_id')
            ->all();
        $roles = array_combine(array_map(intval(...), array_keys($roles)), array_values($roles)) ?: [];

        $reach = [];
        foreach (Workspace::query()->whereIn('id', array_keys($roles))->orderBy('id')->get() as $workspace) {
            $reach[$workspace->id] = new ManagedWorkspace($workspace, StaffRole::from((string) $roles[$workspace->id]));
        }

        return $reach;
    }

    /**
     * @param  list<int>  $workspaceIds
     * @return array<int, StaffRole> workspace id => the actor's role, where they have one this boundary knows
     */
    private function actorRoles(User $actor, array $workspaceIds, bool $lock): array
    {
        $roles = [];
        foreach (WorkspaceMembership::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('user_id', $actor->id)
            ->orderBy('id')
            ->when($lock, static fn (Builder $query): Builder => $query->tap(Locks::forUpdate()))
            ->get() as $membership) {
            $role = StaffRole::tryFrom($membership->role);
            if ($role !== null) {
                $roles[(int) $membership->workspace_id] = $role;
            }
        }

        return $roles;
    }

    /**
     * @param  list<int>  $workspaceIds
     * @return array<int, Workspace> locked, keyed by id
     */
    private function lockWorkspaces(array $workspaceIds): array
    {
        $locked = [];
        foreach (Workspace::query()->whereIn('id', $workspaceIds)->orderBy('id')->tap(Locks::forUpdate())->get() as $workspace) {
            $locked[$workspace->id] = $workspace;
        }

        return $locked;
    }

    /**
     * The role an existing or new membership may be given by this actor, or the
     * refusal: a role nobody grants here, or one this actor may not.
     *
     * @throws DelegatedAccessException
     */
    private function grantable(StaffView $view, int $workspaceId, string $role): StaffRole
    {
        $actorRole = $view->actorRoles[$workspaceId] ?? null;

        if ($actorRole?->managesWorkspace() !== true) {
            throw self::refusal(DelegatedRefusal::NOT_AUTHORIZED);
        }
        if (! $this->roleGrantable($actorRole, $role)) {
            throw self::refusal(DelegatedRefusal::ROLE_NOT_GRANTABLE);
        }

        return StaffRole::from($role);
    }

    /** Owners grant `admin` and `member`; admins grant `member`; nobody grants `owner`. */
    private function roleGrantable(?StaffRole $actorRole, string $role): bool
    {
        return match (StaffRole::tryFrom($role)) {
            StaffRole::Member => $actorRole?->managesWorkspace() === true,
            StaffRole::Admin => $actorRole === StaffRole::Owner,
            StaffRole::Owner, null => false,
        };
    }

    /**
     * One transaction for a whole delegated write, with every refusal from the
     * access boundary mapped to the contract's outcomes. The checks before each
     * call make these unreachable; they still refuse, and nothing is kept.
     *
     * @param  \Closure(): void  $work
     *
     * @throws DelegatedAccessException
     * @throws UniqueConstraintViolationException when the work's insert loses a race for a unique key
     */
    private function transaction(\Closure $work): void
    {
        try {
            DB::transaction($work);
        } catch (AccessMutationRefused $refusal) {
            throw self::refusal(match ($refusal->reason) {
                AccessRefusal::NotAuthorized => DelegatedRefusal::NOT_AUTHORIZED,
                AccessRefusal::OwnerProtected, AccessRefusal::SelfChange, AccessRefusal::UnsupportedRole => DelegatedRefusal::PROTECTED_MEMBERSHIP,
                AccessRefusal::AlreadyStaff, AccessRefusal::NotStaff => DelegatedRefusal::REVISION_CONFLICT,
                AccessRefusal::AlreadyPortalMember, AccessRefusal::NotPortalMember, AccessRefusal::ProjectOutsideCompany, AccessRefusal::ProjectsWithCompanyScope => DelegatedRefusal::INVALID_REQUEST,
            });
        }
    }

    /**
     * The access boundary's context for this request: the actor, the write's
     * operation id (the same on every retry of one action) and the provider's
     * single-use request id, so the audit trail lines up with the provider's.
     */
    private function context(User $actor): AccessMutationContext
    {
        $request = $this->container->bound(DelegatedRequestContext::class) ? $this->container->make(DelegatedRequestContext::class) : null;

        return AccessMutationContext::user($actor, $request?->operationId, $request?->jti);
    }

    /**
     * What dropping this membership would also remove, when there is something
     * and the actor may drop it.
     *
     * @return array{removal_effects?: list<array{kind: string, label: string, count: int}>}
     */
    private static function effects(StaffView $view, WorkspaceMembership $membership): array
    {
        $count = $view->assignmentCount((int) $membership->workspace_id);

        return $count > 0 && $view->editable($membership)
            ? ['removal_effects' => [['kind' => 'project_assignments', 'label' => self::ASSIGNMENTS_LABEL, 'count' => $count]]]
            : [];
    }

    /** @return Builder<User> */
    private function boundUsers(): Builder
    {
        return User::query()->where('oauth_provider', $this->settings->bindingIssuer())->whereNotNull('oauth_subject');
    }

    private function boundUser(string $subject): ?User
    {
        return $this->boundUsers()->where('oauth_subject', $subject)->first();
    }

    /**
     * When the account was created - by sign-in or provisioning - as an
     * observation only, never part of the revision.
     *
     * @return array{provisioned_at: string|null}
     */
    private static function observations(User $user): array
    {
        return ['provisioned_at' => $user->created_at?->toIso8601ZuluString()];
    }

    /** @param array<string, mixed> $payload */
    private static function query(array $payload): ?string
    {
        return is_string($payload['query'] ?? null) ? $payload['query'] : null;
    }

    private static function refusal(string $outcome): DelegatedAccessException
    {
        return DelegatedRefusal::of($outcome);
    }
}
