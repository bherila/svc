<?php

namespace App\Http\Controllers;

use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\AccessibleWorkspacesQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class McpSetupController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, AccessibleWorkspacesQuery $accessible): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($accessible->for($user)->contains(fn (Workspace $option): bool => $option->id === $workspace->id), 404);

        return $this->guide();
    }

    public function portal(ClientCompany $clientCompany): Response
    {
        Gate::authorize('viewPortal', $clientCompany);

        return $this->guide();
    }

    private function guide(): Response
    {
        return Inertia::render('mcp-setup', [
            'serverUrl' => rtrim((string) config('app.url'), '/').'/api/v1/mcp',
            'available' => (bool) config('agent_api.mcp_enabled') && (bool) config('bherila-auth.oauth_server.enabled'),
        ]);
    }
}
