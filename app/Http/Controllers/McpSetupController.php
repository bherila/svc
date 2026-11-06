<?php

namespace App\Http\Controllers;

use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\AccessibleWorkspacesQuery;
use App\Services\Mcp\McpCredentialListing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class McpSetupController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, AccessibleWorkspacesQuery $accessible, McpCredentialListing $credentials): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($accessible->for($user)->contains(fn (Workspace $option): bool => $option->id === $workspace->id), 404);

        return $this->guide($user, $credentials);
    }

    public function portal(Request $request, ClientCompany $clientCompany, McpCredentialListing $credentials): Response
    {
        Gate::authorize('viewPortal', $clientCompany);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $this->guide($user, $credentials);
    }

    private function guide(User $user, McpCredentialListing $credentials): Response
    {
        $base = rtrim((string) config('app.url'), '/');

        return Inertia::render('mcp-setup', [
            'serverUrl' => $base.'/api/v1/mcp',
            // The signed-in person's own connected apps and MCP tokens, each with
            // its "Show all tools" setting and the finished URL that changes it.
            'credentials' => $credentials->for($user),
            'available' => (bool) config('agent_api.mcp_enabled') && (bool) config('bherila-auth.oauth_server.enabled'),
            // For apps that use the REST API with OAuth or an API token instead
            // of MCP (#384). Every URL is finished here; the page assembles none.
            'rest' => [
                'api_base_url' => $base.'/api/v1',
                'openapi_url' => route('openapi.document'),
                'authorize_url' => (string) config('bherila-auth.oauth_server.authorization_endpoint', $base.'/oauth/authorize'),
                'token_url' => (string) config('bherila-auth.oauth_server.token_endpoint', $base.'/oauth/token'),
                // The credential forms read everything else (scopes, groups, presets,
                // lists, action URLs, and whether issuing is on) from the package's
                // JSON index, so no secret-adjacent state rides in page props.
                'index_url' => route('bherila-auth.credentials.index', absolute: false),
            ],
        ]);
    }
}
