<?php

namespace App\Services\Access;

use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\StaffRole;
use App\Support\Concurrency\Locks;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The transaction and lock discipline every workspace access mutation shares.
 *
 * ## The workspace access lock
 *
 * Every access mutation - staff, portal or project assignment - opens one
 * transaction and first locks the workspace row. That serialises access
 * changes within a workspace against each other, so a decision read under
 * the lock cannot be invalidated by another access change until this one
 * commits. It is the same row invoice numbering serialises on; both hold it
 * for one short transaction.
 *
 * ## Authority is re-read under the lock
 *
 * A caller usually checks authority before calling in, as a fast refusal. That
 * read is only advisory: the actor may be demoted or removed between it and
 * the write. So each mutation re-reads the actor's staff membership under the
 * workspace lock, with a locking read so it sees the latest committed role
 * even inside an enclosing transaction whose snapshot predates the lock, and
 * decides again from that.
 *
 * ## Finer locks
 *
 * After the workspace, in `LockResource` order: projects whose revision the
 * change advances (in id order), then staff memberships, then the assignment
 * or portal rows written. Authority is therefore re-checked after any project
 * locks and before every write.
 */
final class AccessCoordinator
{
    /**
     * Run access work in one transaction that holds the workspace access lock.
     *
     * @template TResult
     *
     * @param  Closure(Workspace): TResult  $work  receives the locked workspace row
     * @return TResult
     */
    public function locked(Workspace $workspace, Closure $work): mixed
    {
        return DB::transaction(function () use ($workspace, $work): mixed {
            $locked = Workspace::query()->whereKey($workspace->id)->tap(Locks::forUpdate())->firstOrFail();

            return $work($locked);
        });
    }

    /**
     * These people's staff memberships in this workspace, keyed by user id.
     *
     * Read with a lock under the workspace access lock, and without one for a
     * fast refusal before it. Null ids - the system actor - are skipped.
     *
     * @param  list<int|null>  $userIds
     * @return array<int, WorkspaceMembership>
     */
    public function staff(Workspace $workspace, array $userIds, bool $lock): array
    {
        $ids = array_values(array_unique(array_filter($userIds, static fn (?int $id): bool => $id !== null)));

        $memberships = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('user_id', $ids)
            ->orderBy('id')
            ->when($lock, static fn (Builder $query): Builder => $query->tap(Locks::forUpdate()))
            ->get();

        $keyed = [];
        foreach ($memberships as $membership) {
            $keyed[$membership->user_id] = $membership;
        }

        return $keyed;
    }

    /**
     * The actor's staff role among memberships read by {@see staff()}, or null
     * for the system, a non-member, or a role this boundary does not know.
     *
     * @param  array<int, WorkspaceMembership>  $staff
     */
    public function actorRole(AccessMutationContext $context, array $staff): ?StaffRole
    {
        $actorId = $context->actorId();

        return $actorId === null || ! isset($staff[$actorId]) ? null : StaffRole::tryFrom($staff[$actorId]->role);
    }

    /**
     * The actor's current staff role, read under the workspace access lock.
     */
    public function currentRole(Workspace $workspace, User $actor): ?StaffRole
    {
        return $this->actorRole(AccessMutationContext::user($actor), $this->staff($workspace, [$actor->id], lock: true));
    }

    /**
     * Lock these projects in id order, keyed by id.
     *
     * @param  list<int>  $projectIds
     * @return Collection<int, ClientProject>
     */
    public function lockProjects(Workspace $workspace, array $projectIds): Collection
    {
        if ($projectIds === []) {
            return new Collection;
        }

        return ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $projectIds)
            ->orderBy('id')
            ->tap(Locks::forUpdate())
            ->get()
            ->keyBy('id');
    }
}
