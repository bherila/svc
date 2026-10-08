<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProjectAccessRequest;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceProjectMutationAction;
use Illuminate\Http\RedirectResponse;

class ClientProjectAccessController extends Controller
{
    public function update(UpdateProjectAccessRequest $request, Workspace $workspace, ClientCompany $clientCompany, ClientProject $clientProject, WorkspaceProjectMutationAction $projects): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $role = $request->string('role')->toString();
        $projects->updateAccess($actor, $workspace, $clientProject, $request->string('user')->toString(), $role, companyId: $clientCompany->id);

        return redirect()->back()->with('status', $role === UpdateProjectAccessRequest::NONE ? 'Project access removed.' : 'Project access updated.');
    }
}
