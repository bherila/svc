<?php

namespace App\Services\Files;

use App\Models\ClientCompanyMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\Concurrency\Locks;

/** Agent-origin authority is current after domain locks, never an outer RR snapshot. */
final class AttachmentWriteAccess
{
    public function lockWorkspace(Workspace $workspace): void
    {
        Workspace::query()->whereKey($workspace->id)->tap(Locks::forShare())->firstOrFail();
    }

    public function assertCurrentManager(User $actor, Workspace $workspace, AttachmentMutationOrigin $origin = AttachmentMutationOrigin::Agent): void
    {
        $membership = WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)
            ->tap(Locks::forShare())->first();
        // Direct current rows: a company whereHas subquery can use an older
        // consistent-read snapshot and miss a newly introduced portal identity.
        $portal = ClientCompanyMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)
            ->orderBy('id')->tap(Locks::forShare())->get();
        abort_unless($membership !== null && in_array($membership->role, ['owner', 'admin'], true) && ($origin === AttachmentMutationOrigin::Native || $portal->isEmpty()), 403);
    }
}
