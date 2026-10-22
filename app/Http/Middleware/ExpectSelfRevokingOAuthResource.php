<?php

namespace App\Http\Middleware;

use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the bearer-disposal route accepts either configured resource. Choosing
 * an audience here does not authenticate the bearer: Passport still verifies
 * its signature, issuer, expiry, owner and persisted resource/scope binding.
 * Every other route continues to require its own fixed audience.
 */
final class ExpectSelfRevokingOAuthResource
{
    public function handle(Request $request, Closure $next): Response
    {
        $resource = OAuthResourceIndicator::defaultName();
        foreach (OAuthResourceIndicator::resources() as $name => $configured) {
            if (OAuthResourceIndicator::tokenHasAudience($request->bearerToken(), $configured['uri'])) {
                $resource = $name;
                break;
            }
        }
        OAuthResourceIndicator::expectFor($request, $resource);

        return $next($request);
    }
}
