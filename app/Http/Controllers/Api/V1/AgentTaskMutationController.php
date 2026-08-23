<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AgentTaskMutationController extends Controller
{
    public function store(Request $request, Workspace $workspace, string $project, ProjectAccess $access): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'], 'is_visible_to_client' => ['sometimes', 'boolean']]);
        $user = $this->user($request);
        $project = ClientProject::query()->where('workspace_id', $workspace->id)->where('public_id', $project)->firstOrFail();
        abort_unless($access->canManageTasks($user, $project), 403);
        $task = $project->tasks()->create(['workspace_id' => $workspace->id] + $data + ['is_visible_to_client' => $data['is_visible_to_client'] ?? false]);

        return response()->json(['data' => $this->payload($workspace, $task)], 201);
    }

    public function update(Request $request, Workspace $workspace, string $task, ProjectAccess $access): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'string', 'size:64'], 'title' => ['sometimes', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'], 'status' => ['sometimes', 'string', 'in:open,in_progress,completed'], 'is_visible_to_client' => ['sometimes', 'boolean']]);
        $record = ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $task)->with('project')->firstOrFail();
        abort_unless($access->canManageTasks($this->user($request), $record->project), 403);
        $attributes = array_filter($data, static fn (mixed $value): bool => $value !== null);
        if (($attributes['status'] ?? null) === 'completed') {
            $attributes['completed_at'] = now();
        } elseif (array_key_exists('status', $attributes)) {
            $attributes['completed_at'] = null;
        }
        unset($attributes['expected_version']);
        abort_unless(AgentApiVersion::matches($record, $data['expected_version']), 409, 'The task has changed; read it and retry.');
        $updated = ClientTask::query()->whereKey($record->id)->where('lock_version', $record->lock_version)->update($attributes + ['lock_version' => DB::raw('lock_version + 1')]);
        abort_unless($updated === 1, 409, 'The task has changed; read it and retry.');

        return response()->json(['data' => $this->payload($workspace, $record->fresh() ?? throw new \RuntimeException('The task no longer exists.'))]);
    }

    private function user(Request $request): User
    {
        $principal = $request->user();
        abort_unless($principal instanceof AgentPrincipal, 401);

        return User::query()->findOrFail($principal->id);
    }

    /** @return array<string, mixed> */
    private function payload(Workspace $workspace, ClientTask $task): array
    {
        return ['id' => $task->public_id, 'project_id' => $task->project->public_id, 'title' => $task->title, 'description' => $task->description, 'status' => $task->status, 'is_visible_to_client' => $task->is_visible_to_client, 'completed_at' => $task->completed_at?->toAtomString(), 'version' => AgentApiVersion::for($task), 'web_url' => route('workspaces.operations', $workspace).'?task='.$task->public_id];
    }
}
