<?php

namespace App\Http\Controllers;

use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Laravel\Passport\ClientRepository;

final class OAuthDynamicClientRegistrationController extends Controller
{
    public function __invoke(Request $request, ClientRepository $clients): JsonResponse
    {
        if (! Schema::hasColumns('oauth_clients', ['dynamically_registered_at', 'last_used_at'])) {
            return response()->json(['error' => 'temporarily_unavailable'], 503, [
                'Cache-Control' => 'no-store',
                'Pragma' => 'no-cache',
            ]);
        }
        if (! $request->isJson() || strlen((string) $request->getContent()) > 16_384) {
            return $this->invalid();
        }
        $input = $request->json()->all();
        $allowed = ['client_name', 'redirect_uris', 'grant_types', 'response_types', 'token_endpoint_auth_method', 'scope', 'application_type'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            return $this->invalid();
        }
        $validator = Validator::make($input, [
            'client_name' => ['required', 'string', 'min:1', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'redirect_uris' => ['required', 'array', 'min:1', 'max:10'],
            'redirect_uris.*' => ['required', 'string', 'max:2048'],
            'grant_types' => ['sometimes', 'array', 'size:2'],
            'grant_types.*' => ['required', 'string', 'distinct', 'in:authorization_code,refresh_token'],
            'response_types' => ['sometimes', 'array', 'size:1'],
            'response_types.*' => ['required', 'string', 'distinct', 'in:code'],
            'token_endpoint_auth_method' => ['sometimes', 'in:none'],
            'scope' => ['sometimes', 'string', 'min:1', 'max:2048'],
            'application_type' => ['sometimes', 'string', 'in:native'],
        ]);
        if ($validator->fails()) {
            return $this->invalid();
        }
        $metadata = $validator->validated();
        $name = trim($metadata['client_name']);
        $uris = array_values(array_unique($metadata['redirect_uris']));
        $grants = $metadata['grant_types'] ?? ['authorization_code', 'refresh_token'];
        $responses = $metadata['response_types'] ?? ['code'];
        $scope = $metadata['scope'] ?? null;
        $scopes = $scope === null ? [] : explode(' ', $scope);
        sort($grants);
        if ($name === ''
            || $grants !== ['authorization_code', 'refresh_token']
            || $responses !== ['code']
            || ($metadata['token_endpoint_auth_method'] ?? 'none') !== 'none'
            || count($scopes) !== count(array_unique($scopes))
            || array_diff($scopes, array_keys(AgentApiScopes::descriptions())) !== []
            || array_filter($uris, fn (string $uri): bool => ! $this->isValidRedirectUri($uri)) !== []) {
            return $this->invalid();
        }
        $client = $clients->createAuthorizationCodeGrantClient($name, $uris, confidential: false);
        $client->forceFill(['dynamically_registered_at' => now(), 'last_used_at' => null])->save();

        $response = [
            'client_id' => $client->id,
            'client_id_issued_at' => $client->created_at?->getTimestamp(),
            'client_name' => $client->name,
            'redirect_uris' => $uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
        if ($scope !== null) {
            $response['scope'] = $scope;
        }
        if (isset($metadata['application_type'])) {
            $response['application_type'] = $metadata['application_type'];
        }

        return response()->json($response, 201, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
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
