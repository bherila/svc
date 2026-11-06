<?php

namespace App\Services\Mcp;

use App\Models\McpCredentialSetting;
use Illuminate\Database\Eloquent\Builder;

/**
 * The per-credential MCP settings a person controls from the MCP setup page.
 *
 * A credential is identified the way the person sees it. A connected app is one
 * OAuth client for one person: the access token changes at every refresh, the
 * connection does not, so its setting is keyed by client and survives a
 * refresh. A personal token is itself, keyed by its access-token id. Every read
 * and write names the owner, so one credential's setting never reaches another
 * credential, including another of the same person's.
 */
final class McpCredentialSettings
{
    /** Whether this credential asked to list every authorized tool at `initialize`. */
    public function showsAllTools(int $userId, string $accessTokenId, string $clientId): bool
    {
        return McpCredentialSetting::query()
            ->where('user_id', $userId)
            ->where('show_all_tools', true)
            ->where(static function (Builder $credential) use ($accessTokenId, $clientId): void {
                $credential->where('access_token_id', $accessTokenId)->orWhere('oauth_client_id', $clientId);
            })
            ->exists();
    }

    public function setForConnection(int $userId, string $clientId, bool $showAllTools): void
    {
        McpCredentialSetting::query()->upsert(
            [['user_id' => $userId, 'oauth_client_id' => $clientId, 'access_token_id' => null, 'show_all_tools' => $showAllTools]],
            ['user_id', 'oauth_client_id'],
            ['show_all_tools'],
        );
    }

    public function setForToken(int $userId, string $accessTokenId, bool $showAllTools): void
    {
        McpCredentialSetting::query()->upsert(
            [['user_id' => $userId, 'oauth_client_id' => null, 'access_token_id' => $accessTokenId, 'show_all_tools' => $showAllTools]],
            ['access_token_id'],
            ['show_all_tools'],
        );
    }

    /**
     * @param  list<string>  $clientIds
     * @return array<string, bool> client id => whether it shows all tools
     */
    public function forConnections(int $userId, array $clientIds): array
    {
        return McpCredentialSetting::query()
            ->where('user_id', $userId)
            ->whereIn('oauth_client_id', $clientIds)
            ->pluck('show_all_tools', 'oauth_client_id')
            ->map(static fn (mixed $on): bool => (bool) $on)
            ->all();
    }

    /**
     * @param  list<string>  $tokenIds
     * @return array<string, bool> access-token id => whether it shows all tools
     */
    public function forTokens(int $userId, array $tokenIds): array
    {
        return McpCredentialSetting::query()
            ->where('user_id', $userId)
            ->whereIn('access_token_id', $tokenIds)
            ->pluck('show_all_tools', 'access_token_id')
            ->map(static fn (mixed $on): bool => (bool) $on)
            ->all();
    }
}
