<?php

namespace App\Services\Tasks;

use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\ClientTaskAssignment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\Concurrency\Locks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\ValidationException;

/** All task planning persistence is explicitly scoped to one workspace. */
final class WorkspaceTaskRepository
{
    public function lockWorkspace(Workspace $workspace): Workspace
    {
        // AccessCoordinator uses this same row for grant/revocation mutations.
        return Workspace::query()->whereKey($workspace->id)->tap(Locks::forUpdate())->firstOrFail();
    }

    public function project(Workspace $workspace, string $publicId): ClientProject
    {
        return ClientProject::query()->where('workspace_id', $workspace->id)->where('public_id', $publicId)
            ->whereHas('clientCompany', fn (Builder $company): Builder => $company->where('workspace_id', $workspace->id))
            ->firstOrFail()->setRelation('workspace', $workspace);
    }

    public function find(Workspace $workspace, ?string $publicId, bool $lock = false): ClientTask
    {
        $query = $this->query($workspace)->where('public_id', $publicId);
        $query->with(['assignments' => fn ($assignments) => $assignments->where('workspace_id', $workspace->id)
            ->when($lock, fn ($rows) => $rows->tap(Locks::forShare()))->with('user')->orderBy('id')]);
        if ($lock) {
            $query->tap(Locks::forUpdate());
        }

        return $query->firstOrFail();
    }

    public function create(Workspace $workspace, ClientProject $project, TaskCreateData $data, TaskAccessFacts $access): ClientTask
    {
        $task = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id] + $data->attributes());
        $task->setRelation('project', $project)->setRelation('assignments', new Collection);
        $this->assign($workspace, $task, $data->assigneeIds, $access);

        return $this->find($workspace, $task->public_id);
    }

    /** @param array<string, mixed> $attributes */
    public function save(ClientTask $task, array $attributes, bool $advanceNoop): void
    {
        $task->forceFill($attributes);
        if ($advanceNoop && ! $task->isDirty()) {
            $task->forceFill(['lock_version' => $task->lock_version + 1]);
        }
        $task->save();
    }

    /** Replace responsibility without granting access; unchanged former members may remain recorded.
     * @param  list<string>  $publicIds
     */
    public function assign(Workspace $workspace, ClientTask $task, array $publicIds, TaskAccessFacts $access): bool
    {
        $current = $task->assignments;
        $existing = [];
        foreach ($current as $assignment) {
            $existing[$assignment->user->public_id] = $assignment->user_id;
        }
        $added = array_values(array_diff($publicIds, array_keys($existing)));
        $ids = $existing;
        foreach ($added as $publicId) {
            if (! $access->canAssign($publicId)) {
                throw ValidationException::withMessages(['assignee_ids' => 'Choose current staff with access to this project.']);
            }
            $ids[$publicId] = $access->accounts[$publicId];
        }
        $desired = array_map(fn (string $id): int => $ids[$id], $publicIds);
        $changed = array_diff($publicIds, array_keys($existing)) !== [] || array_diff(array_keys($existing), $publicIds) !== [];
        if ($changed) {
            ClientTaskAssignment::query()->where('workspace_id', $workspace->id)->where('client_task_id', $task->id)
                ->whereNotIn('user_id', $desired)->delete();
            foreach ($added as $publicId) {
                ClientTaskAssignment::query()->create(['workspace_id' => $workspace->id, 'client_task_id' => $task->id, 'user_id' => $ids[$publicId]]);
            }
        }

        return $changed;
    }

    /** Current reads are required even inside an outer repeatable-read snapshot.
     * @param  list<string>  $candidateIds
     */
    public function access(Workspace $workspace, ClientProject $project, User $actor, array $candidateIds): TaskAccessFacts
    {
        $users = User::query()->whereIn('public_id', $candidateIds)->get(['id', 'public_id']);
        $accounts = [];
        foreach ($users as $user) {
            $accounts[$user->public_id] = $user->id;
        }
        $userIds = array_values(array_unique([$actor->id, ...array_values($accounts)]));
        $staff = WorkspaceMembership::query()->where('workspace_id', $workspace->id)->whereIn('user_id', $userIds)
            ->orderBy('id')->tap(Locks::forShare())->get();
        $grants = ClientProjectMembership::query()->where('workspace_id', $workspace->id)->where('client_project_id', $project->id)
            ->whereIn('user_id', $userIds)->orderBy('id')->tap(Locks::forShare())->get();
        $staffRoles = [];
        foreach ($staff as $membership) {
            $staffRoles[$membership->user_id] = $membership->role;
        }
        $projectRoles = [];
        foreach ($grants as $membership) {
            $projectRoles[$membership->user_id] = $membership->role;
        }

        return new TaskAccessFacts($accounts, $staffRoles, $projectRoles);
    }

    /** @return array{data: list<array{id: string, name: string}>, has_more: bool} */
    public function assigneeOptions(Workspace $workspace, ClientProject $project, string $search = ''): array
    {
        $users = $this->eligibleUsers($workspace, $project->id)
            ->when($search !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')->limit(26)->get(['users.id', 'users.public_id', 'users.name']);

        return ['data' => array_values($users->take(25)->map(fn (User $user): array => ['id' => $user->public_id, 'name' => $user->name])->all()),
            'has_more' => $users->count() > 25];
    }

    /** @return Builder<User> */
    private function eligibleUsers(Workspace $workspace, int $projectId): Builder
    {
        return User::query()->whereExists(function (QueryBuilder $memberships) use ($workspace, $projectId): void {
            $memberships->selectRaw('1')->from('workspace_memberships')->where('workspace_id', $workspace->id)
                ->whereColumn('user_id', 'users.id')->where(function (QueryBuilder $roles) use ($workspace, $projectId): void {
                    $roles->whereIn('role', ['owner', 'admin'])->orWhereExists(function (QueryBuilder $projects) use ($workspace, $projectId): void {
                        $projects->selectRaw('1')->from('client_project_memberships')->where('workspace_id', $workspace->id)
                            ->where('client_project_id', $projectId)->whereColumn('user_id', 'users.id');
                    });
                });
        });
    }

    /** @return Builder<ClientTask> */
    private function query(Workspace $workspace): Builder
    {
        return ClientTask::query()->where('workspace_id', $workspace->id)
            ->whereHas('project', fn (Builder $project): Builder => $project->where('workspace_id', $workspace->id)
                ->whereHas('clientCompany', fn (Builder $company): Builder => $company->where('workspace_id', $workspace->id)))
            ->with(['project' => fn ($project) => $project->where('workspace_id', $workspace->id),
                'assignments' => fn ($assignments) => $assignments->where('workspace_id', $workspace->id)->with('user')->orderBy('id')]);
    }
}
