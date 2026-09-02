<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Services\AgentApi\DeleteTimeEntryAction;
use App\Services\AgentApi\LogTimeEntriesAction;
use App\Services\AgentApi\TimeEntryMutationService;
use App\Services\AgentApi\UpdateTimeEntryAction;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\Presenters\AgentTimeEntryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentTimeEntryMutationController extends Controller
{
    public function store(
        Request $request,
        Workspace $workspace,
        LogTimeEntriesAction $logTime,
        AgentTimeEntryPresenter $presenter,
        AgentMutationContextFactory $contexts,
    ): JsonResponse {
        $context = $contexts->from($request);
        $data = $request->validate(['entries' => ['required', 'array', 'min:1', 'max:20'], 'entries.*' => ['required', 'array:project_id,task_id,worked_on,minutes,description,is_billable,is_deferred,is_visible_to_client,client_visible_description,currency'], 'entries.*.project_id' => ['required', 'uuid'], 'entries.*.task_id' => ['nullable', 'uuid'], 'entries.*.worked_on' => ['required', 'date_format:Y-m-d'], 'entries.*.minutes' => ['required', 'integer', 'min:1', 'max:1440'], 'entries.*.description' => ['required', 'string', 'max:10000'], 'entries.*.is_billable' => ['sometimes', 'boolean'], 'entries.*.is_deferred' => ['sometimes', 'boolean'], 'entries.*.is_visible_to_client' => ['sometimes', 'boolean'], 'entries.*.client_visible_description' => ['nullable', 'string', 'max:10000'], 'entries.*.currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/']]);
        $ids = $logTime->run(
            $context->user,
            $workspace,
            $context->oauthClientId,
            $context->idempotencyKey,
            $data['entries'],
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
        UpdateTimeEntryAction $updateTime,
        AgentTimeEntryPresenter $presenter,
        AgentMutationContextFactory $contexts,
    ): JsonResponse {
        $context = $contexts->from($request);
        $data = $request->validate(['expected_version' => ['required', 'string', 'size:64'], 'worked_on' => ['sometimes', 'date_format:Y-m-d'], 'minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'], 'description' => ['sometimes', 'string', 'max:10000'], 'is_billable' => ['sometimes', 'boolean'], 'is_deferred' => ['sometimes', 'boolean'], 'is_visible_to_client' => ['sometimes', 'boolean'], 'client_visible_description' => ['nullable', 'string', 'max:10000']]);
        $record = $updateTime->run(
            $context->user,
            $workspace,
            $context->oauthClientId,
            $context->idempotencyKey,
            $entry,
            $data,
        );

        return response()->json(['data' => $presenter->present($workspace, $record)]);
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        string $entry,
        DeleteTimeEntryAction $deleteTime,
        AgentMutationContextFactory $contexts,
    ): JsonResponse {
        $context = $contexts->from($request);
        $data = $request->validate(['expected_version' => ['required', 'string', 'size:64']]);
        $id = $deleteTime->run(
            $context->user,
            $workspace,
            $context->oauthClientId,
            $context->idempotencyKey,
            $entry,
            $data['expected_version'],
        );

        return response()->json(['data' => ['deleted_id' => $id]]);
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
                $data = $request->validate(['entries' => ['required', 'array', 'min:1', 'max:100'], 'entries.*' => ['required', 'array:id,expected_version,billing_rate_amount,currency'], 'entries.*.id' => ['required', 'uuid', 'distinct'], 'entries.*.expected_version' => ['required', 'string', 'size:64'], 'entries.*.billing_rate_amount' => ['sometimes', 'integer', 'min:0'], 'entries.*.currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/']]);
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
}
