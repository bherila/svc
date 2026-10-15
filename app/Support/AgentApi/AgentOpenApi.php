<?php

namespace App\Support\AgentApi;

use App\Services\AgentApi\Operations\AgentRestDocumentation;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\OpenApi\OpenApiDocumentBuilder;
use Bherila\McpLaravelBridge\OpenApi\OpenApiSettings;

/**
 * The agent API contract, generated from the operation registry (#408 item 3).
 *
 * Paths, security, parameters and prose come from the registry's declarations
 * ({@see AgentRestDocumentation}); schemas from the components of
 * `public/openapi/svc-agent-v1.json`, which is also the checked copy of the
 * generated document and fails a test when the two drift apart.
 */
final class AgentOpenApi
{
    public const string CHECKED_DOCUMENT = 'openapi/svc-agent-v1.json';

    /** The URLs the checked copy names; the served document names this installation's. */
    public const string CHECKED_BASE_URL = 'https://svc.example.test';

    /** The document addressed to this installation, from configuration. */
    public static function forInstallation(): OpenApiDocumentBuilder
    {
        $base = rtrim((string) config('app.url'), '/');

        return self::builder(
            $base,
            (string) config('bherila-auth.oauth_server.authorization_endpoint', $base.'/oauth/authorize'),
            (string) config('bherila-auth.oauth_server.token_endpoint', $base.'/oauth/token'),
        );
    }

    /** The document as checked in. */
    public static function checked(): OpenApiDocumentBuilder
    {
        return self::builder(self::CHECKED_BASE_URL, self::CHECKED_BASE_URL.'/oauth/authorize', self::CHECKED_BASE_URL.'/oauth/token');
    }

    private static function builder(string $base, string $authorizationUrl, string $tokenUrl): OpenApiDocumentBuilder
    {
        return new OpenApiDocumentBuilder(app(OperationRegistry::class), new OpenApiSettings(
            title: 'SVC API',
            version: '1.0.0',
            serverUrl: $base.'/api/v1',
            description: 'Versioned API for role-filtered client, agreement, project, task, time, expense, invoice and received-payment operations. Payment recording is bookkeeping only and never initiates a charge. Expense receipts use authenticated multipart uploads.',
            authorizationUrl: $authorizationUrl,
            tokenUrl: $tokenUrl,
            scopes: AgentApiScopes::descriptions(),
            apiTokenDescription: 'A personal API token created on the SVC setup page. It carries the scopes chosen when it was created and expires after the chosen lifetime.',
            connectionScopes: [AgentApiScopes::MCP_USE],
            summaries: false,
            agentExtensions: true,
            parameters: AgentRestDocumentation::parameters(),
            responses: ['Error' => [
                'description' => 'OAuth, authorization, tenant, conflict, validation, or rate-limit error',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
            ]],
            sharedResponses: ['default' => 'Error'],
            apiTokenBearerFormat: 'JWT',
            oauthDescription: 'Authorization code with S256 PKCE and interactive sign-in.',
            refreshUrl: false,
        ), AgentApiResponseSchemaCatalog::catalog());
    }
}
