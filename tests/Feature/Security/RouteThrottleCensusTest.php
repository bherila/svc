<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Api\V1\AgentMcpController;
use App\Http\Middleware\LimitMcpBatchSize;
use App\Http\Middleware\ThrottleOuterAgentRequests;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\RedirectController;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Route throttle census: every route's resolved middleware, checked for the two
 * throttle mistakes the cross-repository security sweep kept finding (ported from
 * x-template's census of the same name).
 *
 * 1. **A numeric throttle without a prefix** (`throttle:5,1`, `throttle`,
 *    `ThrottleRequests::with(5, 1)`, `ThrottleOuterAgentRequests:60,1`).
 *    ThrottleRequests keys such a limit on the user id (or domain|IP for guests) and
 *    nothing else, so every route using an unprefixed numeric throttle draws on one
 *    shared bucket per person: a burst on one route spends another route's budget,
 *    and the tightest limit wins everywhere. In SVC that included the agent API: an
 *    agent principal's id is its user's id, so browser searches spent the agent's
 *    allowance. A named limiter (`throttle:name`, see App\Support\Http\RateLimits)
 *    or a third, prefix argument (`throttle:5,1,name`) gives the route its own bucket.
 *    Routes may share a prefix deliberately: REST and MCP share `agent-api`.
 * 2. **A state-changing route with no throttle at all.** Any route accepting a
 *    method other than GET/HEAD/OPTIONS, or a GET whose URI or name says it exports
 *    or renders a document, must carry a throttle, or appear in
 *    UNTHROTTLED_ALLOWLIST with a reason. A `Route::redirect()` changes nothing and
 *    is exempt.
 *
 * It also fails on `throttle:name` for a limiter nobody registered (ThrottleRequests
 * then treats the name as a max-attempts count and throws on every request), and on
 * an MCP route that does not cap JSON-RPC batch size with LimitMcpBatchSize.
 *
 * Middleware is read from Router::gatherRouteMiddleware(), which expands groups and
 * aliases and drops withoutMiddleware() exclusions, plus the kernel's global
 * middleware, so a throttle applied through a group counts. SVC configures no
 * global API throttle.
 *
 * The census sees only middleware. It cannot tell which routes send mail, call a
 * processor, fan out notifications or export data under another name: those need a
 * dedicated named limiter, chosen in review (see "Security invariants" in AGENTS.md).
 */
final class RouteThrottleCensusTest extends TestCase
{
    /**
     * Routes that may change state without any throttle, keyed by route name or,
     * for an unnamed route, by "METHODS uri" exactly as the failure message prints
     * it. Every entry needs a reason a reviewer can check; an entry that matches no
     * route fails the census so the list cannot rot.
     *
     * @var array<string, string>
     */
    private const UNTHROTTLED_ALLOWLIST = [
        'svc.billing.stripe.webhook' => 'Processor webhook: StripeWebhookController verifies the Stripe-Signature header '
            .'before reading the event, and Stripe retries a refused delivery, so a 429 would delay or drop a genuine '
            .'event. RateLimits documents the choice and RateLimitsTest pins that these routes stay unthrottled.',
        'svc.billing.stripe.workspace-webhook' => 'Processor webhook, as svc.billing.stripe.webhook, checked against the '
            .'workspace\'s own signing secret; the 40-character endpoint token is random.',
        'svc.billing.square.webhook' => 'Processor webhook: the Square controller verifies the HMAC signature before '
            .'acting, and Square retries. Pinned unthrottled by RateLimitsTest.',
        'svc.billing.square.workspace-webhook' => 'Processor webhook, as svc.billing.square.webhook, per workspace.',
        'packages.public.request' => 'The public service-package request form. PublicPackageController applies its '
            .'own per-address cap and answers every refusal (late, throttled, capped) with the same 422 (PUB-5), so '
            .'throttle middleware would disclose which guard fired. See routes/packages.php.',
        'bherila-auth.delegated-access' => 'Vendor route (bherila/auth-laravel). Called server-to-server by the identity '
            .'provider; the controller answers 404 until delegated access is enabled, then applies its own rate '
            .'limit and verifies a signed, single-use actor assertion before anything else.',
        'passport.token.refresh' => 'Vendor route (laravel/passport). web + auth: it refreshes the signed-in session\'s '
            .'own transient API cookie and needs a valid session and CSRF token.',
        'passport.authorizations.approve' => 'Vendor route (laravel/passport). web + auth: approving needs the signed-in '
            .'session, its CSRF token and the authorization request that session stored on GET oauth/authorize.',
        'passport.authorizations.deny' => 'Vendor route (laravel/passport), as passport.authorizations.approve.',
    ];

    /**
     * Routes whose numeric throttle has no prefix and cannot be given one here,
     * keyed like UNTHROTTLED_ALLOWLIST. SVC's own routes have none: each of these is
     * a vendor route whose middleware is fixed in the package. With every SVC route
     * prefixed, these share a bucket only with each other.
     *
     * @var array<string, string>
     */
    private const UNPREFIXED_ALLOWLIST = [
        'passport.token' => 'Vendor route (laravel/passport) with a bare `throttle` (60 a minute). Guests, so keyed on '
            .'domain|address; no SVC route shares an unprefixed per-address bucket with it.',
    ];

    /**
     * Safe-method routes that must still be throttled because they are expensive
     * to serve: exports walk a whole data set, and PDFs and the pro forma are
     * rendered in the request.
     */
    private const EXPENSIVE_READ_PATTERN = '/export|\bpdf\b|proforma/i';

    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** Middleware classes that take `throttle`'s own parameters (attempts, minutes, prefix). */
    private const THROTTLE_CLASSES = [ThrottleRequests::class, ThrottleOuterAgentRequests::class];

    public function test_no_route_uses_a_numeric_throttle_without_a_prefix(): void
    {
        $findings = $this->withoutAllowlisted($this->census()['unprefixed'], self::UNPREFIXED_ALLOWLIST);

        self::assertSame([], $findings, $this->fixMessage(
            'These routes use a numeric throttle with no prefix, so they share one rate-limit bucket per person '
            .'with every other route doing the same:',
            $findings,
            "Fix: give each its own bucket. Prefer a named limiter in App\\Support\\Http\\RateLimits\n"
            ."(DEFAULTS or COSTLY) and ->middleware('throttle:<name>'). Or add a unique third argument:\n"
            .'    throttle:5,1,invoices-send    or    ThrottleRequests::with(5, 1, \'invoices-send\')'."\n"
            .'Agent API routes use RateLimits::AGENT_API_THROTTLE. A vendor route whose middleware cannot be changed '
            .'goes in UNPREFIXED_ALLOWLIST in '.self::class.' with the reason.',
        ));
    }

    public function test_named_throttles_refer_to_registered_limiters(): void
    {
        $census = $this->census();

        self::assertSame([], $census['unregistered'], $this->fixMessage(
            'These routes name a rate limiter that is not registered. ThrottleRequests then reads the name as a '
            .'max-attempts count and throws MissingRateLimiterException on every request:',
            $census['unregistered'],
            'Fix: add it to App\\Support\\Http\\RateLimits::DEFAULTS (or COSTLY), or correct the name.',
        ));
    }

    public function test_every_state_changing_route_is_throttled(): void
    {
        $findings = $this->withoutAllowlisted($this->census()['unthrottled'], self::UNTHROTTLED_ALLOWLIST);

        self::assertSame([], $findings, $this->fixMessage(
            'These state-changing, export or document routes have no throttle:',
            $findings,
            "Fix: add ->middleware('throttle:<limiter>') with a named limiter sized for the action (an ordinary\n"
            ."browser write takes 'throttle:web-writes'). Routes that send mail, call Stripe or Square, fan out\n"
            ."notifications or render documents need a tight limiter of their own (RateLimits::COSTLY), not a\n"
            ."shared default. If a route genuinely must stay unthrottled (a signature-verified webhook whose sender\n"
            .'retries), add it to UNTHROTTLED_ALLOWLIST in '.self::class.' with the reason.',
        ));
    }

    public function test_allowlist_entries_match_a_live_finding(): void
    {
        $census = $this->census();
        $stale = [
            ...array_diff(array_keys(self::UNTHROTTLED_ALLOWLIST), $this->keysOf($census['unthrottled'])),
            ...array_diff(array_keys(self::UNPREFIXED_ALLOWLIST), $this->keysOf($census['unprefixed'])),
        ];

        self::assertSame([], array_values($stale), 'Allowlist entries in '.self::class.' match no route that still '
            .'needs them (the route is gone, renamed or now throttled). Remove these entries: '.implode(', ', $stale));
    }

    /**
     * An MCP endpoint must refuse an oversized JSON-RPC batch before the SDK runs it:
     * the SDK accepts up to a hundred messages per POST while the HTTP throttle counts
     * the POST once. LimitMcpBatchSize holds the bound (AgentMcpReadOnlyTest proves the
     * behaviour); this proves every MCP route carries it.
     */
    public function test_every_mcp_route_caps_json_rpc_batch_size(): void
    {
        $router = $this->app->make(Router::class);
        $mcpRoutes = [];
        $uncapped = [];
        foreach ($this->routes() as $route) {
            if (! $this->isMcpEndpoint($route) || ! in_array('POST', $route->methods(), true)) {
                continue;
            }
            $mcpRoutes[] = $this->routeLabel($route);
            if (! in_array(LimitMcpBatchSize::class, $router->gatherRouteMiddleware($route), true)) {
                $uncapped[] = $this->routeLabel($route);
            }
        }

        self::assertNotSame([], $mcpRoutes, 'The census no longer finds the MCP endpoint; update isMcpEndpoint().');
        self::assertSame([], $uncapped, 'These MCP routes accept a POST without LimitMcpBatchSize, so one request can '
            .'run a whole JSON-RPC batch: '.implode(', ', $uncapped));
    }

    /**
     * The census must catch what it claims to catch: prove it on synthetic routes.
     */
    public function test_census_detects_each_violation_class(): void
    {
        RateLimiter::for('census-named', fn (): Limit => Limit::perMinute(5));

        Route::post('__census/bare', fn (): string => 'ok')->middleware('throttle:5,1');
        Route::post('__census/bare-default', fn (): string => 'ok')->middleware('throttle');
        Route::post('__census/bare-guest-user', fn (): string => 'ok')->middleware('throttle:10|60,1');
        Route::post('__census/bare-with', fn (): string => 'ok')->middleware(ThrottleRequests::with(5, 1));
        Route::post('__census/bare-outer', fn (): string => 'ok')->middleware(ThrottleOuterAgentRequests::class.':60,1');
        Route::get('__census/bare-get', fn (): string => 'ok')->middleware('throttle:60,1');
        Route::post('__census/prefixed', fn (): string => 'ok')->middleware('throttle:5,1,census-prefixed');
        Route::post('__census/prefixed-outer', fn (): string => 'ok')->middleware(ThrottleOuterAgentRequests::class.':60,1,census');
        Route::post('__census/named', fn (): string => 'ok')->middleware('throttle:census-named');
        Route::middleware('throttle:census-named')->group(function (): void {
            Route::delete('__census/grouped', fn (): string => 'ok');
        });
        Route::post('__census/missing-limiter', fn (): string => 'ok')->middleware('throttle:census-unregistered');
        Route::post('__census/none', fn (): string => 'ok');
        Route::match(['GET', 'POST'], '__census/match', fn (): string => 'ok');
        Route::get('__census/report/export', fn (): string => 'ok');
        Route::get('__census/invoices/{id}/pdf', fn (): string => 'ok');
        Route::get('__census/plain-read', fn (): string => 'ok');
        Route::redirect('__census/old-address', '/__census/plain-read');
        Route::post('__census/excluded', fn (): string => 'ok')
            ->middleware('throttle:census-named')
            ->withoutMiddleware('throttle:census-named');

        $census = $this->census();

        self::assertSame([
            'POST __census/bare',
            'POST __census/bare-default',
            'POST __census/bare-guest-user',
            'POST __census/bare-with',
            'POST __census/bare-outer',
            'GET|HEAD __census/bare-get',
        ], $this->synthetic($census['unprefixed']));
        self::assertSame(['POST __census/missing-limiter'], $this->synthetic($census['unregistered']));
        self::assertSame([
            'POST __census/none',
            'GET|POST|HEAD __census/match',
            'GET|HEAD __census/report/export',
            'GET|HEAD __census/invoices/{id}/pdf',
            'POST __census/excluded',
        ], $this->synthetic($census['unthrottled']));
    }

    /**
     * @return array{unprefixed: list<string>, unregistered: list<string>, unthrottled: list<string>}
     */
    private function census(): array
    {
        $global = $this->globalMiddleware();
        $router = $this->app->make(Router::class);
        $census = ['unprefixed' => [], 'unregistered' => [], 'unthrottled' => []];

        foreach ($this->routes() as $route) {
            $label = $this->routeLabel($route).($route->getName() !== null ? '  (name: '.$route->getName().')' : '');
            $throttles = $this->throttlesIn([...$global, ...$router->gatherRouteMiddleware($route)]);

            foreach ($throttles as $params) {
                if (count($params) === 1 && ! is_numeric($params[0]) && ! str_contains($params[0], '|')) {
                    if (RateLimiter::limiter($params[0]) === null) {
                        $census['unregistered'][] = $label.'  [throttle:'.$params[0].']';
                    }

                    continue;
                }

                if (trim($params[2] ?? '') === '') {
                    $census['unprefixed'][] = $label.'  [throttle:'.implode(',', $params).']';
                }
            }

            if ($throttles === [] && $this->mustBeThrottled($route)) {
                $census['unthrottled'][] = $label;
            }
        }

        return $census;
    }

    /**
     * @return list<RoutingRoute>
     */
    private function routes(): array
    {
        return array_values(Route::getRoutes()->getRoutes());
    }

    /**
     * @return list<string|Closure>
     */
    private function globalMiddleware(): array
    {
        $kernel = $this->app->make(HttpKernel::class);

        return $kernel instanceof FoundationHttpKernel ? array_values($kernel->getGlobalMiddleware()) : [];
    }

    /**
     * The parameter lists of every throttle in a resolved middleware stack.
     *
     * @param  array<int, mixed>  $middleware
     * @return list<list<string>>
     */
    private function throttlesIn(array $middleware): array
    {
        $throttles = [];
        foreach ($middleware as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            [$class, $parameters] = array_pad(explode(':', $entry, 2), 2, '');
            if ($class !== 'throttle' && ! $this->isThrottleClass($class)) {
                continue;
            }

            $throttles[] = $parameters === '' ? [] : explode(',', $parameters);
        }

        return $throttles;
    }

    private function isThrottleClass(string $class): bool
    {
        foreach (self::THROTTLE_CLASSES as $throttle) {
            if (class_exists($class) && is_a($class, $throttle, true)) {
                return true;
            }
        }

        return false;
    }

    private function mustBeThrottled(RoutingRoute $route): bool
    {
        if (ltrim($route->getControllerClass() ?? '', '\\') === RedirectController::class) {
            return false;
        }

        if (array_diff($route->methods(), self::SAFE_METHODS) !== []) {
            return true;
        }

        return preg_match(self::EXPENSIVE_READ_PATTERN, $route->uri().' '.($route->getName() ?? '')) === 1;
    }

    private function isMcpEndpoint(RoutingRoute $route): bool
    {
        $controller = $route->getControllerClass();

        return ($controller !== null && is_a($controller, AgentMcpController::class, true))
            || preg_match('#(^|/)mcp$#', $route->uri()) === 1;
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
        return array_map(function (string $finding): string {
            return preg_match('/\(name: ([^)]+)\)/', $finding, $match) === 1 ? $match[1] : explode('  ', $finding)[0];
        }, $findings);
    }

    /**
     * @param  list<string>  $findings
     * @param  array<string, string>  $allowlist
     * @return list<string>
     */
    private function withoutAllowlisted(array $findings, array $allowlist): array
    {
        return array_values(array_filter(
            $findings,
            fn (string $finding): bool => ! array_key_exists($this->keysOf([$finding])[0], $allowlist),
        ));
    }

    /**
     * @param  list<string>  $violations
     */
    private function fixMessage(string $problem, array $violations, string $fix): string
    {
        return $problem."\n  - ".implode("\n  - ", $violations)."\n\n".$fix."\n";
    }

    /**
     * The synthetic routes registered by the self-test, without the name or throttle notes.
     *
     * @param  list<string>  $violations
     * @return list<string>
     */
    private function synthetic(array $violations): array
    {
        return array_values(array_map(
            fn (string $violation): string => explode('  ', $violation)[0],
            array_filter($violations, fn (string $violation): bool => str_contains($violation, '__census/')),
        ));
    }
}
