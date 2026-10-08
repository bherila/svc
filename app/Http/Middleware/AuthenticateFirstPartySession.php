<?php

namespace App\Http\Middleware;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Support\AgentApi\FirstPartySession;
use Closure;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admit the signed-in website to `/api/v1` on its session (#385).
 *
 * Runs before `auth:api`. A request with a bearer token is an OAuth request
 * and is left entirely to Passport, so the two credentials never mix. A request
 * without one that carries the session cookie runs the web group's session and
 * request-forgery middleware - the same checks a form post gets - and, when the
 * session is signed in, authenticates the `api` guard with a synthetic access
 * token (see {@see FirstPartySession}). Anything else falls through to
 * `auth:api`, which answers 401 as it always has.
 */
final class AuthenticateFirstPartySession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() !== null || ! $request->cookies->has((string) config('session.cookie'))) {
            return $next($request);
        }

        return app(Pipeline::class)
            ->send($request)
            ->through([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                PreventRequestForgery::class,
            ])
            ->then(function (Request $request) use ($next): Response {
                $user = Auth::guard('web')->user();
                if ($user instanceof User) {
                    $principal = AgentPrincipal::query()->whereKey($user->id)->firstOrFail();
                    $principal->withAccessToken(new AccessToken([
                        'oauth_client_id' => FirstPartySession::CLIENT_ID,
                        'oauth_user_id' => (string) $principal->getKey(),
                        'oauth_scopes' => FirstPartySession::scopes(),
                    ]));
                    Auth::guard('api')->setUser($principal);
                    $request->attributes->set(FirstPartySession::ATTRIBUTE, true);
                }

                return $next($request);
            });
    }
}
