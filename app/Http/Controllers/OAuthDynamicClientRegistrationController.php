<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Passport\ClientRepository;

final class OAuthDynamicClientRegistrationController extends Controller
{
    public function __invoke(Request $request, ClientRepository $clients): JsonResponse
    {
        if (! $request->isJson() || strlen((string) $request->getContent()) > 16_384) {
            return $this->invalid();
        }
        $validator = Validator::make($request->json()->all(), [
            'client_name' => ['required', 'string', 'min:1', 'max:100'],
            'redirect_uris' => ['required', 'array', 'min:1', 'max:10'],
            'redirect_uris.*' => ['required', 'string', 'max:2048'],
            'grant_types' => ['sometimes', 'array'],
            'response_types' => ['sometimes', 'array'],
            'token_endpoint_auth_method' => ['sometimes', 'in:none'],
        ]);
        if ($validator->fails()) {
            return $this->invalid();
        }
        $metadata = $validator->validated();
        $name = trim($metadata['client_name']);
        $uris = array_values(array_unique($metadata['redirect_uris']));
        if ($name === '' || array_filter($uris, fn (string $uri): bool => ! $this->isValidRedirectUri($uri)) !== []) {
            return $this->invalid();
        }
        $client = $clients->createAuthorizationCodeGrantClient($name, $uris, confidential: false);

        return response()->json([
            'client_id' => $client->id,
            'client_id_issued_at' => $client->created_at?->getTimestamp(),
            'client_name' => $client->name,
            'redirect_uris' => $uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], 201, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function isValidRedirectUri(string $uri): bool
    {
        if (filter_var($uri, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($uri);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['fragment'], $parts['user'], $parts['pass'])) {
            return false;
        }

        return strtolower((string) $parts['scheme']) === 'https'
            || (strtolower((string) $parts['scheme']) === 'http' && in_array(strtolower((string) $parts['host']), ['localhost', '127.0.0.1', '[::1]'], true));
    }

    private function invalid(): JsonResponse
    {
        return response()->json(['error' => 'invalid_client_metadata', 'error_description' => 'Client metadata is invalid.'], 400, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
