<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientProjectRequest;
use App\Http\Requests\UpdateClientProjectRequest;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceProjectMutationAction;
use Illuminate\Http\RedirectResponse;

class ClientProjectController extends Controller
{
    public function store(StoreClientProjectRequest $request, Workspace $workspace, ClientCompany $clientCompany, WorkspaceProjectMutationAction $projects): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $projects->create($actor, $workspace, $clientCompany, $request->validated());

        return redirect()->back()->with('status', 'Project created.');
    }

    public function update(UpdateClientProjectRequest $request, Workspace $workspace, ClientCompany $clientCompany, ClientProject $clientProject, WorkspaceProjectMutationAction $projects): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $projects->update($actor, $workspace, $clientProject, $request->validated(), (int) $request->validated('lock_version'), $clientCompany->id);

        return redirect()->back()->with('status', 'Project updated.');
    }
}
