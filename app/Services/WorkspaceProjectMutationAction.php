<?php

namespace App\Services;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\ProjectRole;
use App\Support\Concurrency\Locks;
use App\Support\RepositoryReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The workspace-manager project workflow shared by browser and API callers. */
final class WorkspaceProjectMutationAction
{
    public function __construct(private readonly ProjectAccess $access) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, Workspace $workspace, ClientCompany $company, array $attributes, ?string $expectedVersion = null): ClientProject
    {
        $this->requireManager($actor, $workspace);

        return DB::transaction(function () use ($workspace, $company, $attributes, $expectedVersion): ClientProject {
            $lockedCompany = ClientCompany::query()->where('workspace_id', $workspace->id)->whereKey($company->id)->tap(Locks::forUpdate())->firstOrFail();
            if ($expectedVersion !== null) {
                abort_unless(AgentApiVersion::matches($lockedCompany, $expectedVersion), 409, 'The client company has changed; read it and retry.');
            }

            return $lockedCompany->projects()->create([
                'workspace_id' => $workspace->id,
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'repository' => RepositoryReference::normalize($attributes['repository'] ?? null),
                'is_visible_to_client' => $attributes['is_visible_to_client'] ?? true,
            ]);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, Workspace $workspace, ClientProject $project, array $attributes, string|int $expectedVersion, ?int $companyId = null): ClientProject
    {
        $this->requireManager($actor, $workspace);

        return DB::transaction(function () use ($workspace, $project, $attributes, $expectedVersion, $companyId): ClientProject {
            $locked = $this->lockedProject($workspace, $project, $companyId);
            $this->checkVersion($locked, $expectedVersion);
            $changes = array_intersect_key($attributes, array_flip(['name', 'description', 'repository', 'status', 'is_visible_to_client']));
            if (array_key_exists('repository', $changes)) {
                $changes['repository'] = RepositoryReference::normalize($changes['repository']);
            }
            $locked->update($changes);

            return $locked;
        });
    }

    public function updateAccess(User $actor, Workspace $workspace, ClientProject $project, string $userId, string $role, string|int|null $expectedVersion = null, ?int $companyId = null): ClientProject
    {
        $this->requireManager($actor, $workspace);
        abort_unless($role === 'none' || ProjectRole::tryFrom($role) !== null, 422, 'Choose a supported project role or none.');

        return DB::transaction(function () use ($workspace, $project, $userId, $role, $expectedVersion, $companyId): ClientProject {
            $locked = $this->lockedProject($workspace, $project, $companyId);
            if ($expectedVersion !== null) {
                $this->checkVersion($locked, $expectedVersion);
            }
            $membership = WorkspaceMembership::query()->where('workspace_id', $workspace->id)
                ->whereHas('user', fn (Builder $users): Builder => $users->where('public_id', $userId))
                ->tap(Locks::forUpdate())->firstOrFail();
            abort_if(in_array($membership->role, ['owner', 'admin'], true), 422, 'Workspace owners and admins already have workspace-wide access.');
            $existing = ClientProjectMembership::query()->where('workspace_id', $workspace->id)->where('client_project_id', $locked->id)->where('user_id', $membership->user_id);
            if ($role === 'none') {
                $existing->delete();
            } else {
                $existing->updateOrCreate(['workspace_id' => $workspace->id, 'client_project_id' => $locked->id, 'user_id' => $membership->user_id], ['role' => $role]);
            }
            // Access is part of the project's revision. A concurrent grant or
            // removal must invalidate a previously read access decision.
            $locked->advanceAgentRevision();

            return $locked;
        });
    }

    public function requireManager(User $actor, Workspace $workspace): void
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 403);
    }

    private function lockedProject(Workspace $workspace, ClientProject $project, ?int $companyId): ClientProject
    {
        return ClientProject::query()->where('workspace_id', $workspace->id)->whereKey($project->id)
            ->when($companyId !== null, fn (Builder $query): Builder => $query->where('client_company_id', $companyId))
            ->whereHas('clientCompany', fn (Builder $company): Builder => $company->where('workspace_id', $workspace->id))
            ->tap(Locks::forUpdate())->firstOrFail();
    }

    private function checkVersion(ClientProject $project, string|int $expectedVersion): void
    {
        if (is_int($expectedVersion)) {
            if ($project->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages(['lock_version' => 'This project changed while you were editing it. Reload to see the current values before saving.']);
            }

            return;
        }
        abort_unless(AgentApiVersion::matches($project, $expectedVersion), 409, 'The project has changed; read it and retry.');
    }
}
