<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientTaskRequest;
use App\Http\Requests\UpdateClientTaskRequest;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use App\Services\WorkspaceTaskMutationAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ClientTaskController extends Controller
{
    public function store(
        StoreClientTaskRequest $request,
        Workspace $workspace,
        ClientProject $clientProject,
        WorkspaceAuthorization $authorization,
        WorkspaceTaskMutationAction $tasks,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);
        $authorization->assertOwnedBy($workspace, $clientProject);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $tasks->create($actor, $workspace, $clientProject->public_id, $request->validated(), visibleDefault: true);

        return redirect()->back()->with('status', 'Task created.');
    }

    public function update(
        UpdateClientTaskRequest $request,
        Workspace $workspace,
        ClientTask $clientTask,
        WorkspaceAuthorization $authorization,
        WorkspaceTaskMutationAction $tasks,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);
        $authorization->assertOwnedBy($workspace, $clientTask);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $tasks->update($actor, $workspace, $clientTask->public_id, $request->validated());

        return redirect()->back()->with('status', 'Task updated.');
    }
}
