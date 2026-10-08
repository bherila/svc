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
use App\Services\Authorization\AgentTokenScopes;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\AgentApiScopes;
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
        AgentTokenScopes $scopes,
    ): JsonResponse {
        $context = $contexts->from($request);
        $ids = $logTime->run(
            $context->user,
            $workspace,
            $context->oauthClientId,
            $context->idempotencyKey,
            $request->all(),
            $scopes->allows($request, AgentApiScopes::TIME_APPROVE),
        );
        $entriesById = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)->with('project')->withCapacityPlacement($workspace->id)->get()->keyBy('public_id');
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
        $record = $updateTime->run(
            $context->user,
            $workspace,
            $context->oauthClientId,
            $context->idempotencyKey,
            $entry,
            $request->all(),
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
        $id = $deleteTime->run(
            $context->user,
            $workspace,
            $context->oauthClientId,
            $context->idempotencyKey,
            $entry,
            $request->all(),
        );

        return response()->json(['data' => ['deleted_id' => $id]]);
    }

    public function unapprove(
        Request $request,
        Workspace $workspace,
        string $entry,
        TimeEntryMutationService $time,
        ProjectAccess $access,
        AgentTimeEntryPresenter $presenter,
        AgentMutationContextFactory $contexts,
        AgentMutationExecutor $mutations,
    ): JsonResponse {
        $context = $contexts->from($request);
        $ids = $mutations->run(
            $context->user,
            $workspace,
            $context->oauthClientId,
            'time_entries.unapprove',
            $context->idempotencyKey,
            ['entry_id' => $entry, 'body' => $request->all()],
            function () use ($request, $workspace, $entry, $time, $context): array {
                $data = $request->validate(['expected_version' => ['required', 'string', 'size:64']]);
                $record = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('public_id', $entry)->firstOrFail();

                return [$time->unapprove($workspace, $record, $context->user, $data['expected_version'])->public_id];
            },
            function (array $ids) use ($workspace, $access, $context): void {
                abort_unless($access->isWorkspaceManager($context->user, $workspace), 403);
                $count = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)
                    ->whereHas('project', fn ($projects) => $projects
                        ->where('workspace_id', $workspace->id)
                        ->whereColumn('client_projects.client_company_id', 'client_time_entries.client_company_id')
                        ->whereHas('clientCompany', fn ($companies) => $companies->where('workspace_id', $workspace->id)))
                    ->count();
                abort_unless($count === count($ids), 404);
            },
        );
        $record = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('public_id', $ids[0] ?? null)->firstOrFail();

        return response()->json(['data' => $presenter->present($workspace, $record)]);
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
