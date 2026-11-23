<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\AuthenticateFirstPartySession;
use BWH\Auth\Http\Middleware\RequireActiveProviderSession;
use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

/**
 * Provider session route census (docs/provider-identity.md, gap 3): no route can
 * authenticate a person by session without the provider identity check.
 *
 * SVC checks a browser session in exactly two places. `RequireActiveProviderSession`
 * is appended to the `web` group (bootstrap/app.php), so it runs on every route that
 * group carries. `AuthenticateFirstPartySession` admits the signed-in website to
 * `/api/v1` on its own session pipeline and runs the same check itself, in JSON
 * (proved by ProviderIdentityEnforcementTest::test_the_first_party_session_api_is_refused_when_the_identity_ended).
 * A route registered outside `web` - or one that drops the check with
 * withoutMiddleware() - would keep a session the identity provider has ended, with
 * nothing else failing. This census fails it.
 *
 * A route can authenticate a person by session when its resolved middleware:
 *
 * - starts or reads a session (`StartSession`, `AuthenticateSession` and its
 *   subclasses, Sanctum's stateful-frontend middleware, or
 *   `AuthenticateFirstPartySession`, which starts one itself);
 * - authenticates a session guard (`auth` with the default guard, `auth:web`, any
 *   guard whose driver is `session`, or `auth.basic`); or
 * - authenticates `auth:sanctum` while a session is started, since Sanctum's guard
 *   tries the session guards in `sanctum.guard` first. Without a session there is
 *   nobody in those guards, so `auth:sanctum` alone is a token check.
 *
 * Such a route must carry `RequireActiveProviderSession`. The first-party check
 * stands in for it only when that middleware is the route's one use of a session:
 * the route starts no session of its own and authenticates no session guard, so
 * every session the route can see is the one AuthenticateFirstPartySession checked.
 * A route group that hands the website's session to `/api/v1` in any other way fails.
 *
 * Middleware is read from Router::gatherRouteMiddleware(), which expands groups and
 * aliases and drops withoutMiddleware() exclusions, plus the kernel's global
 * middleware, as RouteThrottleCensusTest does.
 */
final class ProviderSessionRouteCensusTest extends TestCase
{
    /**
     * Session-authenticated routes allowed to skip the provider check, keyed by route
     * name or, for an unnamed route, by "METHODS uri" exactly as the failure message
     * prints it. Every entry needs a reason a reviewer can check; an entry that
     * matches no finding fails the census so the list cannot rot.
     *
     * @var array<string, string>
     */
    private const ALLOWLIST = [];

    public function test_every_session_authenticated_route_checks_the_provider_identity(): void
    {
        $census = $this->census();
        $unchecked = $this->withoutAllowlisted($census['unchecked']);

        self::assertSame([], $unchecked, "These routes can authenticate a person by session without the provider identity check:\n  - "
            .implode("\n  - ", $unchecked)."\n\nRegister them in the `web` group (routes/web.php and the files it loads), where "
            .'RequireActiveProviderSession runs on every route. A route under /api/v1 that admits the website\'s session '
            .'does so only through AuthenticateFirstPartySession, with `auth:api` and no session middleware of its own. If a '
            .'route genuinely needs neither, add it to ALLOWLIST in '.self::class.' with the reason.');

        // The census cannot pass by finding nothing: a signed-in workspace screen is
        // checked through the web group, and the website's session into the REST API
        // through the first-party check.
        self::assertContains('GET|HEAD app', $census['web'], 'The census no longer sees the web group check on /app.');
        self::assertContains('GET|HEAD api/v1/context', $census['first_party'], 'The census no longer sees the first-party session check on /api/v1/context.');
    }

    public function test_allowlist_entries_match_a_live_finding(): void
    {
        $stale = array_values(array_diff(array_keys(self::ALLOWLIST), $this->keysOf($this->census()['unchecked'])));

        self::assertSame([], $stale, 'ALLOWLIST entries in '.self::class.' match no route that still needs them '
            .'(the route is gone, renamed or now checked). Remove these entries: '.implode(', ', $stale));
    }

    /**
     * The census must catch what it claims to catch: prove it on synthetic routes.
     */
    public function test_census_detects_each_way_a_session_can_skip_the_check(): void
    {
        Route::get('__census/auth', fn (): string => 'ok')->middleware('auth');
        Route::get('__census/auth-web', fn (): string => 'ok')->middleware('auth:web');
        Route::get('__census/auth-basic', fn (): string => 'ok')->middleware('auth.basic');
        Route::get('__census/session', fn (): string => 'ok')->middleware(StartSession::class);
        Route::get('__census/sanctum-with-session', fn (): string => 'ok')->middleware([StartSession::class, 'auth:sanctum']);
        Route::get('__census/web-without-check', fn (): string => 'ok')
            ->middleware(['web', 'auth'])
            ->withoutMiddleware(RequireActiveProviderSession::class);
        Route::get('__census/first-party-with-own-session', fn (): string => 'ok')
            ->middleware([AuthenticateFirstPartySession::class, StartSession::class, 'auth:api']);
        Route::get('__census/first-party-with-session-guard', fn (): string => 'ok')
            ->middleware([AuthenticateFirstPartySession::class, 'auth:web,api']);

        Route::get('__census/web', fn (): string => 'ok')->middleware(['web', 'auth']);
        Route::get('__census/web-guest', fn (): string => 'ok')->middleware('web');
        Route::get('__census/first-party', fn (): string => 'ok')->middleware([AuthenticateFirstPartySession::class, 'auth:api']);
        Route::get('__census/token', fn (): string => 'ok')->middleware('auth:api');
        Route::get('__census/sanctum-token', fn (): string => 'ok')->middleware('auth:sanctum,api');
        Route::get('__census/anonymous', fn (): string => 'ok');

        $census = $this->census();

        self::assertSame([
            'GET|HEAD __census/auth',
            'GET|HEAD __census/auth-web',
            'GET|HEAD __census/auth-basic',
            'GET|HEAD __census/session',
            'GET|HEAD __census/sanctum-with-session',
            'GET|HEAD __census/web-without-check',
            'GET|HEAD __census/first-party-with-own-session',
            'GET|HEAD __census/first-party-with-session-guard',
        ], $this->synthetic($census['unchecked']));
        self::assertSame(['GET|HEAD __census/web', 'GET|HEAD __census/web-guest'], $this->synthetic($census['web']));
        self::assertSame(['GET|HEAD __census/first-party'], $this->synthetic($census['first_party']));
    }

    /**
     * Every session-authenticated route, by how it is checked.
     *
     * @return array{unchecked: list<string>, web: list<string>, first_party: list<string>}
     */
    private function census(): array
    {
        $global = $this->globalMiddleware();
        $router = $this->app->make(Router::class);
        $census = ['unchecked' => [], 'web' => [], 'first_party' => []];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $classes = $this->classesIn([...$global, ...$router->gatherRouteMiddleware($route)]);
            $ownSession = $this->startsOrReadsASession($classes);
            $firstParty = $this->has($classes, AuthenticateFirstPartySession::class);
            $sessionGuard = $this->authenticatesASessionGuard($classes, $ownSession);
            if (! $ownSession && ! $firstParty && ! $sessionGuard) {
                continue;
            }

            $label = $this->routeLabel($route);
            if ($this->has($classes, RequireActiveProviderSession::class)) {
                $census['web'][] = $label;
            } elseif ($firstParty && ! $ownSession && ! $sessionGuard) {
                $census['first_party'][] = $label;
            } else {
                $census['unchecked'][] = $label.($route->getName() !== null ? '  (name: '.$route->getName().')' : '');
            }
        }

        return $census;
    }

    /**
     * The resolved middleware as [class, parameters] pairs; closures name no class.
     *
     * @param  array<int, mixed>  $middleware
     * @return list<array{0: string, 1: list<string>}>
     */
    private function classesIn(array $middleware): array
    {
        $classes = [];
        foreach ($middleware as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            [$class, $parameters] = array_pad(explode(':', $entry, 2), 2, '');
            $classes[] = [ltrim($class, '\\'), $parameters === '' ? [] : explode(',', $parameters)];
        }

        return $classes;
    }

    /** @param  list<array{0: string, 1: list<string>}>  $classes */
    private function has(array $classes, string $middleware): bool
    {
        foreach ($classes as [$class]) {
            if (class_exists($class) && is_a($class, $middleware, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<array{0: string, 1: list<string>}>  $classes */
    private function startsOrReadsASession(array $classes): bool
    {
        return $this->has($classes, StartSession::class)
            || $this->has($classes, AuthenticateSession::class)
            || $this->has($classes, EnsureFrontendRequestsAreStateful::class);
    }

    /** @param  list<array{0: string, 1: list<string>}>  $classes */
    private function authenticatesASessionGuard(array $classes, bool $sessionStarted): bool
    {
        foreach ($classes as [$class, $guards]) {
            if (! class_exists($class)) {
                continue;
            }
            if (is_a($class, AuthenticateWithBasicAuth::class, true)) {
                return true;
            }
            if (! is_a($class, Authenticate::class, true)) {
                continue;
            }
            foreach ($guards === [] ? [(string) config('auth.defaults.guard')] : $guards as $guard) {
                if ($this->isSessionGuard($guard)) {
                    return true;
                }
                if ($sessionStarted && config("auth.guards.{$guard}.driver") === 'sanctum') {
                    foreach ((array) config('sanctum.guard', ['web']) as $sanctumGuard) {
                        if ($this->isSessionGuard((string) $sanctumGuard)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private function isSessionGuard(string $guard): bool
    {
        return config("auth.guards.{$guard}.driver") === 'session';
    }

    /**
     * @return list<string|Closure>
     */
    private function globalMiddleware(): array
    {
        $kernel = $this->app->make(HttpKernel::class);

        return $kernel instanceof FoundationHttpKernel ? array_values($kernel->getGlobalMiddleware()) : [];
    }

    private function routeLabel(RoutingRoute $route): string
    {
        return implode('|', $route->methods()).' '.$route->uri();
    }

    /**
     * The allowlist key a finding matches: its route name, or "METHODS uri" for an unnamed route.
     *
     * @param  list<string>  $findings
     * @return list<string>
     */
    private function keysOf(array $findings): array
    {
        return array_map(
            static fn (string $finding): string => preg_match('/\(name: ([^)]+)\)/', $finding, $match) === 1 ? $match[1] : explode('  ', $finding)[0],
            $findings,
        );
    }

    /**
     * @param  list<string>  $findings
     * @return list<string>
     */
    private function withoutAllowlisted(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            fn (string $finding): bool => ! array_key_exists($this->keysOf([$finding])[0], self::ALLOWLIST),
        ));
    }

    /**
     * The synthetic routes registered by the self-test, without the name note.
     *
     * @param  list<string>  $findings
     * @return list<string>
     */
    private function synthetic(array $findings): array
    {
        return array_values(array_filter(
            array_map(static fn (string $finding): string => explode('  ', $finding)[0], $findings),
            static fn (string $label): bool => str_contains($label, ' __census/'),
        ));
    }
}
