<?php

declare(strict_types=1);

namespace App\Support\Payments;

/**
 * Deciding whether a Stripe account's webhook endpoints serve SVC. Pure and
 * database-free: the adapter lists the endpoints and the service hands over the
 * facts; nothing here calls Stripe or reads a table.
 *
 * Which endpoint is judged: only the one SVC created for the connection (the
 * stored endpoint id), because its signing secret is the one SVC holds. An event
 * delivered by any other endpoint, even at the same URL, is signed with another
 * secret and refused, so another endpoint's subscriptions prove nothing and it
 * is never adopted:
 *
 * - a stored id that Stripe no longer lists is {@see WebhookHealthStatus::EndpointMissing};
 * - no stored id, with an endpoint at SVC's URL, is {@see WebhookHealthStatus::EndpointUnverified};
 * - no stored id and nothing at the URL is {@see WebhookHealthStatus::NotFound}.
 *
 * @phpstan-type Endpoint array{id: string, url: string, status: string, enabled_events: list<string>, livemode: bool}
 */
final class StripeWebhookHealthDecision
{
    /**
     * @param  list<string>  $required  event types SVC needs
     * @param  list<string>  $expectedUrls  the URLs SVC's webhook is served at for this connection
     * @param  string|null  $environment  `live` or `test`, the key's mode, when known
     * @param  string|null  $storedEndpointId  the endpoint SVC created for the connection, if it did
     * @param  list<Endpoint>  $endpoints  every endpoint the account lists
     */
    public static function decide(array $required, array $expectedUrls, ?string $environment, ?string $storedEndpointId, array $endpoints): WebhookHealth
    {
        $expected = array_filter(array_map(self::normalize(...), $expectedUrls));
        $atOurUrl = static fn (array $endpoint): bool => in_array(self::normalize($endpoint['url']), $expected, true);

        if ($storedEndpointId === null) {
            return new WebhookHealth(array_any($endpoints, $atOurUrl) ? WebhookHealthStatus::EndpointUnverified : WebhookHealthStatus::NotFound);
        }
        $primary = array_find($endpoints, static fn (array $endpoint): bool => $endpoint['id'] === $storedEndpointId);
        if ($primary === null) {
            return new WebhookHealth(WebhookHealthStatus::EndpointMissing);
        }
        if (! $atOurUrl($primary)) {
            return new WebhookHealth(WebhookHealthStatus::NotFound);
        }

        $missing = self::missing($required, $primary['enabled_events']);
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
     * everything. Every required type is required on its own: a subscription to
     * a similarly named event does not stand in for it.
     *
     * @param  list<string>  $required
     * @param  list<string>  $subscribed
     * @return list<string>
     */
    public static function missing(array $required, array $subscribed): array
    {
        if (in_array('*', $subscribed, true)) {
            return [];
        }

        return array_values(array_filter(
            $required,
            static fn (string $type): bool => ! in_array($type, $subscribed, true),
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
}
