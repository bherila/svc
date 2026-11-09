<?php

namespace App\Http\Middleware;

use App\Support\Http\CspMode;
use Closure;
use Illuminate\Foundation\Vite as ViteAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
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
 * The Content-Security-Policy follows `svc.security.csp_mode` ({@see CspMode}): off, report-only
 * (the default) or enforce. Every page gets a fresh nonce, drawn before the view renders and
 * handed to Vite, so the inline scripts the views and Vite emit carry it and script-src needs
 * no 'unsafe-inline'. See {@see self::contentSecurityPolicy()} and docs/ux-overhaul.md
 * "Security hardening".
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = CspMode::fromConfig(config('svc.security.csp_mode'));
        // Drawn before the view renders: the blade views and Vite read it back while the page is built.
        // Drawn in every mode so the page renders the same; in `off` it simply goes unused.
        $nonce = Vite::useCspNonce();

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

        $header = $mode->header();
        if ($header !== null
            && ! $headers->has($header)
            && str_starts_with((string) $headers->get('Content-Type'), 'text/html')) {
            $headers->set($header, $this->contentSecurityPolicy($nonce));
            $endpoint = $this->reportEndpoint();
            if ($endpoint !== null && ! $headers->has('Reporting-Endpoints')) {
                $headers->set('Reporting-Endpoints', 'csp-endpoint="'.$endpoint.'"');
            }
        }

        return $response;
    }

    /**
     * The allowlist, built from what the code loads today:
     *  - Stripe.js (js.stripe.com) and its payment frames, API and images - resources/js/lib/stripe-js.ts.
     *  - Fonts are self-hosted (@fontsource, bundled by Vite), so no font provider is allowed.
     *  - The identity provider, for the form-post sign-out redirect.
     *  - The Vite dev server (script, style, font, image and websocket) only while `public/hot` exists.
     *  - Square is reached by redirect to its hosted page and by server-side calls only: no Square
     *    script, frame or connection is needed in the browser.
     *
     *  - Stripe's documented CSP set for Stripe.js and the Payment Element SVC mounts: `js.stripe.com`
     *    and `*.js.stripe.com` for script and frame, `hooks.stripe.com` for 3-D Secure frames,
     *    `api.stripe.com` for connections and `*.stripe.com` images. Google Maps (address
     *    autocomplete) is not listed: SVC mounts no Address Element.
     *
     * Scripts: only 'self', Stripe and this response's nonce. There is no 'unsafe-inline' in
     * script-src, so an injected inline script does not run. Styles keep 'unsafe-inline': Stripe's
     * Payment Element and the component library (Base UI / Radix positioning, Recharts) write
     * `style` attributes and `<style>` rules at runtime, which a nonce cannot cover.
     */
    public function contentSecurityPolicy(string $nonce): string
    {
        $dev = $this->devServerOrigins();
        $provider = $this->origin((string) config('bherila-auth.oauth_client.base_url'));

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", 'https://js.stripe.com', 'https://*.js.stripe.com', ...$dev['http']],
            'style-src' => ["'self'", "'unsafe-inline'", ...$dev['http']],
            'img-src' => ["'self'", 'data:', 'blob:', 'https://*.stripe.com', ...$dev['http']],
            'font-src' => ["'self'", 'data:', ...$dev['http']],
            'connect-src' => ["'self'", 'https://api.stripe.com', ...$dev['http'], ...$dev['ws']],
            'frame-src' => ['https://js.stripe.com', 'https://*.js.stripe.com', 'https://hooks.stripe.com'],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => $provider === null ? ["'self'"] : ["'self'", $provider],
        ];

        $policy = implode('; ', array_map(
            static fn (string $name, array $sources): string => $name.' '.implode(' ', $sources),
            array_keys($directives),
            $directives,
        ));

        $report = $this->reportEndpoint();
        if ($report !== null) {
            $policy .= '; report-uri '.$report.'; report-to csp-endpoint';
        }

        return $policy;
    }

    /** The violation collector: an https URL or an absolute path, or null when unset or malformed. */
    private function reportEndpoint(): ?string
    {
        $report = (string) config('svc.security.csp_report_uri', '');

        return preg_match('#^(https://[A-Za-z0-9.-]+(:\d+)?)?/(?!/)[A-Za-z0-9._~:/?=&%-]*\z#', $report) === 1 ? $report : null;
    }

    /** @return array{http: list<string>, ws: list<string>} */
    private function devServerOrigins(): array
    {
        // Vite's own hot file: `public/hot` unless something pointed Vite elsewhere, as the test suite does per process.
        $hot = app(ViteAssets::class)->hotFile();
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
