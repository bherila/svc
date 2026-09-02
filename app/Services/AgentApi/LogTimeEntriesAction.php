<?php

namespace App\Services\AgentApi;

use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;

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
        private readonly ProjectAccess $access,
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
            fn (array $ids) => $this->guardReplay($workspace, $user, $ids),
        );
    }

    /** @param list<string> $ids */
    private function guardReplay(Workspace $workspace, User $user, array $ids): void
    {
        $entries = ClientTimeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('public_id', $ids)
            ->with('project')
            ->get();
        abort_unless($entries->count() === count($ids), 404);
        $workspaceManager = $workspace->memberships()
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'admin'])
            ->exists();
        foreach ($entries as $entry) {
            abort_unless($entry->user_id === $user->id || $workspaceManager, 403);
            abort_unless($this->access->canView($user, $entry->project), 404);
            abort_unless($this->access->canLogTime($user, $entry->project), 403);
        }
    }
}
