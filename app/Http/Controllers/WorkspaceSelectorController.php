<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Queries\AccessibleWorkspacesQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where signing in lands: choose a workspace, and nothing else.
 *
 * Its predecessor loaded every client, project and task of every workspace and
 * then tried to be a workspace creator, client creator, project creator, task
 * creator, task manager and navigation page at once. The result grew with the
 * data, so the screen got slower and less useful the longer the account was
 * used - and none of it was the question being asked. That question has one
 * answer per row: which tenant am I working in.
 *
 * Deliberately not skipped when there is only one workspace. Auto-entering
 * saves a click the first time and then removes the only place from which the
 * second workspace is reachable, so a person who gains one has nowhere to see
 * it; and it makes the SVC wordmark - the one intentional way back out - lead
 * to a page that immediately bounces.
 */
class WorkspaceSelectorController extends Controller
{
    public function __invoke(Request $request, AccessibleWorkspacesQuery $accessible): Response
    {
        $user = $request->user();
        abort_if(! $user instanceof User, 401);

        return Inertia::render('workspaces/index', [
            'workspaces' => $accessible->for($user)
                ->map(fn (Workspace $workspace): array => [
                    'id' => (string) $workspace->public_id,
                    'name' => (string) $workspace->name,
                    // Entering resolves which client to open, so the row links
                    // at the resolver rather than guessing a destination here.
                    'enter_href' => route('workspaces.enter', $workspace, absolute: false),
                ])
                ->values()
                ->all(),
        ]);
    }
}
