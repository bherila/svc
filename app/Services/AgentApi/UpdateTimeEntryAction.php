<?php

namespace App\Services\AgentApi;

use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Validator;

/** Tenant-scoped, idempotent update of one editable time entry. */
final class UpdateTimeEntryAction
{
    public function __construct(
        private readonly TimeEntryMutationService $time,
        private readonly AgentMutationExecutor $mutations,
        private readonly AgentEditableTimeEntryReplayGuard $replayGuard,
    ) {}

    /** @param array<string, mixed> $payload */
    public function run(User $user, Workspace $workspace, string $clientId, string $idempotencyKey, string $entryId, array $payload): ClientTimeEntry
    {
        $ids = $this->mutations->run(
            $user, $workspace, $clientId, 'time_entries.update', $idempotencyKey,
            ['entry_id' => $entryId, 'body' => $payload],
            function () use ($workspace, $entryId, $user, $payload): array {
                $data = Validator::make($payload, [
                    'expected_version' => ['required', 'string', 'size:64'],
                    'worked_on' => ['sometimes', 'date_format:Y-m-d'],
                    'minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
                    'description' => ['sometimes', 'string', 'max:10000'],
                    'is_billable' => ['sometimes', 'boolean'],
                    'is_deferred' => ['sometimes', 'boolean'],
                    'is_visible_to_client' => ['sometimes', 'boolean'],
                    'client_visible_description' => ['nullable', 'string', 'max:10000'],
                ])->validate();
                $record = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('public_id', $entryId)->firstOrFail();

                return [$this->time->update($workspace, $record, $user, $data)->public_id];
            },
            fn (array $ids) => $this->replayGuard->assertAllowed($workspace, $user, $ids),
        );

        return ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('public_id', $ids[0] ?? null)->firstOrFail();
    }
}
