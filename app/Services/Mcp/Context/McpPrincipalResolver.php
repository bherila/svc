<?php

namespace App\Services\Mcp\Context;

use App\Models\AgentPrincipal;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        $token = $subject->token();
        if (! $token instanceof Token
            || (bool) $token->revoked
            || ! $token->expires_at instanceof Carbon
            || $token->expires_at->isPast()
            || ! is_string($token->client_id)
            || $token->client_id === ''
            || ! is_string($token->id)
            || $token->id === ''
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
