<?php

namespace App\Services\Mcp\Context;

use App\Models\AgentPrincipal;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

/** Resolves only facts authenticated by Passport; MCP arguments are excluded. */
final class McpPrincipalResolver
{
    /**
     * @throws AuthenticationException
     */
    public function resolve(Request $request): McpPrincipal
    {
        $subject = $request->user('api');
        if (! $subject instanceof AgentPrincipal) {
            throw new AuthenticationException('Unauthenticated MCP request.');
        }

        $accessToken = $subject->token();
        if (! $accessToken instanceof AccessToken
            || ! is_string($accessToken->oauth_access_token_id)
            || $accessToken->oauth_access_token_id === '') {
            throw new AuthenticationException('Invalid MCP credential.');
        }

        // Passport's authenticated AccessToken is a lightweight claim object.
        // Re-read its backing row so revocation, expiry, audience, and client
        // changes made after discovery take effect before every MCP request.
        $token = Passport::token()->newQuery()->find($accessToken->oauth_access_token_id);
        if (! $token instanceof Token
            || (bool) $token->revoked
            || $token->expires_at === null
            || $token->expires_at->isPast()
            || (int) $token->user_id !== (int) $subject->id
            || ! is_string($token->client_id)
            || $token->client_id === ''
            || ! hash_equals(OAuthResourceIndicator::resource(), (string) $token->getAttribute('resource_uri'))) {
            throw new AuthenticationException('Invalid MCP credential.');
        }

        $scopes = array_values(array_filter(
            $token->scopes,
            static fn (mixed $scope): bool => is_string($scope) && $scope !== '',
        ));

        return new McpPrincipal($subject, $token->id, $token->client_id, $scopes);
    }
}
