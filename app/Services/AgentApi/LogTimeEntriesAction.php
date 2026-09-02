<?php

namespace App\Services\AgentApi;

use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Validator;

/**
 * The tenant-scoped, idempotent time-log workflow shared by REST and MCP.
 *
 * The action validates the payload inside the mutation executor so every
 * transport reaches the same receipt and failure-audit boundary.
 */
final class LogTimeEntriesAction
{
    public function __construct(
        private readonly TimeEntryMutationService $time,
        private readonly AgentMutationExecutor $mutations,
        private readonly AgentEditableTimeEntryReplayGuard $replayGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function run(User $user, Workspace $workspace, string $clientId, string $idempotencyKey, array $payload): array
    {
        return $this->mutations->run(
            $user,
            $workspace,
            $clientId,
            'time_entries.log',
            $idempotencyKey,
            $payload,
            function () use ($workspace, $user, $payload): array {
                $data = Validator::make($payload, [
                    'entries' => ['required', 'array', 'min:1', 'max:20'],
                    'entries.*' => ['required', 'array:project_id,task_id,worked_on,minutes,description,is_billable,is_deferred,is_visible_to_client,client_visible_description,currency'],
                    'entries.*.project_id' => ['required', 'uuid'],
                    'entries.*.task_id' => ['nullable', 'uuid'],
                    'entries.*.worked_on' => ['required', 'date_format:Y-m-d'],
                    'entries.*.minutes' => ['required', 'integer', 'min:1', 'max:1440'],
                    'entries.*.description' => ['required', 'string', 'max:10000'],
                    'entries.*.is_billable' => ['sometimes', 'boolean'],
                    'entries.*.is_deferred' => ['sometimes', 'boolean'],
                    'entries.*.is_visible_to_client' => ['sometimes', 'boolean'],
                    'entries.*.client_visible_description' => ['nullable', 'string', 'max:10000'],
                    'entries.*.currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
                ])->validate();
                $ids = [];
                foreach ($data['entries'] as $entry) {
                    $project = ClientProject::query()
                        ->where('workspace_id', $workspace->id)
                        ->where('public_id', $entry['project_id'] ?? null)
                        ->firstOrFail();
                    $ids[] = $this->time->create($workspace, $project, $user, $entry)->public_id;
                }

                return $ids;
            },
            fn (array $ids) => $this->replayGuard->assertAllowed($workspace, $user, $ids),
        );
    }
}
