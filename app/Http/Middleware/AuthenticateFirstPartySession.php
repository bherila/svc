<?php

namespace App\Http\Middleware;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Support\AgentApi\FirstPartySession;
use BWH\Auth\OAuth\Session\ProviderBindingResolver;
use BWH\Auth\OAuth\Session\ProviderSession;
use BWH\Auth\OAuth\Session\ProviderSessionExpired;
use BWH\Auth\OAuth\Session\ProviderStatusUnavailable;
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
 * session is signed in and its provider identity is still active, authenticates
 * the `api` guard with a synthetic access token (see {@see FirstPartySession}). Anything else falls through to
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
                    $refusal = $this->refuseEndedProviderSession($request, $user);
                    if ($refusal !== null) {
                        return $refusal;
                    }
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

    /**
     * The session's provider identity check, as the web group applies it
     * (RequireActiveProviderSession), answered in JSON: an identity that ended
     * signs the session out (401), and an unavailable provider is a retryable
     * 503 that keeps the session. Unsafe methods check freshly, so a write the
     * website makes is never authorized on a stale observation. A no-op until
     * provider identity enforcement is enabled; unbound accounts are left to
     * the login policy.
     */
    private function refuseEndedProviderSession(Request $request, User $user): ?Response
    {
        if (! (bool) config('bherila-auth.provider_identity.enabled', false)) {
            return null;
        }

        try {
            $binding = app(ProviderBindingResolver::class)->binding($user);
            if ($binding === null) {
                return null;
            }
            app(ProviderSession::class)->assertActive($request, $binding->provider, $binding->subject, fresh: ! $request->isMethodSafe());
        } catch (ProviderSessionExpired) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Your sign-in has ended. Sign in again.'], 401, [
                'Cache-Control' => 'private, no-store',
            ]);
        } catch (ProviderStatusUnavailable) {
            return response()->json(['message' => 'Sign-in verification is temporarily unavailable. Please retry.'], 503, [
                'Retry-After' => '30',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return null;
    }
}
