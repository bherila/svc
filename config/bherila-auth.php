<?php

use App\Support\AgentApi\AgentApiScopes;

return [
    // SVC uses the shared OAuth client service but does not expose the package's
    // password, passkey, two-factor, or audit-log routes.
    'routes' => [
        'enabled' => false,
    ],

    'oauth_server' => [
        'issuer' => rtrim((string) env('APP_URL', 'http://localhost'), '/'),
        'resource' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1',
        'authorization_endpoint' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/oauth/authorize',
        'token_endpoint' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/oauth/token',
        'registration_endpoint' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/oauth/register',
        'scopes' => AgentApiScopes::descriptions(),
        'token_endpoint_auth_methods' => ['none'],
        'resource_required_scope' => AgentApiScopes::MCP_USE,
        'dynamic_clients' => [
            'required_columns' => ['dynamically_registered_at', 'last_used_at'],
            'registered_at_column' => 'dynamically_registered_at',
            'last_used_at_column' => 'last_used_at',
            'scopes_column' => null,
            'enforce_registered_scopes' => false,
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
