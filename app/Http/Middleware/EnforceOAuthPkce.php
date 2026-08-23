<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceOAuthPkce
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->routeIs('passport.authorizations.authorize')) {
            $challenge = $request->query('code_challenge');
            if (! is_string($challenge) || $challenge === '' || $request->query('code_challenge_method') !== 'S256') {
                return new JsonResponse(['error' => 'invalid_request', 'error_description' => 'Authorization requests require S256 PKCE.'], 400, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
            }
        }

        return $next($request);
    }
}
