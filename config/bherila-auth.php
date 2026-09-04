<?php

use App\Support\AgentApi\AgentApiScopes;

return [
    // SVC uses the shared OAuth client service but does not expose the package's
    // password, passkey, two-factor, or audit-log routes.
    'routes' => [
        'enabled' => false,
    ],

    'oauth_server' => [
        // Existing resource-bound credentials remain valid when issuance is
        // disabled; metadata, registration, authorization, and token routes do not.
        'enabled' => env('AGENT_API_OAUTH_SERVER_ENABLED', true),
        'issuer' => rtrim((string) env('APP_URL', 'http://localhost'), '/'),
        'resource' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1',
        'authorization_endpoint' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/oauth/authorize',
        'token_endpoint' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/oauth/token',
        'registration_endpoint' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/oauth/register',
        'protected_resource_metadata_url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/.well-known/oauth-protected-resource/api/v1/mcp',
        'scopes' => AgentApiScopes::descriptions(),
        'token_endpoint_auth_methods' => ['none'],
        'resource_required_scope' => AgentApiScopes::MCP_USE,
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
    ],
];
