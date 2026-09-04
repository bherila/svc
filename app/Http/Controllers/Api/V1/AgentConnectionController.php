<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

final class AgentConnectionController extends Controller
{
    public function destroy(Request $request, string $token): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof AgentPrincipal, 401);
        DB::transaction(function () use ($user, $token): void {
            $accessToken = $user->tokens()
                ->where('revoked', false)
                ->lockForUpdate()
                ->find($token);
            abort_unless($accessToken !== null, 404);

            // A v0.11 refresh token owns its resource binding so it can survive
            // Passport purging an expired access-token row. Disconnecting must
            // therefore revoke the refresh credential explicitly before the
            // access token, or the connection could mint a replacement token.
            Passport::refreshToken()->newQuery()
                ->where('access_token_id', $accessToken->getKey())
                ->update(['revoked' => true]);
            $accessToken->revoke();
        });

        return response()->json(status: 204);
    }
}
