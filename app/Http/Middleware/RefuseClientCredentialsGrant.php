<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses the client credentials grant at the token endpoint, whatever the
 * client.
 *
 * Every SVC credential acts for a person; a token minted from a client secret
 * alone would act for nobody, and no operation is written for that. Passport
 * always enables the grant, and only a client registered for it can use it -
 * which no SVC registration path creates - so this is defence in depth: a
 * client created some other way still gets the RFC 6749 answer for a grant
 * the server does not support, before Passport looks at the client.
 */
final class RefuseClientCredentialsGrant
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('passport.token') && $request->input('grant_type') === 'client_credentials') {
            return new JsonResponse([
                'error' => 'unsupported_grant_type',
                'error_description' => 'The authorization grant type is not supported by the authorization server.',
            ], 400, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        }

        return $next($request);
    }
}
