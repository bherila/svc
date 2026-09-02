<?php

namespace App\Services\AgentApi;

use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;

/** Tenant-scoped, idempotent update of one editable time entry. */
final class UpdateTimeEntryAction
{
    public function __construct(
        private readonly TimeEntryMutationService $time,
        private readonly AgentMutationExecutor $mutations,
        private readonly AgentEditableTimeEntryReplayGuard $replayGuard,
    ) {}

    /** @param array<string, mixed> $data */
    public function run(User $user, Workspace $workspace, string $clientId, string $idempotencyKey, string $entryId, array $data): ClientTimeEntry
    {
        $ids = $this->mutations->run(
            $user, $workspace, $clientId, 'time_entries.update', $idempotencyKey,
            ['entry_id' => $entryId, 'body' => $data],
            function () use ($workspace, $entryId, $user, $data): array {
                $record = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('public_id', $entryId)->firstOrFail();

                return [$this->time->update($workspace, $record, $user, $data)->public_id];
            },
            fn (array $ids) => $this->replayGuard->assertAllowed($workspace, $user, $ids),
        );

        return ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('public_id', $ids[0] ?? null)->firstOrFail();
    }
}
