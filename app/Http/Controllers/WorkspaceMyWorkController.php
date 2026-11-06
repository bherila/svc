<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Queries\MyWorkQuery;
use App\Queries\Submission\LightWorkspaceHome;
use App\Support\Workspaces\WorkspaceEdition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My work": a workspace member's own tasks, a way to log time, and the time
 * they have logged (docs/ux-overhaul.md section 5).
 *
 * Any workspace member may open it, because everything on it is read through
 * the viewer's own assignments, own time entries and own project memberships.
 * It carries no rate, amount, invoice or revenue figure for anyone, whatever the
 * viewer's role, so there is nothing for a subcontractor's version to hide.
 */
final class WorkspaceMyWorkController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, MyWorkQuery $work, LightWorkspaceHome $light): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Gate::forUser($user)->authorize('view', $workspace);

        return Inertia::render('workspaces/my-work', [
            'workspace' => ['id' => $workspace->public_id, 'name' => $workspace->name],
            ...$work->for($workspace, $user),
            // A partner's light workspace also shows its setup and the month's
            // hours on their way to approval; every other workspace sends null.
            'light' => WorkspaceEdition::of($workspace->getAttribute('edition'))->isLight() ? $light->for($workspace, $user) : null,
        ]);
    }
}
