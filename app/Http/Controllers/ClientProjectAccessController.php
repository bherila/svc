<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProjectAccessRequest;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Who may reach one project.
 *
 * Project membership stopped being decorative with #157: the client directory,
 * the company switcher and the workspace invoice list all now narrow to the
 * clients a member reaches, and reachability runs through these rows. Until now
 * they could only be written by a console command, which meant the scoping
 * could be tightened but not administered.
 *
 * One endpoint rather than a grant and a revoke, because "no access" is a
 * choice from the same list as every other role, and two endpoints would let a
 * caller grant without being able to state what they are replacing.
 */
class ClientProjectAccessController extends Controller
{
    public function update(
        UpdateProjectAccessRequest $request,
        Workspace $workspace,
        ClientCompany $clientCompany,
        ClientProject $clientProject,
        WorkspaceAuthorization $authorization,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);
        $authorization->assertOwnedBy($workspace, $clientCompany);
        $authorization->assertOwnedBy($workspace, $clientProject);

        abort_unless(
            (int) $clientProject->client_company_id === (int) $clientCompany->id,
            404,
        );

        // The person must already belong to the workspace. Granting project
        // access to someone outside it would create a membership row that
        // every reachability query honours while no workspace membership
        // backs it - access from a side door.
        $user = User::query()
            ->where('public_id', $request->string('user')->toString())
            ->first();

        abort_if($user === null, 404);
        abort_unless(
            $workspace->memberships()->where('user_id', $user->id)->exists(),
            404,
        );

        $role = $request->string('role')->toString();

        $existing = ClientProjectMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_project_id', $clientProject->id)
            ->where('user_id', $user->id);

        if ($role === UpdateProjectAccessRequest::NONE) {
            $existing->delete();

            return redirect()->back()->with('status', 'Project access removed.');
        }

        // Keyed on all three columns, so re-granting moves the role rather than
        // adding a second row that the reachability queries would both match.
        ClientProjectMembership::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'client_project_id' => $clientProject->id,
                'user_id' => $user->id,
            ],
            ['role' => $role],
        );

        return redirect()->back()->with('status', 'Project access updated.');
    }
}
