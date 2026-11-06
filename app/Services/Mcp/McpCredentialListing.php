<?php

namespace App\Services\Mcp;

use App\Models\User;
use BWH\Auth\OAuth\Credentials\ApiCredentialService;

/**
 * The credentials of one signed-in person that open MCP connections, as the
 * MCP setup page lists them: each connected app and each personal token that
 * carries a connection scope, with its "Show all tools" setting and the
 * finished URL that changes it. Only this person's own live credentials are
 * listed, so the page can offer the setting for nothing else.
 *
 * @phpstan-type Row array{id: string, name: string, show_all_tools: bool, update_href: string}
 */
final readonly class McpCredentialListing
{
    public function __construct(
        private ApiCredentialService $credentials,
        private McpCredentialSettings $settings,
    ) {}

    /** @return array{connections: list<Row>, tokens: list<Row>} */
    public function for(User $user): array
    {
        $connected = $this->credentials->connectedClients($user);
        $tokens = array_values(array_filter(
            $this->credentials->tokens($user),
            fn (array $token): bool => $this->credentials->carriesConnectionScope($token['scopes']),
        ));
        $connectionSettings = $this->settings->forConnections($user->id, array_column($connected, 'id'));
        $tokenSettings = $this->settings->forTokens($user->id, array_column($tokens, 'id'));

        return [
            'connections' => array_map(static fn (array $client): array => [
                'id' => $client['id'],
                'name' => $client['name'],
                'show_all_tools' => $connectionSettings[$client['id']] ?? false,
                'update_href' => route('mcp.credentials.connections.update', ['client' => $client['id']], absolute: false),
            ], $connected),
            'tokens' => array_map(static fn (array $token): array => [
                'id' => $token['id'],
                'name' => $token['name'],
                'show_all_tools' => $tokenSettings[$token['id']] ?? false,
                'update_href' => route('mcp.credentials.tokens.update', ['token' => $token['id']], absolute: false),
            ], $tokens),
        ];
    }
}
