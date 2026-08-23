<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationReceiptService;
use App\Services\AgentApi\TimeEntryMutationService;
use App\Support\AgentApi\Presenters\AgentTimeEntryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;

final class AgentTimeEntryMutationController extends Controller
{
    public function store(Request $request, Workspace $workspace, AgentMutationReceiptService $receipts, TimeEntryMutationService $time, AgentTimeEntryPresenter $presenter): JsonResponse
    {
        $data = $request->validate(['entries' => ['required', 'array', 'min:1', 'max:20'], 'entries.*.project_id' => ['required', 'uuid'], 'entries.*.task_id' => ['nullable', 'uuid'], 'entries.*.worked_on' => ['required', 'date_format:Y-m-d'], 'entries.*.minutes' => ['required', 'integer', 'min:1', 'max:1440'], 'entries.*.description' => ['required', 'string', 'max:10000'], 'entries.*.is_billable' => ['sometimes', 'boolean'], 'entries.*.is_deferred' => ['sometimes', 'boolean'], 'entries.*.is_visible_to_client' => ['sometimes', 'boolean'], 'entries.*.client_visible_description' => ['nullable', 'string', 'max:10000'], 'entries.*.currency' => ['nullable', 'string', 'size:3']]);
        $user = $this->user($request);
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && $key !== '' && strlen($key) <= 255, 422, 'An Idempotency-Key header is required.');
        $ids = $receipts->run($user, $workspace, $this->clientId($request), 'time_entries.log', $key, $data, function () use ($workspace, $user, $data, $time): array {
            $ids = [];
            foreach ($data['entries'] as $entry) {
                $project = ClientProject::query()->where('workspace_id', $workspace->id)->where('public_id', $entry['project_id'])->firstOrFail();

                $ids[] = $time->create($workspace, $project, $user, $entry)->public_id;
            }

            return $ids;
        });
        $entries = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)->with('project')->get();

        return response()->json(['data' => $entries->map(fn (ClientTimeEntry $entry): array => $presenter->present($workspace, $entry))->values()], 201);
    }

    public function update(Request $request, Workspace $workspace, string $entry, TimeEntryMutationService $time, AgentTimeEntryPresenter $presenter): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'string', 'size:64'], 'worked_on' => ['sometimes', 'date_format:Y-m-d'], 'minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'], 'description' => ['sometimes', 'string', 'max:10000'], 'is_billable' => ['sometimes', 'boolean'], 'is_deferred' => ['sometimes', 'boolean'], 'is_visible_to_client' => ['sometimes', 'boolean'], 'client_visible_description' => ['nullable', 'string', 'max:10000']]);
        $record = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('public_id', $entry)->firstOrFail();
        $record = $time->update($workspace, $record, $this->user($request), $data);

        return response()->json(['data' => $presenter->present($workspace, $record)]);
    }

    public function destroy(Request $request, Workspace $workspace, string $entry, TimeEntryMutationService $time): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'string', 'size:64']]);
        $record = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('public_id', $entry)->firstOrFail();
        $time->delete($workspace, $record, $this->user($request), $data['expected_version']);

        return response()->json(['data' => ['deleted_id' => $record->public_id]]);
    }

    public function approve(Request $request, Workspace $workspace, TimeEntryMutationService $time): JsonResponse
    {
        $data = $request->validate(['entries' => ['required', 'array', 'min:1', 'max:100'], 'entries.*.id' => ['required', 'uuid', 'distinct'], 'entries.*.expected_version' => ['required', 'string', 'size:64']]);
        $time->approve($workspace, $this->user($request), $data['entries']);

        return response()->json(['data' => ['approved_ids' => array_column($data['entries'], 'id')]]);
    }

    private function user(Request $request): User
    {
        $principal = $request->user();
        abort_unless($principal instanceof AgentPrincipal, 401);

        return User::query()->findOrFail($principal->id);
    }

    private function clientId(Request $request): string
    {
        $token = $request->user('api')?->token();
        $attributes = $token instanceof AccessToken ? $token->toArray() : [];
        $id = $attributes['client_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : 'testing-client';
    }
}
