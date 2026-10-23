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

    // Provider identity enforcement: a browser session, an OAuth token and a
    // personal access token of an account bound to the identity provider stop
    // working once that identity is disabled, deleted, reset or ungranted
    // there, checked against the provider's status endpoint with one shared
    // observation per person (fresh for strictly less than five minutes;
    // privileged writes check freshly). Off until the operator sets
    // BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED=true; while off, sign-in still
    // records the provider's credential generation and new credentials are
    // stamped with it, so enabling it later keeps them. Credentials issued
    // before stamping are refused once it is on: connectors authorize again
    // once. Accounts with no provider binding are left alone.
    'provider_identity' => [
        'enabled' => (bool) env('BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED', false),
        // Null is the default store, which must be shared by every web worker
        // and support locks (the deployment's database store does).
        'cache_store' => env('BHERILA_AUTH_PROVIDER_IDENTITY_CACHE_STORE'),
        'binding' => [
            'provider_column' => 'oauth_provider',
            'subject_column' => 'oauth_subject',
        ],
        // An ended browser session signs in again.
        'expired_redirect_route' => 'login',
        // Signing out stays reachable during a provider outage.
        'except_routes' => ['logout'],
        'bearer_guard' => 'api',
    ],

    // The agent-API authorization-server profile: S256 PKCE for every client,
    // public-only self-registration, RFC 8707 binding, and `none` plus the two
    // client-secret methods advertised (apps a person registers on the setup
    // page may be confidential). Every URL derives from APP_URL. What follows
    // is only where SVC differs from the profile.
    'oauth_server' => AgentOAuthServer::config(AgentApiScopes::descriptions(), [
        'revocation' => ['enabled' => true],
        // Existing resource-bound credentials remain valid when issuance is
        // disabled; metadata, registration, authorization, and token routes do not.
        'enabled' => (bool) env('AGENT_API_OAUTH_SERVER_ENABLED', true),
        // Two protected resources, each its own audience with its own RFC 9728
        // document (at /.well-known/oauth-protected-resource plus the path) and
        // its own scope ceiling: REST at APP_URL/api/v1 and MCP at
        // APP_URL/api/v1/mcp. A token is accepted only on routes expecting its
        // resource (ExpectOAuthResource:<name>), so a REST token cannot open an
        // MCP connection and an MCP token cannot call REST. A client that names
        // no resource gets a REST token; an MCP client names the MCP endpoint,
        // which is where its 401 challenge sends it.
        'resources' => [
            'rest' => ['path' => '/api/v1', 'scopes' => AgentApiScopes::rest()],
            'mcp' => ['path' => '/api/v1/mcp', 'scopes' => AgentApiScopes::mcp()],
        ],
        'assume_omitted_resource' => 'rest',
        // Opening an MCP connection takes this scope, which only the MCP
        // resource admits; no personal API token is offered it.
        'resource_required_scopes' => [AgentApiScopes::MCP_USE],
        // Browser-based agents call discovery, registration and the token
        // endpoint directly. Only these origins get CORS headers there (never
        // credentials); any other origin gets none. The MCP endpoint keeps its
        // own origin policy (agent_api.mcp_allowed_origins).
        'cors' => [
            'allowed_origins' => ['https://claude.ai', 'https://chatgpt.com'],
        ],
        'dynamic_clients' => [
            'enabled' => true,
            'required_columns' => ['dynamically_registered_at', 'last_used_at', 'scopes'],
            'registered_at_column' => 'dynamically_registered_at',
            'last_used_at_column' => 'last_used_at',
            'scopes_column' => 'scopes',
            'enforce_registered_scopes' => true,
            // Days an unused self-registered client is kept before
            // `bherila-auth:prune-dynamic-clients` removes it (#460). Read from
            // SVC's existing setting so a deployment needs no new variable.
            'retention_days' => (int) env('AGENT_API_DYNAMIC_CLIENT_RETENTION_DAYS', 30),
        ],
        // RFC 9207: every authorization response (code or error, from the
        // consent screen or a first-party client that skips it) names this
        // issuer, so a client of several authorization servers can tell which
        // one answered before it exchanges a code.
        'authorization_response_issuer' => ['enabled' => true],
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
