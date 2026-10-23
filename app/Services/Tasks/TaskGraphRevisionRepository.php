<?php

namespace App\Services\Tasks;

use App\Models\ClientProject;
use App\Models\ClientTaskGraphState;
use App\Models\Workspace;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Builder;

/** Scoped project revision mutex. Callers acquire Workspace-exclusive first. */
final class TaskGraphRevisionRepository
{
    public function __construct(private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    public function read(Workspace $workspace, ClientProject $project, bool $current = false): ClientTaskGraphState
    {
        $query = $this->query($workspace, $project);
        if ($current) {
            $query->tap(Locks::forUpdate());
        }

        return $query->first() ?? new ClientTaskGraphState(['workspace_id' => $workspace->id, 'client_project_id' => $project->id])
            ->forceFill(['lock_version' => 0]);
    }

    public function lockForMutation(Workspace $workspace, ClientProject $project): ClientTaskGraphState
    {
        $query = $this->query($workspace, $project)->tap(Locks::forUpdate());
        if ($query->first() === null) {
            // createOrFirst's duplicate fallback uses the current locking query,
            // including when another connection initialized after an old snapshot.
            $query->createOrFirst(['workspace_id' => $workspace->id, 'client_project_id' => $project->id]);
        }

        return $query->firstOrFail();
    }

    public function advance(Workspace $workspace, ClientProject $project, ClientTaskGraphState $state): void
    {
        abort_unless($state->exists && $state->workspace_id === $workspace->id && $state->client_project_id === $project->id, 404);
        $next = $state->lock_version + 1;
        $updatedAt = $this->clock->now($workspace)->utc();
        $changed = $this->query($workspace, $project)->whereKey($state->id)->where('lock_version', $state->lock_version)
            ->update(['lock_version' => $next, 'updated_at' => $updatedAt]);
        abort_unless($changed === 1, 409, 'The task graph revision changed. Read it again before saving.');
        $state->forceFill(['lock_version' => $next, 'updated_at' => $updatedAt])->syncOriginal();
    }

    /** @return Builder<ClientTaskGraphState> */
    private function query(Workspace $workspace, ClientProject $project): Builder
    {
        abort_unless($workspace->exists && $project->exists && $project->workspace_id === $workspace->id, 404);

        return ClientTaskGraphState::query()->where('workspace_id', $workspace->id)->where('client_project_id', $project->id);
    }
}
