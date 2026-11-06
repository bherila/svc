<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Queries\WorkspaceActionQueue;
use App\Services\Authorization\AgentAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The workspace tier's landing page, and the successor to the Operations screen.
 *
 * Owners and admins get the whole workspace queue plus the revenue slot (filled
 * in by the revenue lane). Every other member lands on the same page but sees
 * only their own queue - their draft time and assigned tasks - and the revenue
 * slot is not in their props at all: the key is absent rather than null, so no
 * figure and no placeholder for one reaches the browser of someone who may not
 * see billing. Decided here, never in the page.
 */
final class WorkspaceDashboardController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, AgentAccess $access, WorkspaceActionQueue $queue): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Gate::forUser($user)->authorize('view', $workspace);

        $manager = $access->isWorkspaceManager($user, $workspace);

        return Inertia::render('workspaces/dashboard', [
            'workspace' => ['id' => $workspace->public_id, 'name' => $workspace->name],
            'audience' => $manager ? 'workspace' : 'member',
            'queue' => $manager ? $queue->forManager($workspace) : $queue->forMember($workspace, $user),
            ...($manager ? ['revenue' => ['slot' => 'revenue']] : []),
        ]);
    }
}
