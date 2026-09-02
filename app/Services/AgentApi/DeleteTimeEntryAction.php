<?php

namespace App\Services\AgentApi;

use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Validator;

/** Tenant-scoped, idempotent soft deletion of one editable time entry. */
final class DeleteTimeEntryAction
{
    public function __construct(
        private readonly TimeEntryMutationService $time,
        private readonly AgentMutationExecutor $mutations,
        private readonly AgentEditableTimeEntryReplayGuard $replayGuard,
    ) {}

    /** @param array<string, mixed> $payload */
    public function run(User $user, Workspace $workspace, string $clientId, string $idempotencyKey, string $entryId, array $payload): string
    {
        $ids = $this->mutations->run(
            $user, $workspace, $clientId, 'time_entries.delete', $idempotencyKey,
            ['entry_id' => $entryId, 'body' => $payload],
            function () use ($workspace, $entryId, $user, $payload): array {
                $data = Validator::make($payload, [
                    'expected_version' => ['required', 'string', 'size:64'],
                ])->validate();
                $record = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('public_id', $entryId)->firstOrFail();
                $this->time->delete($workspace, $record, $user, $data['expected_version']);

                return [$record->public_id];
            },
            fn (array $ids) => $this->replayGuard->assertAllowed($workspace, $user, $ids, true),
        );

        return $ids[0];
    }
}
