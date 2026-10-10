<?php

use App\Models\AgentPrincipal;
use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\OAuth\Server\AgentOAuthServer;

$appUrl = rtrim((string) env('APP_URL', 'http://localhost'), '/');

return [
    // SVC uses the shared OAuth client service but does not expose the package's
    // password, passkey, two-factor, or audit-log routes.
    'routes' => [
        'enabled' => false,
    ],

    // Per-client limits key on Request::ip(). Behind a CDN that is only the
    // client when the CDN is trusted to forward it, and the origin also answers
    // direct connections, so only the CDN's published ranges are trusted, and
    // only X-Forwarded-For and its scheme - never the forwarded port (which the
    // CDN passes through from the client) or host.
    //
    // TRUSTED_PROXIES: "cloudflare" (the default), an explicit comma-separated
    // list of addresses / CIDR ranges, "*" only where an origin firewall admits
    // the proxy alone, or empty to trust nothing (no proxy in front). The
    // weekly cloudflare-ranges workflow reports drift in the shipped ranges.
    'trusted_proxies' => [
        'apply' => (bool) env('BHERILA_AUTH_TRUSTED_PROXIES', true),
        'trusted' => env('TRUSTED_PROXIES', 'cloudflare'),
        'cloudflare' => null,
    ],

    // The agent-API authorization-server profile: S256 PKCE for every client,
    // public-only self-registration, RFC 8707 binding to APP_URL/api/v1 with an
    // omitted `resource` taken as that resource, and `none` plus the two
    // client-secret methods advertised (apps a person registers on the setup
    // page may be confidential). Every URL derives from APP_URL. What follows
    // is only where SVC differs from the profile.
    'oauth_server' => AgentOAuthServer::config(AgentApiScopes::descriptions(), [
        // Existing resource-bound credentials remain valid when issuance is
        // disabled; metadata, registration, authorization, and token routes do not.
        'enabled' => (bool) env('AGENT_API_OAUTH_SERVER_ENABLED', true),
        // MCP clients discover the server from the MCP endpoint's challenge.
        'protected_resource_metadata_url' => $appUrl.'/.well-known/oauth-protected-resource/api/v1/mcp',
        // Opening an MCP connection takes this scope; no REST credential is offered it.
        'resource_required_scopes' => [AgentApiScopes::MCP_USE],
        'dynamic_clients' => [
            'enabled' => true,
            'required_columns' => ['dynamically_registered_at', 'last_used_at', 'scopes'],
            'registered_at_column' => 'dynamically_registered_at',
            'last_used_at_column' => 'last_used_at',
            'scopes_column' => 'scopes',
            'enforce_registered_scopes' => true,
        ],
        'authorization_state' => [
            'cache_prefix' => 'svc-oauth-resource:',
            'ttl_seconds' => null,
        ],
        'consent' => [
            'app_name' => 'SVC',
            'heading' => 'Connect :client to :app?',
            'intro' => 'This application is requesting access to your SVC account.',
            'identity' => true,
            'trust_warning' => 'Only continue if you recognize and trust this application. You can revoke the connection later.',
            'dynamic_client_warning' => 'This application registered automatically. After approval, your browser returns to:',
            'policy_notice' => 'SVC permissions and current workspace and project roles still apply to every request.',
            'approve_label' => 'Authorize',
            'deny_label' => 'Cancel',
        ],
        // A person's own API tokens and OAuth apps for REST clients (#384).
        // Browser session routes only, so no OAuth credential can mint another.
        // Tokens and apps belong to the agent principal the API guard loads,
        // and personal tokens reuse the existing personal-access client.
        'credentials' => [
            'enabled' => true,
            'prefix' => 'account/api-credentials',
            'middleware' => ['web', 'auth'],
            'token_lifetimes' => ['P30D', 'P90D', 'P365D'],
            'token_name_prefix' => 'api-token: ',
            'personal_client_name' => 'SVC personal access tokens',
            'provider' => 'agent-principals',
            'owner_model' => AgentPrincipal::class,
        ],
    ], $appUrl),
];
