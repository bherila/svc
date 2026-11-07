<?php

declare(strict_types=1);

namespace App\Support\Payments;

/**
 * Deciding whether a Stripe account's webhook endpoints serve SVC. Pure and
 * database-free: the adapter lists the endpoints and the service hands over the
 * facts; nothing here calls Stripe or reads a table.
 *
 * Which endpoint is judged: of those whose URL is one of SVC's for the
 * connection, the one SVC created for it (the stored endpoint id) because its
 * signing secret is the one SVC holds, otherwise the first enabled one, otherwise
 * the first. Judging one endpoint keeps the answer and the fix about the same
 * thing: an event delivered by an endpoint whose secret SVC does not hold is
 * refused, so a second endpoint's subscriptions prove nothing.
 *
 * @phpstan-type Endpoint array{id: string, url: string, status: string, enabled_events: list<string>, livemode: bool}
 */
final class StripeWebhookHealthDecision
{
    /**
     * @param  list<string>  $required  event types SVC needs
     * @param  array<string, string>  $standIns  required type => another type that satisfies it
     * @param  list<string>  $expectedUrls  the URLs SVC's webhook is served at for this connection
     * @param  string|null  $environment  `live` or `test`, the key's mode, when known
     * @param  string|null  $storedEndpointId  the endpoint SVC created for the connection, if it did
     * @param  list<Endpoint>  $endpoints  every endpoint the account lists
     */
    public static function decide(array $required, array $standIns, array $expectedUrls, ?string $environment, ?string $storedEndpointId, array $endpoints): WebhookHealth
    {
        $expected = array_values(array_filter(array_map(self::normalize(...), $expectedUrls)));
        $matches = array_values(array_filter(
            $endpoints,
            static fn (array $endpoint): bool => in_array(self::normalize($endpoint['url']), $expected, true),
        ));
        if ($matches === []) {
            return new WebhookHealth(WebhookHealthStatus::NotFound);
        }

        $primary = null;
        foreach ($matches as $endpoint) {
            if ($storedEndpointId !== null && $endpoint['id'] === $storedEndpointId) {
                $primary = $endpoint;
                break;
            }
        }
        $primary ??= self::firstEnabled($matches) ?? $matches[0];

        $missing = self::missing($required, $standIns, $primary['enabled_events']);
        $health = static fn (WebhookHealthStatus $status): WebhookHealth => new WebhookHealth($status, $missing, $primary['id'], $primary['enabled_events']);

        if ($primary['status'] !== 'enabled') {
            return $health(WebhookHealthStatus::Disabled);
        }
        if (($environment === 'live' || $environment === 'test') && $primary['livemode'] !== ($environment === 'live')) {
            return $health(WebhookHealthStatus::ModeMismatch);
        }

        return $health($missing === [] ? WebhookHealthStatus::Healthy : WebhookHealthStatus::MissingEvents);
    }

    /**
     * What a failed call to Stripe means for the check: the key lacks the
     * permission (HTTP 403), Stripe does not accept the key (401), or nothing
     * is known (no answer, or an error that says nothing about the key).
     *
     * @param  int|null  $status  Stripe's HTTP status; null when it never answered
     * @param  string|null  $errorType  Stripe's error type, when it gave one
     */
    public static function failure(?int $status, ?string $errorType): WebhookHealthStatus
    {
        return match (true) {
            $status === 403 || $errorType === 'permission_error' => WebhookHealthStatus::PermissionDenied,
            $status === 401 || $errorType === 'authentication_error' => WebhookHealthStatus::KeyRejected,
            default => WebhookHealthStatus::Unreachable,
        };
    }

    /**
     * The required types an endpoint's subscriptions do not cover. `*` covers
     * everything; a stand-in subscription covers the type it stands in for.
     *
     * @param  list<string>  $required
     * @param  array<string, string>  $standIns
     * @param  list<string>  $subscribed
     * @return list<string>
     */
    public static function missing(array $required, array $standIns, array $subscribed): array
    {
        if (in_array('*', $subscribed, true)) {
            return [];
        }

        return array_values(array_filter(
            $required,
            static fn (string $type): bool => ! in_array($type, $subscribed, true)
                && ! (isset($standIns[$type]) && in_array($standIns[$type], $subscribed, true)),
        ));
    }

    /**
     * Scheme, host and path compared the way a delivery would reach them: scheme
     * and host in lower case, a default port dropped, a trailing slash ignored,
     * and no query or fragment. Null for something that is not an absolute http(s) URL.
     */
    public static function normalize(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $port = $parts['port'] ?? null;
        if ($port === ($scheme === 'https' ? 443 : 80)) {
            $port = null;
        }
        $path = rtrim($parts['path'] ?? '', '/');

        return $scheme.'://'.strtolower($parts['host']).($port === null ? '' : ':'.$port).($path === '' ? '/' : $path);
    }

    /**
     * @param  list<Endpoint>  $endpoints
     * @return Endpoint|null
     */
    private static function firstEnabled(array $endpoints): ?array
    {
        foreach ($endpoints as $endpoint) {
            if ($endpoint['status'] === 'enabled') {
                return $endpoint;
            }
        }

        return null;
    }
}
