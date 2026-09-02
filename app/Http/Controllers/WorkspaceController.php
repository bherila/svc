<?php

namespace App\Http\Controllers;

use App\Actions\CreateWorkspace;
use App\Http\Requests\StoreWorkspaceRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class WorkspaceController extends Controller
{
    public function store(StoreWorkspaceRequest $request, CreateWorkspace $createWorkspace): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $workspace = $createWorkspace->handle($user, $request->string('name')->toString());

        // Into the workspace that was just made, rather than back to the list
        // it was made from: the next thing to do is inside it.
        return redirect()->route('workspaces.enter', $workspace)->with('status', 'Workspace created.');
    }
}
