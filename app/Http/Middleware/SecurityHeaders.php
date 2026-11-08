<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening headers on every response: web pages, the portal, the REST and MCP API and
 * the processor webhooks alike. A header a controller already set is never overwritten, so a
 * download that chose its own `X-Content-Type-Options` or frame policy keeps it.
 *
 * Enforced: `nosniff`, `Referrer-Policy`, a minimal `Permissions-Policy`, `X-Frame-Options: DENY`
 * (nothing in SVC is meant to be framed, and no page here frames another origin's page except
 * Stripe's own payment frames) and, on HTTPS only, `Strict-Transport-Security` (no preload,
 * no includeSubDomains: other subdomains of the host are not this application's to commit).
 *
 * Report-only: the Content-Security-Policy. It is never enforced from here - see
 * {@see self::contentSecurityPolicy()} and docs/ux-overhaul.md "Security hardening".
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // Nothing here uses the camera, microphone, location or USB. Payments are left to the
            // browser default so Stripe's frames keep working.
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), usb=(), interest-cohort=()',
            'X-Frame-Options' => 'DENY',
        ];
        foreach ($defaults as $name => $value) {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        $hstsMaxAge = (int) config('svc.security.hsts_max_age', 31536000);
        if ($request->isSecure() && $hstsMaxAge > 0 && ! $headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age='.$hstsMaxAge);
        }

        if (config('svc.security.csp_report_only', true)
            && ! $headers->has('Content-Security-Policy-Report-Only')
            && str_starts_with((string) $headers->get('Content-Type'), 'text/html')) {
            $headers->set('Content-Security-Policy-Report-Only', $this->contentSecurityPolicy());
        }

        return $response;
    }

    /**
     * The allowlist, built from what the code loads today:
     *  - Stripe.js (js.stripe.com) and its payment frames, API and images - resources/js/lib/stripe-js.ts.
     *  - Bunny Fonts, the font provider vite.config.ts names (build output is self-hosted; kept so a
     *    deployment that links the provider directly does not report).
     *  - The identity provider, for the form-post sign-out redirect.
     *  - The Vite dev server (script, style, font, image and websocket) only while `public/hot` exists.
     *  - Square is reached by redirect to its hosted page and by server-side calls only: no Square
     *    script, frame or connection is needed in the browser.
     *
     * `unsafe-inline` stays for scripts (the appearance bootstrap in app.blade.php) and styles
     * (component libraries set style attributes) until a nonce pass makes enforcing possible.
     */
    public function contentSecurityPolicy(): string
    {
        $dev = $this->devServerOrigins();
        $provider = $this->origin((string) config('bherila-auth.oauth_client.base_url'));

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'unsafe-inline'", 'https://js.stripe.com', ...$dev['http']],
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net', ...$dev['http']],
            'img-src' => ["'self'", 'data:', 'blob:', 'https://*.stripe.com', ...$dev['http']],
            'font-src' => ["'self'", 'data:', 'https://fonts.bunny.net', ...$dev['http']],
            'connect-src' => ["'self'", 'https://api.stripe.com', ...$dev['http'], ...$dev['ws']],
            'frame-src' => ['https://js.stripe.com', 'https://hooks.stripe.com'],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => $provider === null ? ["'self'"] : ["'self'", $provider],
        ];

        $policy = implode('; ', array_map(
            static fn (string $name, array $sources): string => $name.' '.implode(' ', $sources),
            array_keys($directives),
            $directives,
        ));

        $report = (string) config('svc.security.csp_report_uri', '');
        if (preg_match('#^(https://[A-Za-z0-9.-]+(:\d+)?)?/(?!/)[A-Za-z0-9._~:/?=&%-]*\z#', $report) === 1) {
            $policy .= '; report-uri '.$report;
        }

        return $policy;
    }

    /** @return array{http: list<string>, ws: list<string>} */
    private function devServerOrigins(): array
    {
        $hot = public_path('hot');
        if (! is_file($hot)) {
            return ['http' => [], 'ws' => []];
        }
        // @infection-ignore-all: a file is_file() confirmed but that cannot be read gives false, which trims to '' and is rejected below like any other non-address.
        $parts = parse_url(trim((string) file_get_contents($hot)));
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ! isset($parts['host'])) {
            return ['http' => [], 'ws' => []];
        }
        $authority = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return [
            'http' => [$parts['scheme'].'://'.$authority],
            'ws' => [($parts['scheme'] === 'https' ? 'wss' : 'ws').'://'.$authority],
        ];
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ! isset($parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
