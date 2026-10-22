<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Services\Credentials\AgentCredentialSelection;
use App\Services\Credentials\CredentialRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;

/**
 * `connections.revoke`: a connection disconnects itself. The path names the
 * calling access token; any other id - another of the person's tokens, or
 * anyone else's - is a 404, so a connection can end only its own grant.
 * Revoking every connection a person holds is the operator's
 * `svc:auth:revoke-connections`, through the same {@see CredentialRevoker}.
 */
final class AgentConnectionController extends Controller
{
    public function destroy(Request $request, string $token, CredentialRevoker $revoker): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof AgentPrincipal, 401);
        $current = $user->currentAccessToken();
        $currentId = $current instanceof AccessToken ? $current->oauth_access_token_id : null;
        abort_unless(is_string($currentId) && $currentId !== '' && hash_equals($currentId, $token), 404);

        // The refresh credential is revoked before the access token, under the
        // access-token row lock, so the connection cannot mint a replacement.
        $revocation = $revoker->revoke(AgentCredentialSelection::token($user, $token), 'agent');
        // The token itself, or what a refresh completed meanwhile rotated it into.
        abort_unless($revocation->accessTokens() >= 1, 404);

        return response()->json(status: 204);
    }

    /** Discard only the bearer and its tracked refresh-token family. */
    public function revokeToken(Request $request, CredentialRevoker $revoker): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof AgentPrincipal, 401);
        $current = $user->currentAccessToken();
        abort_unless($current instanceof AccessToken && $current->oauth_access_token_id !== '', 401);

        $revoker->revoke(AgentCredentialSelection::token($user, $current->oauth_access_token_id), 'agent');

        return response()->json(status: 204);
    }

    /**
     * `connections.disconnect`: the calling REST credential's application is
     * disconnected from the calling person - every credential the person holds
     * for that OAuth client, through the same revoker and durable guard. A personal
     * API token shares one client with the person's other personal tokens, so
     * it ends only itself. The website's own session holds no token and has
     * nothing to disconnect.
     */
    public function disconnect(Request $request, CredentialRevoker $revoker): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof AgentPrincipal, 401);
        $current = $user->currentAccessToken();
        abort_unless($current instanceof AccessToken && $current->oauth_access_token_id !== '' && $current->oauth_client_id !== '', 404);

        $client = Passport::client()->newQuery()->find($current->oauth_client_id);
        $revoker->revoke($client === null || $client->hasGrantType('personal_access')
            ? AgentCredentialSelection::token($user, $current->oauth_access_token_id)
            : AgentCredentialSelection::connections($user, $current->oauth_client_id), 'agent');

        return response()->json(status: 204);
    }
}
