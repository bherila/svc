<?php

namespace App\Http\Middleware;

use BWH\Auth\OAuth\Server\DynamicClientRegistrationValidator;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hold an app a person registered to the permissions they chose for it (#384).
 *
 * The auth package enforces a stored scope ceiling only for self-registered
 * (dynamic) clients. An app registered on the setup page is not dynamic, so
 * without this an app created for `identity:read` could ask for billing writes
 * or `mcp:use` at the consent screen and, once approved, hold them. Runs on the
 * authorization request, where the scopes of the code - and so of every token
 * and refresh issued from it - are fixed.
 */
final class EnforceOwnedClientScopeCeiling
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || ! $request->routeIs('passport.authorizations.authorize')) {
            return $next($request);
        }
        $clientId = $request->query('client_id');
        $client = is_string($clientId) ? Passport::client()->newQuery()->find($clientId) : null;
        if (! $client instanceof Client || $client->getAttribute('owner_id') === null) {
            return $next($request);
        }

        $ceiling = $client->getAttribute('scopes');
        $scopeInput = $request->query('scope');
        $requested = is_string($scopeInput)
            ? DynamicClientRegistrationValidator::parseScopes($scopeInput)
            : Passport::defaultScopes();
        if (! is_array($ceiling) || $requested === [] || array_diff($requested, $ceiling) !== []) {
            return new JsonResponse([
                'error' => 'invalid_scope',
                'error_description' => 'This app may only request the permissions it was registered with.',
            ], 400, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        }

        return $next($request);
    }
}
