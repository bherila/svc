<?php

namespace App\Services\AgentApi;

use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Support\Facades\DB;

final class TimeEntryMutationService
{
    public function __construct(private readonly ProjectAccess $access) {}

    /** @param array<string, mixed> $data */
    public function create(Workspace $workspace, ClientProject $project, User $actor, array $data): ClientTimeEntry
    {
        abort_unless($this->access->canView($actor, $project), 404);
        abort_unless($this->access->isWorkspaceManager($actor, $workspace) || $project->members()->whereKey($actor->id)->exists(), 403);
        $task = null;
        if (is_string($data['task_id'] ?? null)) {
            $task = ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $data['task_id'])->firstOrFail();
            abort_unless($task->client_project_id === $project->id, 422, 'The task must belong to the selected project.');
        }

        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id,
            'client_project_id' => $project->id, 'client_task_id' => $task?->id, 'user_id' => $actor->id,
            'worked_on' => $data['worked_on'], 'minutes' => $data['minutes'], 'description' => $data['description'],
            'client_visible_description' => $data['client_visible_description'] ?? null,
            'is_visible_to_client' => $data['is_visible_to_client'] ?? false,
            'is_billable' => $data['is_billable'] ?? true, 'is_deferred' => $data['is_deferred'] ?? false,
            'currency' => isset($data['currency']) ? strtoupper((string) $data['currency']) : $workspace->default_currency,
            'status' => 'draft',
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(Workspace $workspace, ClientTimeEntry $entry, User $actor, array $data): ClientTimeEntry
    {
        $this->assertDraftEditable($workspace, $entry, $actor);
        $attributes = array_filter([
            'worked_on' => $data['worked_on'] ?? null, 'minutes' => $data['minutes'] ?? null, 'description' => $data['description'] ?? null,
            'is_billable' => $data['is_billable'] ?? null, 'is_deferred' => $data['is_deferred'] ?? null,
            'is_visible_to_client' => $data['is_visible_to_client'] ?? null, 'client_visible_description' => $data['client_visible_description'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
        abort_unless(AgentApiVersion::matches($entry, $data['expected_version']), 409, 'The time entry has changed; read it and retry.');
        $updated = ClientTimeEntry::query()->whereKey($entry->id)->where('lock_version', $entry->lock_version)->update($attributes + ['lock_version' => DB::raw('lock_version + 1')]);
        abort_unless($updated === 1, 409, 'The time entry has changed; read it and retry.');

        return $entry->fresh() ?? throw new \RuntimeException('The time entry no longer exists.');
    }

    public function delete(Workspace $workspace, ClientTimeEntry $entry, User $actor, string $expectedVersion): void
    {
        $this->assertDraftEditable($workspace, $entry, $actor);
        abort_unless(AgentApiVersion::matches($entry, $expectedVersion), 409, 'The time entry has changed; read it and retry.');
        $updated = ClientTimeEntry::query()->whereKey($entry->id)->where('lock_version', $entry->lock_version)->update(['lock_version' => DB::raw('lock_version + 1'), 'deleted_at' => now()]);
        abort_unless($updated === 1, 409, 'The time entry has changed; read it and retry.');
    }

    /** @param list<array{id: string, expected_version: string}> $entries */
    public function approve(Workspace $workspace, User $actor, array $entries): void
    {
        DB::transaction(function () use ($workspace, $actor, $entries): void {
            foreach ($entries as $item) {
                $entry = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('public_id', $item['id'])->lockForUpdate()->firstOrFail();
                abort_unless($this->access->canApproveTime($actor, $entry->project), 403);
                abort_unless($entry->status === 'draft', 409, 'Only draft time entries can be approved.');
                abort_unless(AgentApiVersion::matches($entry, $item['expected_version']), 409, 'The time entry has changed; read it and retry.');
                $entry->forceFill(['status' => 'approved', 'approved_by_user_id' => $actor->id, 'approved_at' => now(), 'lock_version' => $entry->lock_version + 1])->save();
            }
        });
    }

    private function assertDraftEditable(Workspace $workspace, ClientTimeEntry $entry, User $actor): void
    {
        abort_unless($entry->workspace_id === $workspace->id, 404);
        abort_unless($entry->status === 'draft', 409, 'Only draft time entries can be changed.');
        abort_unless($entry->user_id === $actor->id || $this->access->isWorkspaceManager($actor, $workspace), 403);
        abort_unless($this->access->canView($actor, $entry->project), 404);
    }
}
