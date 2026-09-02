<?php

namespace App\Queries;

use App\Models\ClientCompanyMembership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every workspace this person may enter, by either door.
 *
 * There are two, and the selector has to know about both. Internal staff hold a
 * `workspace_memberships` row. An external portal user never does - they are
 * deliberately not workspace members, which is what stops the portal being a
 * way onto the operator screens - and reach exactly one tenant through a client
 * company membership instead. Reading only the first, as the dashboard this
 * replaces did, signed a portal user in and then showed them an empty page with
 * nothing to open.
 *
 * Answering "which workspaces" is not answering "with what authority". Both
 * doors produce a row here; what each viewer may then *do* inside is decided
 * where it always was - `WorkspacePolicy` for operators, `PortalAccess` and
 * `ClientCompanyPolicy` for clients - and the navigation factory hands each of
 * them the route family that matches. Widening `WorkspacePolicy::view` to admit
 * portal users would have been the short version of this and would have made
 * every workspace-wide operator screen reachable from outside.
 */
final class AccessibleWorkspacesQuery
{
    /**
     * @return Collection<int, Workspace>
     */
    public function for(User $user): Collection
    {
        $throughMembership = $user->workspaces()->select('workspaces.*');

        // Portal memberships carry an explicit `workspace_id`, derived from the
        // company when the row is written, so this needs no join back through
        // the company - and cannot be satisfied by a membership that names a
        // company of another tenant.
        $throughPortal = ClientCompanyMembership::query()
            ->where('user_id', $user->id)
            ->pluck('workspace_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return Workspace::query()
            ->where(function ($scope) use ($throughMembership, $throughPortal): void {
                $scope->whereIn('id', $throughMembership->select('workspaces.id'));

                if ($throughPortal !== []) {
                    $scope->orWhereIn('id', array_values(array_unique($throughPortal)));
                }
            })
            // One row per workspace however many doors lead to it: the union is
            // over ids, so someone who is both a member and a portal user of
            // the same tenant sees it once.
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }
}
