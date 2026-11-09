<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 for every service-package page while the feature is off (X-1).
 *
 * A 404 rather than a 403: with the flag off the feature does not exist, so a
 * route of it should look no different from any other unknown address.
 */
final class EnsureServicePackagesEnabled
{
    public static function enabled(): bool
    {
        $enabled = config('svc.service_packages.enabled', false);

        // @infection-ignore-all Equivalent: config/svc.php already casts the switch, and without strict_types the bool return type coerces as the cast does; the cast is for static analysis of config()'s mixed.
        return (bool) $enabled;
    }

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(self::enabled(), 404);

        return $next($request);
    }
}
