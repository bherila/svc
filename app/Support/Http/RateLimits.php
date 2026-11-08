<?php

namespace App\Support\Http;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The named limiters for routes a person or a browser reaches without the agent API's own
 * throttle. Every ceiling is per minute and generous - several times what a person doing
 * ordinary work reaches - so the limiter exists to stop loops and probing, never to slow a
 * working screen. Each is tunable through `svc.rate_limits` without a code change.
 *
 * Processor webhooks (Stripe, Square) are deliberately absent: they are signature-verified,
 * the processor retries on failure, and a 429 would drop or delay a genuine delivery.
 */
final class RateLimits
{
    /** Name => default attempts per minute. */
    public const array DEFAULTS = [
        'sign-in' => 60,
        'portal' => 300,
        'portal-writes' => 60,
        'payment-intents' => 30,
        'invitations' => 30,
        'csp-reports' => 120,
    ];

    public static function register(): void
    {
        foreach (array_keys(self::DEFAULTS) as $name) {
            RateLimiter::for($name, static fn (Request $request): Limit => Limit::perMinute(self::attempts($name))
                ->by($name.'|'.self::subject($request, $name)));
        }
    }

    public static function attempts(string $name): int
    {
        $configured = config('svc.rate_limits.'.$name);

        $attempts = (int) $configured;

        return is_numeric($configured) && $attempts > 0 ? $attempts : self::DEFAULTS[$name];
    }

    /** The signed-in person when there is one, otherwise the address. Sign-in is always per address. */
    private static function subject(Request $request, string $name): string
    {
        $user = $name === 'sign-in' ? null : $request->user();

        return $user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.($request->ip() ?? 'unknown');
    }
}
