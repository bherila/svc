<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentConnectionController extends Controller
{
    public function destroy(Request $request, string $token): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof AgentPrincipal, 401);
        $accessToken = $user->tokens()->where('revoked', false)->find($token);
        abort_unless($accessToken !== null, 404);
        $accessToken->revoke();

        return response()->json(status: 204);
    }
}
