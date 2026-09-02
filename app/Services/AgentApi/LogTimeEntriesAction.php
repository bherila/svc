<?php

namespace App\Services\AgentApi;

use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;

/**
 * The tenant-scoped, idempotent time-log workflow shared by REST and MCP.
 *
 * Transport adapters validate their public DTO before calling this action.
 */
final class LogTimeEntriesAction
{
    public function __construct(
        private readonly TimeEntryMutationService $time,
        private readonly AgentMutationExecutor $mutations,
        private readonly AgentEditableTimeEntryReplayGuard $replayGuard,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<string>
     */
    public function run(User $user, Workspace $workspace, string $clientId, string $idempotencyKey, array $entries): array
    {
        return $this->mutations->run(
            $user,
            $workspace,
            $clientId,
            'time_entries.log',
            $idempotencyKey,
            ['entries' => $entries],
            function () use ($workspace, $user, $entries): array {
                $ids = [];
                foreach ($entries as $entry) {
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
