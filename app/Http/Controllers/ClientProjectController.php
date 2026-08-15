<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientProjectRequest;
use App\Models\ClientCompany;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ClientProjectController extends Controller
{
    public function store(
        StoreClientProjectRequest $request,
        Workspace $workspace,
        ClientCompany $clientCompany,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);
        abort_unless($clientCompany->workspace_id === $workspace->id, 404);

        $clientCompany->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => $request->string('name')->toString(),
            'description' => $request->validated('description'),
            'is_visible_to_client' => $request->boolean('is_visible_to_client', true),
        ]);

        return redirect()->route('dashboard')->with('status', 'Project created.');
    }
}
