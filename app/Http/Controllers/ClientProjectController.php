<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientProjectRequest;
use App\Http\Requests\UpdateClientProjectRequest;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use App\Support\Concurrency\Locks;
use App\Support\RepositoryReference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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
            // Stored canonical, never as typed: the operator pastes whatever
            // their checkout printed, and only one spelling of it can match.
            'repository' => RepositoryReference::normalize($request->validated('repository')),
            'is_visible_to_client' => $request->boolean('is_visible_to_client', true),
        ]);

        // Back to the settings screen it was created from. Projects are
        // created inside one client, so returning to a workspace-wide page
        // would leave the operator to find their way back in.
        return redirect()->back()->with('status', 'Project created.');
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

        // Re-read under a row lock inside the transaction that writes, keyed on
        // every column the authorization above relied on.
        //
        // Those assertions describe the instances the router bound, and the
        // router binds by public id alone, so the write that followed named
        // only the primary key: reparent the project - to another workspace, or
        // to another company in this one - between the check and the write, and
        // the request modifies a row it was never authorized for. The project
        // carries both keys, so both are re-asserted here rather than only the
        // workspace.
        DB::transaction(function () use ($workspace, $clientCompany, $clientProject, $request): void {
            $locked = ClientProject::query()
                ->whereKey($clientProject->getKey())
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $clientCompany->id)
                ->tap(Locks::forUpdate())
                ->first();

            abort_if($locked === null, 404);

            // The version the form was rendered from. A stale payload is
            // well-formed - every field validates - so this is the only thing
            // that separates "set visibility to true" from "I loaded this page
            // before someone hid it". Refused rather than merged, because the
            // field at stake decides what a client can see and a silent
            // last-write-wins re-exposes it.
            //
            // A validation error rather than a 409: Inertia reserves 409 for
            // its own asset-version protocol and would turn one into a full
            // page reload, discarding the operator's edits and telling them
            // nothing. This puts the reason on the field.
            if ((int) $locked->lock_version !== (int) $request->validated('lock_version')) {
                throw ValidationException::withMessages([
                    'lock_version' => 'This project changed while you were editing it. Reload to see the current values before saving.',
                ]);
            }

            $locked->update([
                'name' => $request->string('name')->toString(),
                'description' => $request->validated('description'),
                'repository' => RepositoryReference::normalize($request->validated('repository')),
                'status' => $request->string('status')->toString(),
                'is_visible_to_client' => $request->boolean('is_visible_to_client'),
            ]);
        });

        return redirect()->back()->with('status', 'Project updated.');
    }
}
