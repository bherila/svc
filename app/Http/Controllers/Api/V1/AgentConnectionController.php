<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Services\Credentials\AgentCredentialSelection;
use App\Services\Credentials\CredentialRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;

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
}
