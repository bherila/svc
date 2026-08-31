<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientProjectRequest;
use App\Http\Requests\UpdateClientProjectRequest;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ClientProjectController extends Controller
{
    public function store(
        StoreClientProjectRequest $request,
        Workspace $workspace,
        ClientCompany $clientCompany,
        WorkspaceAuthorization $authorization,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);
        $authorization->assertOwnedBy($workspace, $clientCompany);

        $clientCompany->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => $request->string('name')->toString(),
            'description' => $request->validated('description'),
            'is_visible_to_client' => $request->boolean('is_visible_to_client', true),
        ]);

        return redirect()->route('dashboard')->with('status', 'Project created.');
    }

    /**
     * Rename, describe, archive or hide one project.
     *
     * The project is checked against the workspace *and* against the company
     * in the URL, because it binds by a public id unique across every
     * workspace - otherwise a manager edits another client's project through
     * their own client's Manage tab.
     */
    public function update(
        UpdateClientProjectRequest $request,
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

        $clientProject->update([
            'name' => $request->string('name')->toString(),
            'description' => $request->validated('description'),
            'status' => $request->string('status')->toString(),
            'is_visible_to_client' => $request->boolean('is_visible_to_client'),
        ]);

        return redirect()->back()->with('status', 'Project updated.');
    }
}
