<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Services\AgentApi\TimeEntryMutationService;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\Presenters\AgentTimeEntryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentTimeEntryMutationController extends Controller
{
    public function store(
        Request $request,
        Workspace $workspace,
        TimeEntryMutationService $time,
        AgentTimeEntryPresenter $presenter,
        ProjectAccess $access,
        AgentMutationContextFactory $contexts,
        AgentMutationExecutor $mutations,
    ): JsonResponse {
        $context = $contexts->from($request);
        $ids = $mutations->run(
            $context->user,
            $workspace,
            $context->oauthClientId,
            'time_entries.log',
            $context->idempotencyKey,
            $request->all(),
            function () use ($request, $workspace, $context, $time): array {
                $data = $request->validate(['entries' => ['required', 'array', 'min:1', 'max:20'], 'entries.*.project_id' => ['required', 'uuid'], 'entries.*.task_id' => ['nullable', 'uuid'], 'entries.*.worked_on' => ['required', 'date_format:Y-m-d'], 'entries.*.minutes' => ['required', 'integer', 'min:1', 'max:1440'], 'entries.*.description' => ['required', 'string', 'max:10000'], 'entries.*.is_billable' => ['sometimes', 'boolean'], 'entries.*.is_deferred' => ['sometimes', 'boolean'], 'entries.*.is_visible_to_client' => ['sometimes', 'boolean'], 'entries.*.client_visible_description' => ['nullable', 'string', 'max:10000'], 'entries.*.currency' => ['nullable', 'string', 'size:3']]);
                $ids = [];
                foreach ($data['entries'] as $entry) {
                    $project = ClientProject::query()->where('workspace_id', $workspace->id)->where('public_id', $entry['project_id'])->firstOrFail();
                    $ids[] = $time->create($workspace, $project, $context->user, $entry)->public_id;
                }

                return $ids;
            },
            fn (array $ids) => $this->guardEditableTime($workspace, $context->user, $ids, $access),
        );
        $entriesById = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)->with('project')->get()->keyBy('public_id');
        $entries = collect($ids)->map(function (string $id) use ($entriesById): ClientTimeEntry {
            $entry = $entriesById->get($id);
            abort_unless($entry instanceof ClientTimeEntry, 404);

            return $entry;
        });

        return response()->json(['data' => $entries->map(fn (ClientTimeEntry $entry): array => $presenter->present($workspace, $entry))->values()], 201);
    }

    public function update(
        Request $request,
        Workspace $workspace,
        string $entry,
        TimeEntryMutationService $time,
        AgentTimeEntryPresenter $presenter,
        ProjectAccess $access,
        AgentMutationContextFactory $contexts,
        AgentMutationExecutor $mutations,
    ): JsonResponse {
        $context = $contexts->from($request);
        $ids = $mutations->run(
            $context->user,
            $workspace,
            $context->oauthClientId,
            'time_entries.update',
            $context->idempotencyKey,
            ['entry_id' => $entry, 'body' => $request->all()],
            function () use ($request, $workspace, $entry, $time, $context): array {
                $data = $request->validate(['expected_version' => ['required', 'string', 'size:64'], 'worked_on' => ['sometimes', 'date_format:Y-m-d'], 'minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'], 'description' => ['sometimes', 'string', 'max:10000'], 'is_billable' => ['sometimes', 'boolean'], 'is_deferred' => ['sometimes', 'boolean'], 'is_visible_to_client' => ['sometimes', 'boolean'], 'client_visible_description' => ['nullable', 'string', 'max:10000']]);
                $record = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('public_id', $entry)->firstOrFail();
                $record = $time->update($workspace, $record, $context->user, $data);

                return [$record->public_id];
            },
            fn (array $ids) => $this->guardEditableTime($workspace, $context->user, $ids, $access),
        );
        $record = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('public_id', $ids[0] ?? null)->firstOrFail();

        return response()->json(['data' => $presenter->present($workspace, $record)]);
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        string $entry,
        TimeEntryMutationService $time,
        ProjectAccess $access,
        AgentMutationContextFactory $contexts,
        AgentMutationExecutor $mutations,
    ): JsonResponse {
        $context = $contexts->from($request);
        $ids = $mutations->run(
            $context->user,
            $workspace,
            $context->oauthClientId,
            'time_entries.delete',
            $context->idempotencyKey,
            ['entry_id' => $entry, 'body' => $request->all()],
            function () use ($request, $workspace, $entry, $time, $context): array {
                $data = $request->validate(['expected_version' => ['required', 'string', 'size:64']]);
                $record = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('public_id', $entry)->firstOrFail();
                $time->delete($workspace, $record, $context->user, $data['expected_version']);

                return [$record->public_id];
            },
            fn (array $ids) => $this->guardEditableTime($workspace, $context->user, $ids, $access, true),
        );

        return response()->json(['data' => ['deleted_id' => $ids[0]]]);
    }

    public function approve(
        Request $request,
        Workspace $workspace,
        TimeEntryMutationService $time,
        ProjectAccess $access,
        AgentMutationContextFactory $contexts,
        AgentMutationExecutor $mutations,
    ): JsonResponse {
        $context = $contexts->from($request);
        $ids = $mutations->run(
            $context->user,
            $workspace,
            $context->oauthClientId,
            'time_entries.approve',
            $context->idempotencyKey,
            $request->all(),
            function () use ($request, $workspace, $time, $context): array {
                $data = $request->validate(['entries' => ['required', 'array', 'min:1', 'max:100'], 'entries.*.id' => ['required', 'uuid', 'distinct'], 'entries.*.expected_version' => ['required', 'string', 'size:64']]);
                $time->approve($workspace, $context->user, $data['entries']);

                return array_column($data['entries'], 'id');
            },
            function (array $ids) use ($workspace, $access, $context): void {
                $entries = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)->with('project')->get();
                abort_unless($entries->count() === count($ids), 404);
                foreach ($entries as $record) {
                    abort_unless($access->canApproveTime($context->user, $record->project), 403);
                }
            },
        );

        return response()->json(['data' => ['approved_ids' => $ids]]);
    }

    /** @param list<string> $ids */
    private function guardEditableTime(Workspace $workspace, User $user, array $ids, ProjectAccess $access, bool $withTrashed = false): void
    {
        $query = ClientTimeEntry::query();
        if ($withTrashed) {
            $query->withTrashed();
        }
        $entries = $query->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)->with('project')->get();
        abort_unless($entries->count() === count($ids), 404);
        $workspaceManager = $workspace->memberships()->where('user_id', $user->id)->whereIn('role', ['owner', 'admin'])->exists();
        foreach ($entries as $record) {
            abort_unless($record->user_id === $user->id || $workspaceManager, 403);
            abort_unless($access->canView($user, $record->project), 404);
        }
    }
}
