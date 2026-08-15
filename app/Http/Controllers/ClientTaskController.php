<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientTaskRequest;
use App\Http\Requests\UpdateClientTaskRequest;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ClientTaskController extends Controller
{
    public function store(
        StoreClientTaskRequest $request,
        Workspace $workspace,
        ClientProject $clientProject,
        WorkspaceAuthorization $authorization,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);
        $authorization->assertOwnedBy($workspace, $clientProject);

        $clientProject->tasks()->create([
            'workspace_id' => $workspace->id,
            'title' => $request->string('title')->toString(),
            'description' => $request->validated('description'),
            'is_visible_to_client' => $request->boolean('is_visible_to_client', true),
        ]);

        return redirect()->route('dashboard')->with('status', 'Task created.');
    }

    public function update(
        UpdateClientTaskRequest $request,
        Workspace $workspace,
        ClientTask $clientTask,
        WorkspaceAuthorization $authorization,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);
        $authorization->assertOwnedBy($workspace, $clientTask);

        $status = $request->string('status')->toString();
        $clientTask->update([
            'status' => $status,
            'completed_at' => $status === 'completed' ? now() : null,
            'is_visible_to_client' => $request->boolean('is_visible_to_client', $clientTask->is_visible_to_client),
        ]);

        return redirect()->route('dashboard')->with('status', 'Task updated.');
    }
}
