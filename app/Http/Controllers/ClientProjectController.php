<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientProjectRequest;
use App\Models\ClientCompany;
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
}
