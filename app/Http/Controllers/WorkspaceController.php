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
        $createWorkspace->handle($user, $request->string('name')->toString());

        return redirect()->route('dashboard')->with('status', 'Workspace created.');
    }
}
