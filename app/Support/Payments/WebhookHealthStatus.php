<?php

declare(strict_types=1);

namespace App\Support\Payments;

/**
 * What a check of a Stripe webhook endpoint found. Stored as its value on
 * `workspace_payment_connections.webhook_check_status`.
 */
enum WebhookHealthStatus: string
{
    /** The endpoint is enabled, in the key's mode, and subscribed to every required event. */
    case Healthy = 'healthy';

    /** The endpoint is enabled but lacks some required events. */
    case MissingEvents = 'missing_events';

    /** The endpoint exists but Stripe is not delivering to it. */
    case Disabled = 'disabled';

    /** No endpoint in the account points at SVC's URL for this connection. */
    case NotFound = 'not_found';

    /**
     * The connection records the endpoint SVC created, and Stripe no longer has
     * it. Another endpoint at the same URL is not that one: its signing secret
     * is not the one SVC holds, so it is never judged in its place.
     */
    case EndpointMissing = 'endpoint_missing';

    /**
     * The connection records no endpoint (it came from environment
     * configuration), so SVC cannot confirm which endpoint at its URL the stored
     * signing secret belongs to. A URL match is not proof.
     */
    case EndpointUnverified = 'endpoint_unverified';

    /** The endpoint is in the other mode (test or live) than the key. */
    case ModeMismatch = 'mode_mismatch';

    /** The restricted key may not read webhook endpoints. */
    case PermissionDenied = 'permission_denied';

    /** Stripe did not accept the key at all. */
    case KeyRejected = 'key_rejected';

    /** Stripe could not be reached or gave no usable answer; nothing is known. */
    case Unreachable = 'unreachable';

    /** `ok`, `warning` (something to act on) or `unknown` (the check itself failed). */
    public function severity(): string
    {
        return match ($this) {
            self::Healthy => 'ok',
            self::MissingEvents, self::Disabled, self::NotFound, self::EndpointMissing, self::EndpointUnverified, self::ModeMismatch, self::PermissionDenied, self::KeyRejected => 'warning',
            self::Unreachable => 'unknown',
        };
    }
}
