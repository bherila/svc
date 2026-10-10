<?php

namespace App\Http\Controllers;

use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\AccessibleWorkspacesQuery;
use BWH\Auth\OAuth\Credentials\ApiCredentialService;
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

        return $this->guide($user);
    }

    public function portal(Request $request, ClientCompany $clientCompany): Response
    {
        Gate::authorize('viewPortal', $clientCompany);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $this->guide($user);
    }

    private function guide(User $user): Response
    {
        $base = rtrim((string) config('app.url'), '/');
        $credentials = app(ApiCredentialService::class);
        $issuing = (bool) config('bherila-auth.oauth_server.enabled');

        return Inertia::render('mcp-setup', [
            'serverUrl' => $base.'/api/v1/mcp',
            'available' => (bool) config('agent_api.mcp_enabled') && (bool) config('bherila-auth.oauth_server.enabled'),
            // For apps that use the REST API with OAuth or an API token instead
            // of MCP (#384). Every URL is finished here; the page assembles none.
            'rest' => [
                'api_base_url' => $base.'/api/v1',
                'openapi_url' => route('openapi.document'),
                'authorize_url' => (string) config('bherila-auth.oauth_server.authorization_endpoint', $base.'/oauth/authorize'),
                'token_url' => (string) config('bherila-auth.oauth_server.token_endpoint', $base.'/oauth/token'),
                'scopes' => array_map(
                    static fn (string $id, string $description): array => ['id' => $id, 'description' => $description],
                    array_keys($credentials->grantableScopes()),
                    array_values($credentials->grantableScopes()),
                ),
                // ISO-8601 durations (P30D...), labelled by the page.
                'token_lifetimes' => $credentials->lifetimes(),
                // Null while the OAuth server is switched off: revoking stays available.
                'issue_token_href' => $issuing ? route('bherila-auth.credentials.tokens.store', absolute: false) : null,
                'register_app_href' => $issuing ? route('bherila-auth.credentials.apps.store', absolute: false) : null,
                'tokens' => array_map(static fn (array $token): array => [
                    ...$token,
                    'revoke_href' => route('bherila-auth.credentials.tokens.destroy', ['token' => $token['id']], absolute: false),
                ], $credentials->tokens($user)),
                'apps' => array_map(static fn (array $app): array => [
                    ...$app,
                    'delete_href' => route('bherila-auth.credentials.apps.destroy', ['client' => $app['id']], absolute: false),
                ], $credentials->apps($user)),
            ],
        ]);
    }
}
