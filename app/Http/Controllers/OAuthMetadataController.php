<?php

namespace App\Http\Controllers;

use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Http\JsonResponse;

final class OAuthMetadataController extends Controller
{
    public function authorizationServer(): JsonResponse
    {
        return $this->response([
            'issuer' => url('/'),
            'authorization_endpoint' => url('/oauth/authorize'),
            'token_endpoint' => url('/oauth/token'),
            'registration_endpoint' => url('/oauth/register'),
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => array_keys(AgentApiScopes::descriptions()),
            'resource_indicators_supported' => true,
        ]);
    }

    public function protectedResource(): JsonResponse
    {
        return $this->response([
            'resource' => url('/api/v1'),
            'authorization_servers' => [url('/')],
            'scopes_supported' => array_keys(AgentApiScopes::descriptions()),
            'bearer_methods_supported' => ['header'],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function response(array $payload): JsonResponse
    {
        return response()->json($payload)->withHeaders(['Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}
