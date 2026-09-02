<?php

namespace App\Services\AgentApi;

use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;

/** Rechecks the existing time-edit policy before an idempotent result is replayed. */
final class AgentEditableTimeEntryReplayGuard
{
    public function __construct(private readonly ProjectAccess $access) {}

    /** @param list<string> $ids */
    public function assertAllowed(Workspace $workspace, User $user, array $ids, bool $withTrashed = false): void
    {
        $query = ClientTimeEntry::query();
        if ($withTrashed) {
            $query->withTrashed();
        }
        $entries = $query->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)->with('project')->get();
        abort_unless($entries->count() === count($ids), 404);
        $workspaceManager = $workspace->memberships()->where('user_id', $user->id)->whereIn('role', ['owner', 'admin'])->exists();
        foreach ($entries as $entry) {
            abort_unless($entry->user_id === $user->id || $workspaceManager, 403);
            abort_unless($this->access->canView($user, $entry->project), 404);
            abort_unless($this->access->canLogTime($user, $entry->project), 403);
        }
    }
}
