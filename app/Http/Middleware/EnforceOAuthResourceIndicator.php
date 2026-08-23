<?php

namespace App\Http\Middleware;

use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\OAuthAuthorizationStateStore;
use App\Support\AgentApi\OAuthResourceIndicator;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceOAuthResourceIndicator
{
    public function __construct(private readonly OAuthAuthorizationStateStore $state) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('passport.authorizations.authorize') && $request->isMethod('GET')) {
            $resource = $request->query('resource');
            $scopes = explode(' ', (string) $request->query('scope', ''));
            $canonical = OAuthResourceIndicator::canonicalize($resource);
            if (($resource !== null && $canonical !== OAuthResourceIndicator::agentApi()) || (in_array(AgentApiScopes::MCP_USE, $scopes, true) && $canonical === null)) {
                return $this->invalid();
            }
            if ($canonical !== null) {
                $request->attributes->set(OAuthResourceIndicator::REQUEST_ATTRIBUTE, $canonical);
            }
            $previous = $this->state->current();
            $response = $next($request);
            $current = $this->state->current();
            if (is_string($current) && $current !== $previous && $canonical !== null) {
                $this->state->remember($current, $canonical);
            }

            return $response;
        }
        if ($request->routeIs('passport.authorizations.approve', 'passport.authorizations.deny')) {
            $token = $request->input('auth_token');
            if (is_string($token) && ($resource = $this->state->get($token)) !== null) {
                $request->attributes->set(OAuthResourceIndicator::REQUEST_ATTRIBUTE, $resource);
            }
        }
        if ($request->routeIs('passport.token') && $request->exists('resource') && OAuthResourceIndicator::canonicalize($request->input('resource')) === null) {
            return $this->invalid();
        }

        return $next($request);
    }

    private function invalid(): JsonResponse
    {
        return response()->json(['error' => 'invalid_target'], 400, ['Cache-Control' => 'no-store']);
    }
}
