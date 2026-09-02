<?php

namespace App\Services\AgentApi;

use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;

/** Tenant-scoped, idempotent soft deletion of one editable time entry. */
final class DeleteTimeEntryAction
{
    public function __construct(
        private readonly TimeEntryMutationService $time,
        private readonly AgentMutationExecutor $mutations,
        private readonly AgentEditableTimeEntryReplayGuard $replayGuard,
    ) {}

    public function run(User $user, Workspace $workspace, string $clientId, string $idempotencyKey, string $entryId, string $expectedVersion): string
    {
        $ids = $this->mutations->run(
            $user, $workspace, $clientId, 'time_entries.delete', $idempotencyKey,
            ['entry_id' => $entryId, 'body' => ['expected_version' => $expectedVersion]],
            function () use ($workspace, $entryId, $user, $expectedVersion): array {
                $record = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('public_id', $entryId)->firstOrFail();
                $this->time->delete($workspace, $record, $user, $expectedVersion);

                return [$record->public_id];
            },
            fn (array $ids) => $this->replayGuard->assertAllowed($workspace, $user, $ids, true),
        );

        return $ids[0];
    }
}
